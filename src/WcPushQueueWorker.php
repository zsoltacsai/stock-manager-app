<?php

require_once __DIR__ . '/WooCommerceClient.php';

/**
 * A WooCommerce készlet-push queue "worker" mechanikája — claim, backoff-
 * időzítés, max attempts, állapotátmenetek. PONTOSAN a NavInvoiceQueueWorker
 * mintáját követi (lásd ott a teljes indoklást), csak EGY fázisú: nincs
 * "beküldve, státuszra várunk" köztes lépés, mert a WooCommerce
 * updateStock() hívás önmagában szinkron/azonnal eldönti a push sikerét.
 *
 * SZÁNDÉKOSAN NEM blokkolja a kasszát/beszerzést/leltárt — ezt a workert
 * egy KÜLÖN, cron-indított kérés hívja (lásd webroot/api/wc-queue-run.php),
 * NEM maga sale.php/purchase-save.php/stock-take-complete.php, amik csak
 * beütemezik a sort (Database::enqueueWcPush()) és azonnal válaszolnak.
 */
class WcPushQueueWorker
{
    /** @see NavInvoiceQueueWorker::BACKOFF_SECONDS — ugyanaz az ütemezés újrafelhasználva, nincs ok egy külön ütemre. */
    private const BACKOFF_SECONDS = [60, 300, 900, 1800, 3600, 10800, 21600, 43200, 86400];

    /** @see NavInvoiceQueueWorker::STALE_LOCK_SECONDS — a WC hívás saját timeoutja (20-45s) tízszerese, bőséges tartalékkal. */
    private const STALE_LOCK_SECONDS = 600;

    private Database $db;
    private WooCommerceClient $client;

    public function __construct(Database $db, WooCommerceClient $client)
    {
        $this->db = $db;
        $this->client = $client;
    }

    /**
     * @return array{claimed:int, done:int, retried:int, permanent_failures:int, dead_letters:int, skipped:int}
     */
    public function processDuePushes(int $limit = 20): array
    {
        $summary = ['claimed' => 0, 'done' => 0, 'retried' => 0, 'permanent_failures' => 0, 'dead_letters' => 0, 'skipped' => 0];

        for ($i = 0; $i < $limit; $i++) {
            $row = $this->db->claimQueuedWcPush(self::STALE_LOCK_SECONDS);
            if ($row === null) {
                break;
            }
            $summary['claimed']++;

            $product = $this->db->findProductById((int) $row['product_id']);
            if (!$product) {
                // A termék időközben törlődött — nincs mit push-olni, ez nem
                // hiba, csak okafogyottá vált. Terminális 'done', hogy ne
                // próbálkozzon örökké egy sose-létező termékkel.
                $this->db->markWcPushDone((int) $row['id']);
                $summary['skipped']++;
                continue;
            }

            try {
                // A push PILLANATÁBAN érvényes, friss készletet olvassuk —
                // NEM a beütemezéskori pillanatképet — lásd
                // migrateV24WcPushQueue() docblockja.
                $this->client->updateStock((int) $row['wc_product_id'], (int) $product['stock_qty']);
                $this->db->touchWcSyncedAt((int) $row['product_id']);
                $this->db->logSync('push', (int) $row['product_id'], "Stock pushed via queue (#{$row['id']}, {$row['trigger_type']}:{$row['trigger_id']})");
                $this->db->markWcPushDone((int) $row['id']);
                $summary['done']++;
            } catch (WooCommerceRequestException $e) {
                if ($e->retryable) {
                    $this->applyRetryOrDeadLetter($row, $e->getMessage(), $summary);
                } else {
                    $this->db->markWcPushFailed((int) $row['id'], $e->getMessage());
                    $this->db->logSync('push', (int) $row['product_id'], 'FAILED: ' . $e->getMessage());
                    $summary['permanent_failures']++;
                }
            } catch (Throwable $e) {
                // Nem WooCommerce-specifikus hiba (pl. UrlSafety elutasítás
                // egy megváltozott/érvénytelen store_url miatt) — konfigurációs
                // jellegű, egy azonnali újrapróbálkozás ugyanezt adná, tehát
                // VÉGLEGES, nem a hálózati backoff-ütemezésbe kerül.
                $this->db->markWcPushFailed((int) $row['id'], $e->getMessage());
                $this->db->logSync('push', (int) $row['product_id'], 'FAILED: ' . $e->getMessage());
                $summary['permanent_failures']++;
            }
        }

        return $summary;
    }

    private function applyRetryOrDeadLetter(array $row, string $error, array &$summary): void
    {
        $attempts = (int) $row['attempts'] + 1;

        if ($attempts > count(self::BACKOFF_SECONDS)) {
            $this->db->markWcPushDeadLetter((int) $row['id'], $error);
            $this->db->logSync('push', (int) $row['product_id'], 'DEAD_LETTER: ' . $error);
            $summary['dead_letters']++;
            return;
        }

        $delay = self::BACKOFF_SECONDS[$attempts - 1];
        $this->db->scheduleWcPushRetry((int) $row['id'], $error, date('Y-m-d H:i:s', time() + $delay), $attempts);
        $summary['retried']++;
    }
}
