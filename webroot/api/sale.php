<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/LowStockNotifier.php';
require_once __DIR__ . '/../../src/InvoiceService.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
$cart  = $input['items'] ?? [];
$buyer = $input['buyer'] ?? null; // when null, "Vevő számlát kér" wasn't checked — no Szamlazz.hu call happens at all in that case
$paymentMethod = $input['payment_method'] ?? 'Készpénz';
$customerId = !empty($input['customer_id']) ? (int) $input['customer_id'] : null;
$redeemPoints = max(0, (int) ($input['redeem_points'] ?? 0));
$locationId = !empty($input['location_id']) ? (int) $input['location_id'] : null;
// Kliens által generált, a kosár egy adott "leadási kísérletéhez" tartozó
// kulcs — dupla kattintás, hálózati újrapróbálkozás, vagy egy elveszett
// válasz utáni manuális újraküldés esetén ez zárja ki, hogy ugyanaz a
// logikai eladás kétszer kerüljön rögzítésre (duplán csökkentett
// készlet, duplán jóváírt hűségpont, duplán kiállított számla stb.).
// A tényleges atomikus védelmet a sales.idempotency_key UNIQUE indexe
// adja (lásd Database::insertSale()), nem ez az előzetes ellenőrzés
// önmagában — ez csak a GYORS útvonal egy már ismert kulcshoz.
$idempotencyKey = trim((string) ($input['idempotency_key'] ?? ''));
// Az idempotencia-kulcs önmagában csak azt zárja ki, hogy UGYANAZ a kulcs
// kétszer hozzon létre eladást — azt nem, hogy valaki (hibás kliens, vagy
// egy közvetlen API-hívást indító szkript) ugyanazt a kulcsot egy MÁSIK,
// ténylegesen eltérő kéréssel küldje be. Az ujjlenyomat ezt a második
// esetet zárja ki — lásd build_sale_fingerprint() és
// Database::migrateV18SaleIdempotencyFingerprint() docblockja.
$idempotencyFingerprint = $idempotencyKey !== '' ? build_sale_fingerprint($input) : null;

// Az egységes 'invoices' tábla óta (lásd Database::migrateV19Invoices()) a
// visszajátszott válasz számla-részét is a kiválasztott szolgáltató alapján
// kell rekonstruálni — lásd build_idempotent_replay_response() docblockja.
$invoiceProviderKey = (string) ($appSettings['invoice_provider'] ?? 'szamlazz') === 'nav' ? 'nav' : 'szamlazz';

if ($idempotencyKey !== '') {
    $existingSale = $db->findSaleByIdempotencyKey($idempotencyKey);
    if ($existingSale) {
        match_or_reject_idempotent_replay($db, $existingSale, $idempotencyFingerprint, $invoiceProviderKey); // sose tér vissza — vagy 200 visszajátszás, vagy 409
    }
}

if (empty($cart)) {
    send_json(['error' => 'Cart is empty'], 400);
}

if ($buyer !== null) {
    foreach (['nev', 'irsz', 'telepules', 'cim'] as $required) {
        if (empty($buyer[$required])) {
            send_json(['error' => "Missing buyer field: $required"], 400);
        }
    }
}

$customer = null;
if ($customerId && !empty($appSettings['loyalty_enabled'])) {
    $customer = $db->findCustomerById($customerId);
    if (!$customer) {
        send_json(['error' => 'A kiválasztott vásárló nem található.'], 404);
    }
    if ($redeemPoints > (int) $customer['loyalty_points']) {
        send_json(['error' => 'A vásárlónak nincs elég pontja (' . $customer['loyalty_points'] . ' pont van).'], 400);
    }
}

$couponCode = trim((string) ($input['coupon_code'] ?? ''));
$giftCardCode = trim((string) ($input['gift_card_code'] ?? ''));

