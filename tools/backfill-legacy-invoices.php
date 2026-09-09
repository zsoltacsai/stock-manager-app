<?php

declare(strict_types=1);

/**
 * Egyszeri, kézzel futtatandó, IDEMPOTENS backfill script — a Phase 1-4
 * (az `invoices` tábla és a Számlázz.hu-s `upsertInvoiceMirror()`
 * mirror-írás) BEVEZETÉSE ELŐTTI eladásokhoz pótolja a hiányzó `invoices`
 * sort, hogy a "Kimenő számlák" nézet (ami kizárólag az `invoices`
 * táblát olvassa) ezeket a régi, ténylegesen kiállított Számlázz.hu-s
 * számlákat is mutassa.
 *
 * FONTOS GARANCIÁK:
 *   - Sose módosít vagy töröl semmilyen `sales`/`sale_items` sort — csak
 *     `INSERT`-et hajt végre az `invoices` táblába.
 *   - Idempotens: `sale_id`+`provider='szamlazz'` UNIQUE indexre
 *     támaszkodó `INSERT OR IGNORE`/`INSERT IGNORE`-t használ — egy
 *     ismételt futtatás nem hoz létre duplikátumot, nem sérti a
 *     (sale_id, provider) egyediséget.
 *   - Csak azokat a sale-eket érinti, amiknél TÉNYLEGESEN volt kiállított
 *     számla (`szamlazz_invoice_number IS NOT NULL AND != ''`) — a
 *     véglegesen sikertelen (`invoice_failed`, számlaszám nélküli)
 *     kísérleteket szándékosan kihagyja (nincs mihez kötni őket).
 *   - A nettó/ÁFA bontást a `sale_items` tételes adataiból számolja
 *     újra, UGYANAZZAL a kedvezmény-arányosítási képlettel, mint
 *     amit `webroot/api/sale.php` a valódi (élő) számlázáskor használ
 *     (lásd `$invoiceDiscountRatio` ott) — enélkül egy kuponnal/
 *     hűségponttal kedvezményezett régi eladásnál a visszaszámolt
 *     nettó+ÁFA összeg nem adná ki a ténylegesen kiszámlázott bruttó
 *     összeget.
 *   - MINDEN egyes sale INSERT-je egy KÜLÖN, saját tranzakcióban fut
 *     (beginTransaction/commit/rollBack) — ez SZÁNDÉKOSAN NEM egyetlen,
 *     a teljes futást átfogó tranzakció: egy nagy `sales` táblán egy
 *     órákig nyitva tartott tranzakció felesleges zárolást jelentene, ÉS
 *     egy félbeszakadt futás esetén MINDENT visszagörgetne, ahelyett hogy
 *     onnan folytatható lenne, ahol abbamaradt. Mivel minden sale saját,
 *     önálló, egyetlen INSERT-ből álló tranzakció, egy menet közbeni
 *     megszakítás (pl. kill -9, áramszünet) legfeljebb annyit jelent,
 *     hogy a MÉG FEL NEM DOLGOZOTT sale-ek egyszerűen a következő
 *     futtatáskor (ami idempotens) kerülnek sorra — SOSE marad félkész,
 *     részlegesen kitöltött `invoices` sor.
 *
 * Futtatás:
 *   php tools/backfill-legacy-invoices.php --dry-run   (NEM ír semmit, csak riportol)
 *   php tools/backfill-legacy-invoices.php             (a valódi, éles backfill)
 */

require __DIR__ . '/../src/Database.php';

$cliArgs = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $cliArgs, true);
// Opcionális, a --dry-run-tól eltérő argumentum: egy alternatív SQLite
// fájl elérési útja — KIZÁRÓLAG automatizált tesztekhez (lásd
// tests/BackfillLegacyInvoicesTest.php), hogy a script valódi élő
// adatbázis nélkül, egy eldobható ideiglenes fájlon is tesztelhető
// legyen. Nélküle a script a normál config/config.php-ban beállított
// (éles) adatbázist használja.
$dbPathArg = null;
foreach ($cliArgs as $arg) {
    if ($arg !== '--dry-run') {
        $dbPathArg = $arg;
        break;
    }
}

if ($dbPathArg !== null) {
    $db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPathArg]], dirname(__DIR__));
} else {
    $config = require __DIR__ . '/../config/config.php';
    $db = new Database($config['db'], dirname(__DIR__));
}
$pdo = $db->pdo();
$driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

