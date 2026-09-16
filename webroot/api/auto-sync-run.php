<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/Settings.php';

$settings = new Settings(__DIR__ . '/../../data/settings.json');
$current = $settings->read();

if (empty($current['auto_sync_enabled'])) {
    send_json(['skipped' => true, 'reason' => 'disabled']);
}

$intervalMinutes = max(1, (int) $current['auto_sync_interval_minutes']);
$lastRun = $current['last_auto_sync_at'] ? strtotime($current['last_auto_sync_at']) : 0;
$dueAt = $lastRun + ($intervalMinutes * 60);

if (time() < $dueAt) {
    send_json(['skipped' => true, 'reason' => 'not_due', 'next_run_at' => date('c', $dueAt)]);
}

try {
    $wc = new WooCommerceClient($config['woocommerce']);
    $result = $wc->fetchAllProducts();
    $products = $result['products'];

    $imported = 0;
    $skipped = 0;
    // Regresszió (1.3.1): lásd sync-pull.php ugyanezen javításának
    // docblokkja — a teljes katalógust egyetlen tranzakcióban feldolgozó
    // korábbi minta SQLite-on a teljes szinkron idejére fogva tartotta az
    // író-zárat, blokkolva egy közben induló valódi eladást. Ez a végpont
    // cronból, gyakran (akár percenként) fut, tehát ez a kockázat itt
    // különösen releváns.
    foreach (array_chunk($products, 200) as $chunk) {
        $db->beginTransaction();
        try {
            foreach ($chunk as $p) {
                if (empty($p['barcode'])) {
                    $skipped++;
                    continue;
                }
                $db->upsertProductFromWc($p);
                $imported++;
            }
            $db->commit();
        } catch (Throwable $e) {
            $db->rollBack();
            throw $e;
        }
    }

    $summary = "$imported termék frissítve, $skipped kihagyva" . ($result['truncated'] ? ' — FIGYELEM: a katalógus nagyobb 5000 tételnél, nem lett mind feldolgozva' : '');
    $settings->save([
        'last_auto_sync_at'      => date('c'),
        'last_auto_sync_summary' => $summary,
    ]);

    send_json(['ran' => true, 'imported' => $imported, 'skipped' => $skipped]);
} catch (RuntimeException $e) {
    // A WooCommerceClient saját, biztonságosan felhasználó (admin, a
    // Rendszerállapot oldalon a mentett last_auto_sync_summary-n
    // keresztül) elé tárható hibaüzenete — lásd sync-pull.php ugyanezen
    // indoklását.
    $db->rollBack();
    $settings->save([
        'last_auto_sync_at'      => date('c'),
        'last_auto_sync_summary' => 'Hiba: ' . $e->getMessage(),
    ]);
    send_json(['ran' => true, 'error' => $e->getMessage()], 502);
} catch (Throwable $e) {
    $db->rollBack();
    error_log('[fountaintrade] auto-sync-run.php: ' . get_class($e) . ': ' . $e->getMessage());
    $settings->save([
        'last_auto_sync_at'      => date('c'),
        'last_auto_sync_summary' => 'Váratlan szerverhiba történt — részletek a szerver naplójában.',
    ]);
    send_json(['ran' => true, 'error' => 'Váratlan szerverhiba történt.'], 500);
}