// 1. Tételsorok összeállítása — a készlet itt NINCS letiltva: a bolt
//    szeretné tudni túladni a készleten (a készlet negatívba megy,
//    a következő beszerzésnél korrigálódik). A kasszafelület már ez
//    előtt figyelmezteti az eladót; itt csak megjelöljük, mely sorok
//    mennek negatívba, hogy a válasz jelezni tudja.
// Egy kosártétel lehet kézzel beírt is (nincs product_id) — olyan
// szolgáltatáshoz vagy egyedi tételhez, amit a vevő rá akar tetetni a
// nyugtára/számlára, de egyáltalán nincs nyilvántartva a készletben. Ez
// teljesen kihagyja a készlet/WooCommerce/alacsony-készlet kezelést, de
// az eladáson elmentődik és a számlán is szerepel, mint bármelyik más sor.
$lineItems = [];
$total = 0.0;
$oversoldItems = [];

foreach ($cart as $line) {
    if (!empty($line['manual'])) {
        $name = trim((string) ($line['name'] ?? ''));
        $qty = (int) ($line['qty'] ?? 0);
        $unitPrice = (float) ($line['unit_price'] ?? 0);
        $vatRate = (string) ($line['vat_rate'] ?? '27');

        if ($name === '') {
            send_json(['error' => 'A kézi tétel neve kötelező.'], 400);
        }
        if ($qty <= 0) {
            send_json(['error' => "Érvénytelen mennyiség: $name"], 400);
        }
        if ($unitPrice < 0) {
            send_json(['error' => "Érvénytelen egységár: $name"], 400);
        }

        $lineItems[] = [
            'product_id'   => null,
            'wc_product_id' => null,
            'name'         => $name,
            'barcode'      => null,
            'qty'          => $qty,
            'unit_price'   => $unitPrice,
            'vat_rate'     => $vatRate,
            'stock_before' => null,
            'low_stock_threshold' => null,
            'manual'       => true,
        ];
        $total += $qty * $unitPrice;
        continue;
    }

    $product = $db->findProductById((int) $line['product_id']);
    $qty = (int) $line['qty'];

    if (!$product) {
        send_json(['error' => "Product {$line['product_id']} not found"], 404);
    }
    if ($qty <= 0) {
        send_json(['error' => "Invalid quantity for {$product['name']}"], 400);
    }

    $stockAfter = (int) $product['stock_qty'] - $qty;
    if ($stockAfter < 0) {
        $oversoldItems[] = ['name' => $product['name'], 'new_stock' => $stockAfter];
    }

    $lineItems[] = [
        'product_id' => $product['id'],
        'wc_product_id' => !empty($product['sync_to_woocommerce']) ? $product['wc_product_id'] : null,
        'name'       => $product['name'],
        'barcode'    => $product['barcode'],
        'qty'        => $qty,
        'unit_price' => (float) $product['price'],
        'vat_rate'   => $product['vat_rate'],
        'stock_before' => (int) $product['stock_qty'],
        'low_stock_threshold' => $product['low_stock_threshold'],
    ];
    $total += $qty * (float) $product['price'];
}

$subtotal = $total;

$coupon = null;
$couponDiscount = 0.0;
if ($couponCode !== '') {
    $couponResult = $db->validateCoupon($couponCode, $subtotal);
    if (!$couponResult['ok']) {
        send_json(['error' => $couponResult['error']], 400);
    }
    $coupon = $couponResult['coupon'];
    $couponDiscount = $couponResult['discount'];
    $total = max(0, $total - $couponDiscount);
}

$pointValue = (float) ($appSettings['loyalty_point_value_huf'] ?? 0);
$redeemDiscount = $customer ? round($redeemPoints * $pointValue, 2) : 0.0;
$total = max(0, $total - $redeemDiscount);

