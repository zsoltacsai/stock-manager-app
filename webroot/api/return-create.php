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

if (!$saleId || empty($requestedItems)) {
    send_json(['error' => 'Válassz ki legalább egy visszaveendő tételt.'], 400);
}

$sale = $db->getSaleWithItems($saleId);
if (!$sale) {
    send_json(['error' => 'Az eladás nem található.'], 404);
}

$saleItemsById = [];
foreach ($sale['items'] as $si) {
    $saleItemsById[(int) $si['id']] = $si;
}
$alreadyReturned = $db->getReturnedQuantitiesForSale($saleId);

$itemsToReturn = [];
foreach ($requestedItems as $req) {
    $saleItemId = (int) ($req['sale_item_id'] ?? 0);
    $qty = (int) ($req['qty'] ?? 0);

    if ($qty <= 0) {
        continue;
    }
    if (!isset($saleItemsById[$saleItemId])) {
        send_json(['error' => "Ismeretlen eladási tétel: #$saleItemId"], 400);
    }

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
    $returnId = $db->processReturn($saleId, $itemsToReturn, $reason, $staffId, $totalRefund, $sale, $cashRegisterId);
} catch (RuntimeException $e) {
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