$candidates = $pdo->query("
    SELECT id, szamlazz_invoice_number, szamlazz_pdf_path, total, created_at
    FROM sales
    WHERE szamlazz_invoice_number IS NOT NULL AND szamlazz_invoice_number != ''
    ORDER BY id
")->fetchAll(PDO::FETCH_ASSOC);

$totalMigratable = count($candidates);
$alreadyExisting = 0;
$pendingOrCreated = 0; // dry-run: "migrálásra váró"; éles futás: "újonnan létrehozott"
$skipped = 0;
$errors = 0;
$skipReasons = [];
$errorDetails = [];
$skippedSaleIds = [];
$errorSaleIds = [];

$insertSql = $driver === 'mysql'
    ? "INSERT IGNORE INTO invoices (sale_id, provider, status, invoice_number, net_total, vat_total, gross_total, currency, issued_at, pdf_path, created_at, updated_at)
       VALUES (?, 'szamlazz', 'done', ?, ?, ?, ?, 'HUF', ?, ?, ?, ?)"
    : "INSERT OR IGNORE INTO invoices (sale_id, provider, status, invoice_number, net_total, vat_total, gross_total, currency, issued_at, pdf_path, created_at, updated_at)
       VALUES (?, 'szamlazz', 'done', ?, ?, ?, ?, 'HUF', ?, ?, ?, ?)";
$insertStmt = $pdo->prepare($insertSql);
$itemsStmt = $pdo->prepare('SELECT unit_price, qty, vat_rate FROM sale_items WHERE sale_id = ?');

foreach ($candidates as $sale) {
    $saleId = (int) $sale['id'];

    // Az idempotencia ELSŐDLEGES bizonyítéka (éles futásnál) az INSERT OR
    // IGNORE/IGNORE lent — ez az előzetes ellenőrzés a pontos összesítő
    // riporthoz kell (hogy "már létező" és "migrálásra váró/létrehozott"
    // külön számolható legyen), ÉS ez adja a dry-run egyetlen forrását
    // (dry-run sose ír, tehát az INSERT OR IGNORE saját rowCount()-jára
    // nem támaszkodhat).
    if ($db->findInvoiceBySaleAndProvider($saleId, 'szamlazz') !== null) {
        $alreadyExisting++;
        continue;
    }

    try {
        $itemsStmt->execute([$saleId]);
        $items = $itemsStmt->fetchAll(PDO::FETCH_ASSOC);

        if (!$items) {
            $skipped++;
            $skippedSaleIds[] = $saleId;
            $skipReasons[] = "sale #$saleId: nincs hozzá sale_items sor, a nettó/ÁFA nem számolható vissza";
            continue;
        }

        $subtotal = 0.0;
        foreach ($items as $item) {
            $subtotal += (float) $item['unit_price'] * (float) $item['qty'];
        }
        $grossTotal = round((float) $sale['total'], 2);
        // Ugyanaz a kedvezmény-arányosítás, mint sale.php $invoiceDiscountRatio-ja.
        $discountRatio = $subtotal > 0 ? min(1, $grossTotal / $subtotal) : 1.0;

        $netTotal = 0.0;
        foreach ($items as $item) {
            $vatRate = (string) $item['vat_rate'];
            $vatPct = is_numeric($vatRate) ? ((float) $vatRate) / 100 : 0.0;
            $unitGross = round((float) $item['unit_price'] * $discountRatio, 2);
            $lineGross = $unitGross * (float) $item['qty'];
            $netTotal += is_numeric($vatRate) ? round($lineGross / (1 + $vatPct), 2) : $lineGross;
        }
        $netTotal = round($netTotal, 2);
        $vatTotal = round($grossTotal - $netTotal, 2);

        if ($dryRun) {
            // NEM ír semmit — a számítás sikeres lefutása (idáig eljutott,
            // kivétel nélkül) önmagában bizonyítja, hogy éles futáskor ez
            // a sor sikeresen migrálható lenne.
            $pendingOrCreated++;
            continue;
        }

        $pdo->beginTransaction();
        try {
            $insertStmt->execute([
                $saleId,
                $sale['szamlazz_invoice_number'],
                $netTotal,
                $vatTotal,
                $grossTotal,
                $sale['created_at'],
                $sale['szamlazz_pdf_path'],
                $sale['created_at'],
                $sale['created_at'],
            ]);

            if ($insertStmt->rowCount() > 0) {
                $pdo->commit();
                $pendingOrCreated++;
            } else {
                // Az INSERT OR IGNORE/IGNORE elnyelte (párhuzamos futás
                // vagy az előzetes ellenőrzés óta közben létrejött sor) —
                // nincs mit commit-olni/rollback-elni, de a tranzakciót
                // konzisztensen zárjuk.
                $pdo->rollBack();
                $alreadyExisting++;
            }
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
    } catch (Throwable $e) {
        $errors++;
        $errorSaleIds[] = $saleId;
        $errorDetails[] = "sale #$saleId: " . $e->getMessage();
    }
}

$reconciled = $alreadyExisting + $pendingOrCreated + $skipped + $errors;

if ($dryRun) {
    echo "=== DRY RUN — AZ ADATBÁZIS NEM MÓDOSULT, SEMMILYEN INSERT/UPDATE NEM TÖRTÉNT ===\n\n";
}

echo "=== Backfill " . ($dryRun ? "dry-run " : "") . "eredmény ===\n";
echo "Migrálható régi Számlázz.hu-s sale (szamlazz_invoice_number IS NOT NULL): $totalMigratable\n";
echo "Már meglévő matching invoices rekord: $alreadyExisting\n";
echo ($dryRun ? "Migrálásra váró rekord" : "Újonnan létrehozott invoices rekord") . ": $pendingOrCreated\n";
echo "Kihagyott rekord: $skipped\n";
echo "Hibás/problémás rekord: $errors\n";
echo "\nÖsszesített darabszám (migrálható): $totalMigratable\n";
echo "Ellenőrzés: migrálható ($totalMigratable) == meglévő ($alreadyExisting) + "
    . ($dryRun ? "migrálásra váró" : "létrehozott") . " ($pendingOrCreated) + kihagyott ($skipped) + hibás ($errors) = $reconciled => "
    . ($totalMigratable === $reconciled ? "OK\n" : "MISMATCH — vizsgáld meg a fenti számokat!\n");

if ($skippedSaleIds) {
    echo "\nKihagyott sale_id-k: " . implode(', ', $skippedSaleIds) . "\n";
    echo "Kihagyás okai:\n" . implode("\n", $skipReasons) . "\n";
}
if ($errorSaleIds) {
    echo "\nHibás/problémás sale_id-k: " . implode(', ', $errorSaleIds) . "\n";
    echo "Hibák:\n" . implode("\n", $errorDetails) . "\n";
}

if ($dryRun) {
    echo "\nEz egy DRY RUN volt — nem történt tényleges adatbázis-módosítás. A valódi backfillhez futtasd argumentum nélkül: php tools/backfill-legacy-invoices.php\n";
}