// Hűségszint (loyalty tier) kedvezmény — az élettartam-elköltés alapján,
// a pontbeváltástól függetlenül. A kupon és a pontok után, az utalvány
// előtt kerül alkalmazásra.
$tierName = null;
$tierDiscount = 0.0;
if ($customer && !empty($appSettings['loyalty_enabled'])) {
    $goldThreshold = (float) ($appSettings['loyalty_tier_gold_threshold'] ?? 150000);
    $silverThreshold = (float) ($appSettings['loyalty_tier_silver_threshold'] ?? 50000);
    $totalSpent = (float) $customer['total_spent'];

    if ($totalSpent >= $goldThreshold) {
        $tierName = 'gold';
        $tierPct = (float) ($appSettings['loyalty_tier_gold_discount'] ?? 10);
    } elseif ($totalSpent >= $silverThreshold) {
        $tierName = 'silver';
        $tierPct = (float) ($appSettings['loyalty_tier_silver_discount'] ?? 5);
    } else {
        $tierName = 'bronze';
        $tierPct = 0.0;
    }

    if ($tierPct > 0) {
        $tierDiscount = round($total * ($tierPct / 100), 2);
        $total = max(0, $total - $tierDiscount);
    }
}

$pointsEarned = 0;
$loyaltyBasisTotal = $total; // a pontszámítás ÉS a total_spent ugyanerre az alapra épül — lásd lentebb
if ($customer && !empty($appSettings['loyalty_enabled'])) {
    $hufPerPoint = max(1, (int) ($appSettings['loyalty_huf_per_point'] ?? 100));
    $pointsEarned = (int) floor($total / $hufPerPoint);
}

$giftCard = null;
$giftCardRedeemed = 0.0;
if ($giftCardCode !== '') {
    $giftCardResult = $db->validateGiftCard($giftCardCode, $total);
    if (!$giftCardResult['ok']) {
        send_json(['error' => $giftCardResult['error']], 400);
    }
    $giftCard = $giftCardResult['gift_card'];
    $giftCardRedeemed = $giftCardResult['redeemable'];
    // $total csökken innentől a kártyával fedezett résszel, de a
    // $loyaltyBasisTotal (pontszámítás ÉS total_spent alapja) szándékosan
    // NEM — egy ajándékkártyával fizetett rész is "elköltésnek" számít a
    // hűségszint/pontok szempontjából, különben a kártyát használó vásárló
    // pontjai és hűségszintje egymáshoz képest inkonzisztensen alakulna.
    $total = max(0, round($total - $giftCardRedeemed, 2));
}

$giftCardNewBalance = null;
$newPointsBalance = null;
$claimError = null;

