<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * WcPushQueueWorker tesztek — a queue-MECHANIKÁT (claim, backoff, max
 * attempts, állapotátmenetek) ellenőrzik VALÓDI Database felett, csak a
 * WooCommerce HTTP-réteg van helyettesítve egy "szkriptelt" fake
 * WooCommerceClient-tel (lásd FakeWooCommerceClientForQueueTest lentebb) —
 * PONTOSAN a NavInvoiceQueueWorkerTest.php mintáját követve (ott a NavClient
 * pluggable $transport callable-jével, itt — mivel a WooCommerceClient
 * konstruktora nem fogad ilyet — egy metódus-felülíró al-osztállyal, mert
 * a WooCommerceClient::request() maga SSRF-védett (lásd UrlSafety::check()),
 * ezért valódi loopback-hívással a WORKER retry/backoff/dead-letter
 * ütemezését nem lehetne 9 lépésen át gyorsan és determinisztikusan
 * végigfuttatni — a HTTP-réteg saját válasz-osztályozását lásd
 * tests/WooCommerceClientTest.php, VALÓDI loopback-hívásokkal).
 * A VALÓS, több-folyamatos konkurrencia-tesztet lásd
 * tests/PurchaseAndWcPushConcurrencyTest.php::testConcurrentClaimQueuedWcPushYieldsExactlyOneWinner().
 */
final class WcPushQueueWorkerTest extends TestCase
{
    private function sampleProduct(array $overrides = []): array
    {
        return array_merge([
            'name' => 'WC push worker teszt termék', 'unit' => 'db', 'vat_rate' => '27',
            'net_price' => 1000, 'price' => 1270, 'stock_qty' => 20,
        ], $overrides);
    }

    public function testProcessDuePushesMarksSuccessfulPushAsDone(): void
    {
        $db = tests_new_database();
        $productId = $db->saveProduct($this->sampleProduct());
        $db->incrementStock($productId, 20); // saveProduct() nem állít stock_qty-t, lásd sampleProduct() docblockja fent
        $db->enqueueWcPush($productId, 555, 'sale', 1);

        $client = new FakeWooCommerceClientForQueueTest([null]); // null = siker (nincs kivétel)
        $worker = new WcPushQueueWorker($db, $client);
        $summary = $worker->processDuePushes(10);

        $this->assertSame(1, $summary['claimed']);
        $this->assertSame(1, $summary['done']);
        $this->assertSame(0, $summary['retried']);
        $this->assertCount(1, $client->calls);
        $this->assertSame(555, $client->calls[0]['wc_product_id']);
        $this->assertSame(20, $client->calls[0]['qty'], 'A push-nak a PUSH PILLANATÁBAN érvényes, friss készletet kell küldenie.');
    }

    public function testRetryableFailureReschedulesWithBackoffAndIncrementsAttempts(): void
    {
        $db = tests_new_database();
        $productId = $db->saveProduct($this->sampleProduct());
        $row = $db->enqueueWcPush($productId, 555, 'sale', 1);

        $client = new FakeWooCommerceClientForQueueTest([
            new WooCommerceRequestException('átmeneti hiba', retryable: true),
        ]);
        $worker = new WcPushQueueWorker($db, $client);
        $summary = $worker->processDuePushes(10);

        $this->assertSame(1, $summary['retried']);
        $this->assertSame(0, $summary['dead_letters']);

        $refetched = $db->getWcPushQueueRowById((int) $row['id']);
        $this->assertSame('queued', $refetched['status'], 'Egy retryable hiba után vissza kell kerülnie queued-ra, a következő backoff-időpontra.');
        $this->assertSame(1, (int) $refetched['attempts']);
        $this->assertNotNull($refetched['next_attempt_at']);
        $this->assertGreaterThan(date('Y-m-d H:i:s'), $refetched['next_attempt_at'], 'A következő próbálkozásnak a JÖVŐBEN kell esedékesnek lennie (60s backoff), nem azonnalinak.');
    }

    public function testPermanentFailureMarksFailedWithoutRetry(): void
    {
        $db = tests_new_database();
        $productId = $db->saveProduct($this->sampleProduct());
        $row = $db->enqueueWcPush($productId, 555, 'sale', 1);

        $client = new FakeWooCommerceClientForQueueTest([
            new WooCommerceRequestException('hibás hitelesítő adat', retryable: false, httpStatus: 401),
        ]);
        $worker = new WcPushQueueWorker($db, $client);
        $summary = $worker->processDuePushes(10);

        $this->assertSame(1, $summary['permanent_failures']);
        $this->assertSame(0, $summary['retried']);

        $refetched = $db->getWcPushQueueRowById((int) $row['id']);
        $this->assertSame('failed', $refetched['status'], 'Egy VÉGLEGES (4xx) hiba nem ütemez újra próbálkozást.');
        $this->assertNull($refetched['next_attempt_at']);
    }

