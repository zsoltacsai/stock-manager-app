<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

$input = json_input();
$saleId = (int) ($input['sale_id'] ?? 0);
$requestedItems = $input['items'] ?? [];
$reason = trim((string) ($input['reason'] ?? ''));
// A szerver-oldali, PIN-nel ellenőrzött session-ből, nem a kliens által
// beküldött staff_id-ból — különben bárki más dolgozó nevére írhatná a
// visszárut, torzítva az elszámoltathatóságot.
$staffId = Auth::currentStaffId();
// A visszatérítés a JELENLEGI (a visszatérítés PILLANATÁBAN nyitott)
// műszakhoz kötődik, NEM az eredeti eladáséhoz — a pénz fizikailag MOST
// hagyja el az aktuális kasszát. Opcionális, visszafelé kompatibilis, lásd
// sale.php ugyanezen mintáját.
//
// Release-blocker javítás: KORÁBBAN itt egy külön getOpenCashSession()-
// lekérdezés kapott session-azonosítót, amit a lenti processReturn()
// hívásnak adtunk át — ez a lekérdezés a TÉNYLEGES DB-írás pillanatára már
// elavulhatott (időközben valaki lezárhatta a műszakot), pontosan ugyanaz a
// race, amit a sale.php-nál a Checkpoint 4-ben már kijavítottunk. A nyers
// $cashRegisterId-t adjuk tovább — a tényleges session-választás a
// Database::processReturn() saját, atomikus al-lekérdezésének a dolga, a
// beszúrás pillanatában, nem egy itteni, külön (és emiatt potenciálisan
// elavuló) lekérdezés authority-jaként.
$cashRegisterId = !empty($input['cash_register_id']) ? (int) $input['cash_register_id'] : null;
// N-3: kliens által generált, a visszáru egy "leadási kísérletéhez" tartozó
// kulcs — ugyanaz a minta, mint sale.php-ban: egy dupla beküldés vagy egy
// elveszett válasz utáni újraküldés SOSE hajt végre második visszárut
// (dupla visszatérítés, dupla készlet-visszaírás). A tényleges atomikus
// védelmet a returns.idempotency_key UNIQUE indexe adja, a visszáru
// tranzakciójában (Database::processReturn()). Ez a mennyiségi védelemtől
// (F-03, "legfeljebb ennyi vihető még vissza") FÜGGETLEN réteg: az
// ugyanazon kérés ismétlését zárja ki, nem a túl nagy összmennyiséget.
$idempotencyKey = trim((string) ($input['idempotency_key'] ?? ''));
if (strlen($idempotencyKey) > 64) {
    send_json(['error' => 'Az idempotency_key túl hosszú.'], 400);
}

if (!$saleId || empty($requestedItems)) {
    send_json(['error' => 'Válassz ki legalább egy visszaveendő tételt.'], 400);
}

$sale = $db->getSaleWithItems($saleId);
if (!$sale) {
    send_json(['error' => 'Az eladás nem található.'], 404);
}

$idempotencyFingerprint = null;
if ($idempotencyKey !== '') {
    $normalizedForFingerprint = [];
    foreach (is_array($requestedItems) ? $requestedItems : [] as $req) {
        if (is_array($req) && (int) ($req['qty'] ?? 0) > 0) {
            $normalizedForFingerprint[] = (int) ($req['sale_item_id'] ?? 0) . ':' . (int) $req['qty'];
        }
    }
    sort($normalizedForFingerprint);
    $idempotencyFingerprint = hash('sha256', $saleId . '|' . implode(',', $normalizedForFingerprint));
}

/** Egy korábbi, ugyanazzal a kulccsal rögzített visszáru eredménye — vagy 409, ha a kulcs egy MÁS tartalmú kéréshez tartozik. */
function return_replay_or_reject(array $existing, ?string $fingerprint, array $sale): void
{
    $stored = $existing['idempotency_fingerprint'] ?? null;
    if ($stored !== null && $fingerprint !== null && !hash_equals((string) $stored, $fingerprint)) {
        send_json(['error' => 'Ez az idempotencia-kulcs már egy másik visszáruhoz lett használva — töltsd újra az oldalt.'], 409);
    }
    send_json([
        'return_id'       => (int) $existing['id'],
        'total_refund'    => round((float) $existing['total_refund'], 2),
        'needs_manual_credit_note' => !empty($sale['szamlazz_invoice_number']),
        'original_invoice_number'  => $sale['szamlazz_invoice_number'] ?? null,
        'replayed'        => true,
    ]);
}

// A visszajátszás MEGELŐZI a mennyiségi ellenőrzést: egy már rögzített
// visszáru után a "még visszavihető" mennyiség természetesen kevesebb.
if ($idempotencyKey !== '') {
    $existing = $db->findReturnByIdempotencyKey($idempotencyKey);
    if ($existing && (int) $existing['sale_id'] === $saleId) {
        return_replay_or_reject($existing, $idempotencyFingerprint, $sale);
    } elseif ($existing) {
        send_json(['error' => 'Ez az idempotencia-kulcs már egy másik visszáruhoz lett használva — töltsd újra az oldalt.'], 409);
    }
}

$saleItemsById = [];
foreach ($sale['items'] as $si) {
    $saleItemsById[(int) $si['id']] = $si;
}
$alreadyReturned = $db->getReturnedQuantitiesForSale($saleId);

if (!is_array($requestedItems)) {
    send_json(['error' => 'Érvénytelen visszáru-tétellista.'], 400);
}