$db->beginTransaction();
try {
    // Atomikusan lefoglaljuk a szűkös erőforrásokat (kupon-felhasználás,
    // hűségpont-egyenleg, ajándékutalvány-egyenleg) MIELŐTT maga az eladás
    // rögzülne, ugyanabban a tranzakcióban — különben két majdnem egyidejű
    // eladás mindkettő sikeresen elkölthetné ugyanazt a már csak egyszer
    // meglévő kedvezményt: az eladás a kedvezménnyel együtt rögzülne, és
    // csak UTÁNA (ha egyáltalán) derülne ki, hogy a fedezet már elfogyott —
    // ekkor viszont már késő, az eladás nem vonható vissza csendben.
    if ($coupon && !$db->incrementCouponUsage((int) $coupon['id'])) {
        $claimError = 'Ezt a kupont időközben valaki más felhasználta. Kérlek, próbáld újra.';
    } elseif ($customer && $redeemPoints > 0 && !$db->tryClaimLoyaltyPoints($customerId, $redeemPoints)) {
        $claimError = 'A vásárlónak időközben már nincs elég pontja. Kérlek, próbáld újra.';
    } elseif ($giftCard && $giftCardRedeemed > 0 && !$db->tryClaimGiftCardBalance((int) $giftCard['id'], $giftCardRedeemed)) {
        $claimError = 'Az ajándékutalvány egyenlege időközben megváltozott. Kérlek, próbáld újra.';
    }

    if ($claimError !== null) {
        $db->rollBack();
    } else {
        $saleId = $db->insertSale(
            round($total, 2),
            $paymentMethod,
            $buyer['nev'] ?? null,
            $customer ? $customerId : null,
            $pointsEarned,
            $customer ? $redeemPoints : 0,
            $coupon['id'] ?? null,
            $couponDiscount,
            $giftCardRedeemed,
            Auth::currentStaffId(),
            $idempotencyKey,
            $idempotencyFingerprint
        );
        foreach ($lineItems as $item) {
            $db->insertSaleItem($saleId, $item);
            if (empty($item['manual'])) {
                $db->decrementStock($item['product_id'], $item['qty']);
                if ($locationId) {
                    $db->decrementLocationStock($item['product_id'], $locationId, $item['qty']);
                }
            }
        }

        if ($customer && $redeemPoints > 0) {
            $db->recordLoyaltyPointsRedemption($customerId, $redeemPoints, $saleId);
        }
        if ($giftCard && $giftCardRedeemed > 0) {
            $giftCardNewBalance = $db->recordGiftCardRedemption((int) $giftCard['id'], $giftCardRedeemed, $saleId);
        }

        // A hűségpont-JÓVÁÍRÁS és az élettartam-elköltés frissítése
        // SZÁNDÉKOSAN ugyanabban a tranzakcióban történik, mint maga az
        // eladás rögzítése/készletcsökkentés/beváltás — korábban ez a két
        // hívás a tranzakción KÍVÜL, külön auto-commit lépésként futott,
        // ami azt jelentette, hogy egy a commit() UTÁN, de e két hívás
        // ELŐTT bekövetkező folyamat-összeomlás (fatal error, memória-
        // kifogyás, kényszerített leállás) az eladást sikeresként
        // rögzítve hagyta, miközben a vásárló ténylegesen sose kapta meg
        // a jóváírt pontjait/elköltés-növekményét — és mivel az
        // idempotencia-visszajátszás a MÁR PERZISZTÁLT sales-sorból épül
        // fel, egy újrapróbálkozás sem pótolta volna ezt utólag. Az
        // applyLoyaltyPoints() (lásd ott) már önmagában is atomikus
        // relatív UPDATE, tehát a tranzakción belüli, egyéb sorokkal
        // (stock, kupon, ajándékutalvány) egy blokkban való véglegesítés
        // itt NEM versenyhelyzet-védelmi, hanem TARTÓSSÁGI (durability)
        // célt szolgál: vagy MINDEN hatás rögzül, vagy egy sem.
        if ($customer) {
            if ($pointsEarned > 0) {
                $newPointsBalance = $db->applyLoyaltyPoints($customerId, $pointsEarned, $saleId, "Jóváírva eladás #$saleId-nél");
            } else {
                // Friss olvasás kell (nem a tranzakció ELŐTTI $customer
                // pillanatkép) — ha ebben az eladásban pontbeváltás is
                // történt (tryClaimLoyaltyPoints fentebb, ugyanebben a
                // tranzakcióban), a $customer változó még a beváltás
                // ELŐTTI, elavult egyenleget tartalmazná.
                $newPointsBalance = (int) ($db->findCustomerById($customerId)['loyalty_points'] ?? 0);
            }
            $db->addCustomerSpend($customerId, round($loyaltyBasisTotal, 2));
        }

        $db->commit();
    }
} catch (Throwable $e) {
    $db->rollBack();
    // Ha ez éppen az idempotencia-kulcs UNIQUE-ütközése, az azt jelenti,
    // hogy egy VERSENYHELYZETBEN futó másik kérés (nem egy korábbi,
    // időben eltolt újrapróbálkozás, hanem egy szinte pontosan
    // egyidejű másik kérés ugyanazzal a kulccsal) már megnyerte a
    // beszúrást — ezt itt, ATOMIKUSAN garantálja maga az adatbázis
    // (UNIQUE INDEX), nem egy "ellenőrizd, majd írd be" mintázat. A fenti,
    // kérés eleji findSaleByIdempotencyKey() ezt csak a GYAKORI, nem
    // versenyhelyzetes esetben (időben eltolt újrapróbálkozás) kapja el —
    // itt a valóban egyidejű esetet. A győztes eredményét adjuk vissza,
    // nem hibát.
    if ($idempotencyKey !== '' && str_contains($e->getMessage(), 'idempotency_key')) {
        $winner = $db->findSaleByIdempotencyKey($idempotencyKey);
        if ($winner) {
            match_or_reject_idempotent_replay($db, $winner, $idempotencyFingerprint, $invoiceProviderKey); // sose tér vissza
        }
    }
    send_json(['error' => 'Az eladás rögzítése sikertelen: ' . $e->getMessage()], 500);
}