    public function testBackoffScheduleExhaustsIntoDeadLetterAfterNineRetries(): void
    {
        $db = tests_new_database();
        $productId = $db->saveProduct($this->sampleProduct());
        $row = $db->enqueueWcPush($productId, 555, 'sale', 1);
        $id = (int) $row['id'];

        // 9 retryable hiba egymás után — mindegyik után a workernek
        // AZONNAL esedékesnek kell látnia a sort a következő futtatáshoz
        // (lásd lentebb: next_attempt_at manuálisan a múltba állítva, hogy
        // a teszt ne várjon ténylegesen órákat/napokat a valódi backoff-
        // ütemezés szerint).
        $client = new FakeWooCommerceClientForQueueTest(array_fill(0, 9, new WooCommerceRequestException('folyamatos hiba', retryable: true)));
        $worker = new WcPushQueueWorker($db, $client);

        for ($i = 0; $i < 9; $i++) {
            $summary = $worker->processDuePushes(10);
            $this->assertSame(1, $summary['claimed'], "A(z) " . ($i + 1) . ". körben claim-elhetőnek kell lennie.");
            // A backoff miatt a next_attempt_at a jövőben van — a teszt
            // közvetlenül a múltba tolja, hogy a KÖVETKEZŐ kör azonnal
            // esedékesnek lássa (a backoff IDŐTARTAMÁT máshol, DB-szinten
            // már ellenőriztük — lásd testRetryableFailureReschedulesWithBackoffAndIncrementsAttempts()).
            $db->pdo()->prepare("UPDATE wc_push_queue SET next_attempt_at = ? WHERE id = ?")
                ->execute([date('Y-m-d H:i:s', time() - 1), $id]);
        }

        $refetched = $db->getWcPushQueueRowById($id);
        $this->assertSame(9, (int) $refetched['attempts']);
        $this->assertSame('queued', $refetched['status'], 'A 9. backoff-lépés UTÁN még queued — a 10. próbálkozás dönt a dead_letter-ről.');

        // 10. próbálkozás — a backoff-ütemezés (9 elem) kimerült.
        $client->behaviors[] = new WooCommerceRequestException('végleg kimerült', retryable: true);
        $summary = $worker->processDuePushes(10);
        $this->assertSame(1, $summary['dead_letters']);

        $final = $db->getWcPushQueueRowById($id);
        $this->assertSame('dead_letter', $final['status']);
    }

    public function testWcPushQueueForeignKeyPreventsHardDeletingAReferencedProduct(): void
    {
        // A termék "törlése" ebben az appban mindenhol SOFT delete
        // (is_deleted flag, lásd bulkSetProductsDeleted()) — egy FIZIKAI
        // DELETE-et a wc_push_queue.product_id FK-ja eleve megakadályoz,
        // amíg van rá hivatkozó (még fel nem dolgozott) push-sor. Ez a
        // WcPushQueueWorker::processDuePushes()-beli "$product === null"
        // védelmet (lásd ott) SZERKEZETILEG elérhetetlenné teszi a normál
        // működésben — ez a teszt ezt a garanciát bizonyítja, nem magát az
        // (emiatt gyakorlatilag sose lefutó) ágat.
        $db = tests_new_database();
        $productId = $db->saveProduct($this->sampleProduct());
        $db->enqueueWcPush($productId, 555, 'sale', 1);

        $this->expectException(PDOException::class);
        $db->pdo()->prepare('DELETE FROM products WHERE id = ?')->execute([$productId]);
    }

    public function testProcessDuePushesRespectsLimitAndStopsWhenQueueEmpty(): void
    {
        $db = tests_new_database();
        for ($i = 0; $i < 3; $i++) {
            $productId = $db->saveProduct($this->sampleProduct(['name' => "Termék $i"]));
            $db->enqueueWcPush($productId, 100 + $i, 'sale', $i);
        }

        $client = new FakeWooCommerceClientForQueueTest([null, null, null]);
        $worker = new WcPushQueueWorker($db, $client);
        $summary = $worker->processDuePushes(10); // limit > tényleges sorok száma

        $this->assertSame(3, $summary['claimed'], 'Üres queue esetén a ciklusnak le kell állnia a limit elérése előtt is.');
        $this->assertSame(3, $summary['done']);
    }
}

/**
 * Szkriptelt fake — a `$behaviors` tömb elemeit egyesével "fogyasztja el"
 * minden updateStock()-hívásnál: `null` = siker (nincs kivétel), egy
 * `Throwable` = az adott kivétel dobása. Szándékosan NEM hívja a szülő
 * konstruktort (nincs rá szükség — az updateStock() teljesen felül van
 * írva, sose ér el a valódi cURL-hívásig).
 */
class FakeWooCommerceClientForQueueTest extends WooCommerceClient
{
    public array $calls = [];

    public function __construct(public array $behaviors)
    {
    }

    public function updateStock(int $wcProductId, int $qty): void
    {
        $this->calls[] = ['wc_product_id' => $wcProductId, 'qty' => $qty];
        $behavior = array_shift($this->behaviors);
        if ($behavior instanceof Throwable) {
            throw $behavior;
        }
    }
}