$itemsToReturn = [];
$seenSaleItemIds = [];
foreach ($requestedItems as $req) {
    $saleItemId = (int) (is_array($req) ? ($req['sale_item_id'] ?? 0) : 0);
    $qty = (int) (is_array($req) ? ($req['qty'] ?? 0) : 0);

    if ($qty <= 0) {
        continue;
    }
    if (!isset($saleItemsById[$saleItemId])) {
        send_json(['error' => "Ismeretlen eladási tétel: #$saleItemId"], 400);
    }
    // Egy tétel egy kérésen belül csak EGYSZER szerepelhet — különben a
    // soronkénti visszavehető-mennyiség ellenőrzés ismétléssel megkerülhető
    // lenne (több sor, együtt az eladott mennyiségnél többet visszavéve).
    if (isset($seenSaleItemIds[$saleItemId])) {
        send_json(['error' => "Ugyanaz az eladási tétel (#$saleItemId) többször szerepel a kérésben."], 400);
    }
    $seenSaleItemIds[$saleItemId] = true;

    $original = $saleItemsById[$saleItemId];
    $maxReturnable = (int) $original['qty'] - ($alreadyReturned[$saleItemId] ?? 0);
    if ($qty > $maxReturnable) {
        send_json(['error' => "\"{$original['name']}\" tételből legfeljebb $maxReturnable db vihető még vissza."], 400);
    }

    $itemsToReturn[] = [
        'sale_item_id' => $saleItemId,
        'product_id'   => $original['product_id'],
        'name'         => $original['name'],
        'qty'          => $qty,
        'unit_price'   => (float) $original['unit_price'],
    ];
}

if (empty($itemsToReturn)) {
    send_json(['error' => 'Nincs érvényes visszaveendő tétel.'], 400);
}

// A visszatérítendő összeg NEM lehet egyszerűen tétel-egységár × mennyiség:
// az eredeti kupon/hűségszint/pontkedvezmény a teljes rendelésre vonatkozott,
// nem soronként, így a sale_items.unit_price a KEDVEZMÉNY ELŐTTI árat
// tartalmazza. Enélkül a visszatérítés túl sokat adna vissza minden olyan
// eladásnál, ahol bármilyen rendelés-szintű kedvezmény érvényesült.
// Arányosítjuk: a teljes eladás eredeti (kedvezmény nélküli) tételösszegéhez
// képest mekkora hányadot tett ki a ténylegesen fizetett végösszeg, és ezt az
// arányt alkalmazzuk a visszaveendő tételek nyers összegére is. Ezt a
// prorated összeget kell tárolni is, nem csak a válaszban visszaadni.
$originalSubtotal = 0.0;
foreach ($sale['items'] as $si) {
    $originalSubtotal += (float) $si['qty'] * (float) $si['unit_price'];
}
$discountRatio = $originalSubtotal > 0 ? min(1, (float) $sale['total'] / $originalSubtotal) : 1.0;

$rawRefund = array_sum(array_map(static fn ($i) => $i['qty'] * $i['unit_price'], $itemsToReturn));
$totalRefund = round($rawRefund * $discountRatio, 2);

try {
    $returnId = $db->processReturn($saleId, $itemsToReturn, $reason, $staffId, $totalRefund, $sale, $cashRegisterId, $idempotencyKey, $idempotencyFingerprint);
} catch (PDOException $e) {
    // Egy közel egyidejű, ugyanazzal a kulccsal érkező kérés vesztese a
    // UNIQUE-ütközésnél (vagy egy SQLite-zárütközésnél) jár itt — ha a
    // győztes visszáru már létezik, annak eredménye jön vissza.
    if ($idempotencyKey !== '') {
        $winner = $db->findReturnByIdempotencyKey($idempotencyKey);
        if ($winner && (int) $winner['sale_id'] === $saleId) {
            return_replay_or_reject($winner, $idempotencyFingerprint, $sale);
        }
    }
    // B-12: DB-hiba (lock, constraint, SQL) — NEM üzleti ütközés. A
    // PDOException a RuntimeException leszármazottja, ezért előtte kell állnia.
    send_database_error_response($e, 'return-create.php visszáru rögzítése sikertelen');
} catch (RuntimeException $e) {
    // A vesztes egy ritka sorrendben a mennyiségi ellenőrzésen is
    // elbukhat (a győztes közben már visszavette a darabot) — ha a
    // győztes UGYANEZZEL a kulccsal futott, ez visszajátszás, nem ütközés.
    if ($idempotencyKey !== '') {
        $winner = $db->findReturnByIdempotencyKey($idempotencyKey);
        if ($winner && (int) $winner['sale_id'] === $saleId) {
            return_replay_or_reject($winner, $idempotencyFingerprint, $sale);
        }
    }
    // A Database::processReturn() saját, kézzel írt, biztonságosan
    // felhasználó elé tárható üzenete (pl. "...tételből időközben már
    // csak N db vihető vissza.") — valódi üzleti visszajelzés, nem
    // technikai kivétel-részlet.
    send_json(['error' => $e->getMessage()], 409);
} catch (Throwable $e) {
    send_generic_error_response($e, 'return-create.php visszáru rögzítése sikertelen');
}

send_json([
    'return_id'       => $returnId,
    'total_refund'    => round($totalRefund, 2),
    'needs_manual_credit_note' => !empty($sale['szamlazz_invoice_number']),
    'original_invoice_number'  => $sale['szamlazz_invoice_number'] ?? null,
]);