if ($claimError !== null) {
    send_json(['error' => $claimError], 409);
}

$invoiceResult = null;

if ($buyer !== null) {
    // A lineItems[]['unit_price'] a kedvezmény ELŐTTI (kosár-összeállításkori)
    // egységárat tartalmazza — enélkül az arányosítás nélkül a számla
    // felé mindig a teljes, kedvezmény nélküli összeg menne ki, akkor is, ha
    // kupon/hűségpont/hűségszint/ajándékutalvány miatt a vevő ténylegesen
    // kevesebbet fizetett (lásd sales.total). Ugyanaz az arányosítási minta,
    // mint getDailySummary()-ban és api/return-create.php-ban.
    $invoiceDiscountRatio = $subtotal > 0 ? min(1, $total / $subtotal) : 1.0;

    $invoiceItems = array_map(fn($i) => [
        'name'             => $i['name'],
        'qty'              => $i['qty'],
        'unit_price_gross' => round($i['unit_price'] * $invoiceDiscountRatio, 2),
        'vat_rate'         => $i['vat_rate'],
    ], $lineItems);

    $invoiceNetTotal = 0.0;
    foreach ($invoiceItems as $ii) {
        $vatPct = is_numeric($ii['vat_rate']) ? ((float) $ii['vat_rate']) / 100 : 0.0;
        $lineGross = $ii['unit_price_gross'] * $ii['qty'];
        $invoiceNetTotal += is_numeric($ii['vat_rate']) ? round($lineGross / (1 + $vatPct), 2) : $lineGross;
    }
    $invoiceGrossTotal = round($total, 2);
    $invoiceVatTotal = round($invoiceGrossTotal - $invoiceNetTotal, 2);

    $languageOverride = $input['invoice_language'] ?? null;

    $invoiceService = new InvoiceService($config, $appSettings);
    $invoiceResult = $invoiceService->processInvoice([
        'db'             => $db,
        'sale_id'        => $saleId,
        'buyer'          => $buyer,
        'items'          => $invoiceItems,
        'language'       => $languageOverride,
        'payment_method' => $paymentMethod,
        'totals'         => [
            'net' => $invoiceNetTotal, 'vat' => $invoiceVatTotal, 'gross' => $invoiceGrossTotal,
            'currency' => $config['szamlazz']['currency'] ?? 'HUF',
        ],
    ]);
}

$pushErrors = [];
try {
    $wc = new WooCommerceClient($config['woocommerce']);
    foreach ($lineItems as $item) {
        if (empty($item['wc_product_id'])) {
            continue;
        }
        // A tényleges, a tranzakció commit-ja UTÁN érvényes készletet
        // olvassuk újra az adatbázisból (nem a kérés elején rögzített
        // stock_before-ból számolunk) — különben két majdnem egyidejű
        // eladás egymást írhatná felül egy elavult, abszolút értékkel.
        // A helyi DB-t itt nem is kell újra frissíteni (setStock), mert
        // a tranzakció már a helyes relatív decrementStock()-ot alkalmazta.
        $current = $db->findProductById($item['product_id']);
        if (!$current) {
            continue;
        }
        try {
            $wc->updateStock((int) $item['wc_product_id'], (int) $current['stock_qty']);
            $db->touchWcSyncedAt($item['product_id']);
            $db->logSync('push', $item['product_id'], 'Stock pushed after sale #' . $saleId);
        } catch (Throwable $e) {
            $pushErrors[] = $item['name'] . ': ' . $e->getMessage();
            $db->logSync('push', $item['product_id'], 'FAILED: ' . $e->getMessage());
        }
    }
} catch (Throwable $e) {
    $pushErrors[] = $e->getMessage();
}

$lowStockCrossed = [];
foreach ($lineItems as $item) {
    if (!empty($item['manual'])) {
        continue;
    }
    $threshold = $item['low_stock_threshold'] !== null && $item['low_stock_threshold'] !== ''
        ? (int) $item['low_stock_threshold']
        : (int) $appSettings['low_stock_default_threshold'];
    $stockAfter = $item['stock_before'] - $item['qty'];
    if ($stockAfter <= $threshold && $item['stock_before'] > $threshold) {
        $lowStockCrossed[] = [
            'id' => $item['product_id'], 'name' => $item['name'], 'barcode' => $item['barcode'],
            'stock_qty' => $stockAfter, 'threshold' => $threshold,
        ];
    }
}
if ($lowStockCrossed) {
    try {
        LowStockNotifier::notify($appSettings, $lowStockCrossed);
    } catch (Throwable $e) {
    }
}

send_json([
    'sale_id'        => $saleId,
    'receipt_token'  => $db->getSaleReceiptToken($saleId),
    'total'          => round($total, 2),
    'subtotal'       => round($subtotal, 2),
    'invoice'        => $invoiceResult,
    'wc_push_errors' => $pushErrors,
    'oversold_items' => $oversoldItems,
    'loyalty'        => $customer ? [
        'customer_id'      => $customerId,
        'points_earned'    => $pointsEarned,
        'points_redeemed'  => $redeemPoints,
        'new_balance'      => $newPointsBalance,
        'redeem_discount'  => $redeemDiscount,
        'tier'             => $tierName,
        'tier_discount'    => $tierDiscount,
    ] : null,
    'coupon' => $coupon ? [
        'code'     => $coupon['code'],
        'discount' => $couponDiscount,
    ] : null,
    'gift_card' => $giftCard ? [
        'code'        => $giftCard['code'],
        'redeemed'    => $giftCardRedeemed,
        'new_balance' => $giftCardNewBalance,
    ] : null,
]);

/**
 * Egy korábban (ugyanezzel az idempotencia-kulccsal) már sikeresen
 * feldolgozott eladáshoz tartozó válasz újraépítése, KIZÁRÓLAG a
 * sales sor saját, már perzisztált mezőiből (plusz, ha van, az egységes
 * `invoices` tükör-sorból — lásd Database::migrateV19Invoices()) — nincs
 * külön "válasz-pillanatkép" oszlop/tábla, mert minden szükséges adat
 * már úgyis ott van. Ha az eredeti kérés még a számla-kiállítás/
 * WooCommerce-push "farok" feldolgozásánál tart (a sale már commit-olva
 * van, de invoice/wc_push_errors még nem), ez akkor is egy KORREKT,
 * csak kevésbé részletes választ ad — a legfontosabb garancia (az
 * eladás rögzítve van, itt a sale_id/receipt_token) mindig igaz.
 *
 * A számla-rész forrása szolgáltató-függő: a 'szamlazz' provider a
 * SzamlazzInvoiceProvider által is írt `invoices`-tükröt ÉS a régi
 * sales.szamlazz_invoice_number/status oszlopokat is használhatná, de
 * mivel MINDKETTŐT ugyanaz az attachInvoiceToSale()/upsertInvoiceMirror()
 * pár írja egyszerre, a régi sales-oszlopok maradnak az elsődleges,
 * változatlan forrás (visszafelé kompatibilis a V19 előtti sale-ekkel
 * is, amikhez sose lesz `invoices` sor). Egy jövőbeli 'nav' provider
 * viszont KIZÁRÓLAG az `invoices` táblát írja (a sales.szamlazz_*
 * oszlopokhoz sosem nyúl) — ott ez az egyetlen forrás.
 */
function build_idempotent_replay_response(Database $db, array $sale, string $invoiceProviderKey): array
{
    if ($invoiceProviderKey === 'nav') {
        $mirror = $db->findInvoiceBySaleAndProvider((int) $sale['id'], 'nav');
        $invoice = $mirror
            ? ($mirror['status'] === 'done'
                ? ['success' => true, 'invoice_number' => $mirror['invoice_number'], 'pdf_path' => $mirror['pdf_path'], 'error' => null]
                : ($mirror['status'] === 'failed' || $mirror['status'] === 'dead_letter'
                    ? ['success' => false, 'invoice_number' => null, 'pdf_path' => null, 'error' => $mirror['last_error'] ?? 'A NAV számla kiállítása korábban sikertelen volt.']
                    : null)) // queued/processing/submitted — még folyamatban, lásd sale.php processInvoice()
            : null;
    } else {
        $invoice = !empty($sale['szamlazz_invoice_number'])
            ? ['success' => true, 'invoice_number' => $sale['szamlazz_invoice_number'], 'pdf_path' => $sale['szamlazz_pdf_path'] ?? null, 'error' => null]
            : (($sale['status'] ?? '') === 'invoice_failed'
                ? ['success' => false, 'invoice_number' => null, 'pdf_path' => null, 'error' => 'A számla kiállítása korábban sikertelen volt — nézd meg az eladást a listában, és próbáld újra onnan.']
                : null);
    }

    return [
        'sale_id'        => (int) $sale['id'],
        'receipt_token'  => $sale['receipt_token'],
        'total'          => round((float) $sale['total'], 2),
        'subtotal'       => null,
        'replayed'       => true,
        'invoice'        => $invoice,
        'wc_push_errors' => [],
        'oversold_items' => [],
        'loyalty' => !empty($sale['customer_id']) ? [
            'customer_id'     => (int) $sale['customer_id'],
            'points_earned'   => (int) ($sale['loyalty_points_earned'] ?? 0),
            'points_redeemed' => (int) ($sale['loyalty_points_redeemed'] ?? 0),
            'new_balance'     => null,
            'redeem_discount' => null,
            'tier'            => null,
            'tier_discount'   => null,
        ] : null,
        'coupon' => !empty($sale['coupon_id']) ? [
            'code'     => null,
            'discount' => (float) ($sale['coupon_discount'] ?? 0),
        ] : null,
        'gift_card' => (float) ($sale['gift_card_redeemed'] ?? 0) > 0 ? [
            'code'        => null,
            'redeemed'    => (float) $sale['gift_card_redeemed'],
            'new_balance' => null,
        ] : null,
    ];
}

/**
 * Determinisztikus "ujjlenyomat" a kérés üzletileg releváns mezőiről —
 * NEM a nyers JSON-ból (a mezők sorrendje/esetleges extra mezők a
 * kliensben módosulhatnak anélkül, hogy a KÉRÉS LOGIKAI TARTALMA
 * változna), hanem egy kanonikus, rendezett tömbből képzett hash. Csak
 * azok a mezők szerepelnek benne, amik ténylegesen befolyásolják, mi
 * történik a szerveren (tételek/mennyiségek, kézi tételek ára/ÁFÁ-ja,
 * vevő/számlázási adatok, fizetési mód, hűségpont-beváltás, kupon,
 * ajándékutalvány, telephely) — a katalógustermékek ára/ÁFÁ-ja
 * SZÁNDÉKOSAN nem szerepel itt: azt a szerver a product_id alapján, a
 * saját adatbázisából olvassa ki, sose a kliens állításából, tehát egy
 * eltérő kliens-oldali (figyelmen kívül hagyott) árérték nem tesz két
 * egyébként azonos kérést "eltérő logikai kéréssé".
 */
function build_sale_fingerprint(array $input): string
{
    $cart = is_array($input['items'] ?? null) ? array_values($input['items']) : [];
    $normalizedItems = array_map(static function ($line) {
        $line = is_array($line) ? $line : [];
        $isManual = !empty($line['manual']);
        return [
            'product_id' => isset($line['product_id']) ? (int) $line['product_id'] : null,
            'qty'        => isset($line['qty']) ? (int) $line['qty'] : null,
            'manual'     => $isManual,
            // Katalógustermékeknél a NÉV/ÁR/ÁFA a szerver saját adatbázisából
            // származik (product_id alapján) — csak a kézi tételeknél
            // kliens-meghatározott ezek, ott viszont ténylegesen számítanak.
            'name'       => $isManual ? (string) ($line['name'] ?? '') : null,
            'unit_price' => $isManual && isset($line['unit_price']) ? round((float) $line['unit_price'], 2) : null,
            'vat_rate'   => $isManual ? (string) ($line['vat_rate'] ?? '') : null,
        ];
    }, $cart);
    // Stabil rendezés, hogy két, ténylegesen azonos kosár eltérő sorrenddel
    // (pl. egy kliens-oldali újrarendezés) ne számítson eltérő kérésnek.
    usort($normalizedItems, static fn(array $a, array $b): int =>
        [(string) $a['product_id'], $a['name'], $a['qty']] <=> [(string) $b['product_id'], $b['name'], $b['qty']]);

    $buyer = is_array($input['buyer'] ?? null) ? $input['buyer'] : null;
    if ($buyer !== null) {
        $buyer = array_map('strval', $buyer);
        ksort($buyer);
    }

    $fingerprintData = [
        'items'          => $normalizedItems,
        'buyer'          => $buyer,
        'payment_method' => (string) ($input['payment_method'] ?? 'Készpénz'),
        'customer_id'    => !empty($input['customer_id']) ? (int) $input['customer_id'] : null,
        'redeem_points'  => max(0, (int) ($input['redeem_points'] ?? 0)),
        'coupon_code'    => trim((string) ($input['coupon_code'] ?? '')),
        'gift_card_code' => trim((string) ($input['gift_card_code'] ?? '')),
        'location_id'    => !empty($input['location_id']) ? (int) $input['location_id'] : null,
    ];

    return hash('sha256', json_encode($fingerprintData, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

/**
 * Egy meglévő (ugyanazzal az idempotencia-kulccsal már rögzített) eladás
 * visszajátszása — DE csak akkor, ha a mostani kérés ujjlenyomata egyezik
 * az eredetivel. Ha nem egyezik, a kulcsot valaki egy MÁSIK, ténylegesen
 * eltérő kéréshez próbálja újrafelhasználni — ezt 409 Conflict-tal
 * utasítjuk el, a második kérés fel sem dolgozódik, az eredeti eladás
 * változatlan marad.
 *
 * A bevezetés ELŐTT (V17 alatt) létrejött sale-eknél az
 * idempotency_fingerprint NULL — ezeknél nincs mivel összehasonlítani,
 * ezért továbbra is visszajátszhatónak tekintjük őket (nem utasítjuk el
 * utólag egy sose létezett ujjlenyomat hiánya miatt).
 *
 * SOSE tér vissza — vagy egy 200-as visszajátszást, vagy egy 409-es
 * hibát küld (send_json() mindkét esetben exit-tel zár).
 */
function match_or_reject_idempotent_replay(Database $db, array $existingSale, ?string $requestFingerprint, string $invoiceProviderKey): void
{
    $storedFingerprint = $existingSale['idempotency_fingerprint'] ?? null;
    if ($storedFingerprint !== null && $storedFingerprint !== '' && $storedFingerprint !== $requestFingerprint) {
        send_json([
            'error' => 'Ugyanaz az idempotencia-kulcs egy korábbitól eltérő tartalmú kéréssel érkezett — ez a kérés nem dolgozható fel. Töltsd újra az oldalt, és próbáld újra a vásárlást.',
        ], 409);
    }
    send_json(build_idempotent_replay_response($db, $existingSale, $invoiceProviderKey));
}
