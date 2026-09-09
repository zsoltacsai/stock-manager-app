<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * NavIncomingInvoiceSyncWorker tesztek — a queue-MECHANIKÁT (claim, backoff,
 * cursor-kezelés, első vs. inkrementális sync, kimaradt cron utáni
 * felzárkózás) ellenőrzik VALÓDI Database + VALÓDI NavIncomingInvoiceSync
 * felett, csak a NAV HTTP-réteg mockolt — a NavInvoiceQueueWorkerTest.php
 * elvét követve. A VALÓS, több-folyamatos konkurrencia-bizonyítékot lásd
 * DatabaseTest.php::testIncomingInvoiceSyncClaimIsAtomicAcrossRealConcurrentProcesses().
 */
final class NavIncomingInvoiceSyncWorkerTest extends TestCase
{
    private function fakeCfg(): array
    {
        return [
            'nav_login' => 'teszt', 'nav_password' => 'teszt', 'nav_signer_key' => 'teszt-signer-key-1234567890',
            'nav_exchange_key' => 'ABCDEFGHIJKLMNOP', 'nav_tax_number' => '12345678', 'nav_test_mode' => true,
        ];
    }

    /** @param callable(string $xml): array{status:int,body:string} $handler */
    private function syncWith(Database $db, callable $handler): NavIncomingInvoiceSync
    {
        $clientFactory = fn () => new NavClient($this->fakeCfg(), function (string $url, string $xml) use ($handler) {
            return $handler($xml);
        });
        return new NavIncomingInvoiceSync($db, $clientFactory);
    }

    private function emptyDigestPageXml(): string
    {
        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<QueryInvoiceDigestResponse xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            . '<common:result><funcCode>OK</funcCode></common:result>'
            . '<invoiceDigestResult><currentPage>1</currentPage><availablePage>1</availablePage></invoiceDigestResult>'
            . '</QueryInvoiceDigestResponse>';
    }

    // ---- Első sync (cursor még NULL) ----

    public function testTriggerManualSyncSetsInitialCursorFromRequestedPeriod(): void
    {
        $db = tests_new_database();
        $sync = $this->syncWith($db, fn () => ['status' => 200, 'body' => $this->emptyDigestPageXml()]);
        $worker = new NavIncomingInvoiceSyncWorker($db, $sync);

        $before = $db->getIncomingInvoiceSyncState('nav');
        $this->assertNull($before['sync_cursor_ins_date']);

        $result = $worker->triggerManualSync(['period' => '7d']);

        $this->assertSame('success', $result['outcome']);
        $state = $db->getIncomingInvoiceSyncState('nav');
        $this->assertNotNull($state['sync_cursor_ins_date']);
        $this->assertSame('success', $state['status']);
        $sevenDaysAgo = time() - 7 * 86400;
        $cursorTs = strtotime($state['sync_cursor_ins_date']);
        // A cursor valahol a "7 nappal ezelőtt"-höz közel kell legyen (a
        // futás során eltelt néhány másodperc miatt nem pontos egyezés).
        $this->assertLessThan(60, abs($cursorTs - time()), 'a rövid (7 napos, üres) tartomány egyetlen ablakban, "most"-ig feldolgozásra kerül');
    }

    public function testProcessDueSyncUsesDefaultLookbackWhenNeverManuallyConfigured(): void
    {
        $db = tests_new_database();
        $sync = $this->syncWith($db, fn () => ['status' => 200, 'body' => $this->emptyDigestPageXml()]);
        $worker = new NavIncomingInvoiceSyncWorker($db, $sync);

        // Sose volt admin-kezdeményezett sync — a CRON worker saját,
        // dokumentált alapértelmezését (7 nap) kell használja, NEM
        // hibázhat/állhat le "nincs kezdőpont" miatt.
        $result = $worker->processDueSync();

        $this->assertSame('success', $result['outcome']);
        $state = $db->getIncomingInvoiceSyncState('nav');
        $this->assertNotNull($state['sync_cursor_ins_date']);
    }

    // ---- Manuális + automatikus sync verseny elleni védelem ----

    public function testTriggerManualSyncReturnsAlreadyRunningWhenAnotherSyncHoldsTheClaim(): void
    {
        $db = tests_new_database();
        // Egy MÁSIK (pl. a cron) worker már lefoglalta a sort.
        $claimed = $db->claimIncomingInvoiceSync('nav', 1800);
        $this->assertNotNull($claimed);

        $sync = $this->syncWith($db, fn () => ['status' => 200, 'body' => $this->emptyDigestPageXml()]);
        $worker = new NavIncomingInvoiceSyncWorker($db, $sync);

        $result = $worker->triggerManualSync(['period' => '7d']);

        $this->assertSame('already_running', $result['outcome']);
    }

    public function testProcessDueSyncReturnsAlreadyRunningWhenClaimHeld(): void
    {
        $db = tests_new_database();
        $claimed = $db->claimIncomingInvoiceSync('nav', 1800);
        $this->assertNotNull($claimed);

        $sync = $this->syncWith($db, fn () => ['status' => 200, 'body' => $this->emptyDigestPageXml()]);
        $worker = new NavIncomingInvoiceSyncWorker($db, $sync);

        $result = $worker->processDueSync();

        $this->assertSame('already_running', $result['outcome']);
    }

    // ---- Backoff — a cron nem próbálkozik idő előtt ----

    public function testProcessDueSyncIsNotDueBeforeScheduledRetryTime(): void
    {
        $db = tests_new_database();
        $db->markIncomingInvoiceSyncRetry('nav', 'átmeneti hiba', date('Y-m-d H:i:s', time() + 3600), 1);

        $sync = $this->syncWith($db, function () {
            $this->fail('A NAV-ot NEM szabad hívni, amíg a next_attempt_at a jövőben van.');
        });
        $worker = new NavIncomingInvoiceSyncWorker($db, $sync);

        $result = $worker->processDueSync();

        $this->assertSame('not_due', $result['outcome']);
    }

    public function testProcessDueSyncRunsWhenRetryTimeHasPassed(): void
    {
        $db = tests_new_database();
        $db->markIncomingInvoiceSyncRetry('nav', 'átmeneti hiba', date('Y-m-d H:i:s', time() - 5), 1);
        $db->setIncomingInvoiceSyncCursorIfUnset('nav', gmdate('Y-m-d\TH:i:s\Z', time() - 3600));

        $sync = $this->syncWith($db, fn () => ['status' => 200, 'body' => $this->emptyDigestPageXml()]);
        $worker = new NavIncomingInvoiceSyncWorker($db, $sync);

        $result = $worker->processDueSync();

        $this->assertSame('success', $result['outcome']);
    }

    // ---- Retry / backoff ütemezés hiba esetén ----

    public function testFailingSyncSchedulesRetryWithBackoffAndPreservesCursor(): void
    {
        $db = tests_new_database();
        $db->setIncomingInvoiceSyncCursorIfUnset('nav', gmdate('Y-m-d\TH:i:s\Z', time() - 3600));

        $sync = $this->syncWith($db, function () {
            throw new RuntimeException('NAV kapcsolati hiba: timeout');
        });
        $worker = new NavIncomingInvoiceSyncWorker($db, $sync);

        $result = $worker->processDueSync();

        $this->assertSame('retry', $result['outcome']);
        $state = $db->getIncomingInvoiceSyncState('nav');
        $this->assertSame('retry', $state['status']);
        $this->assertSame(1, (int) $state['attempts']);
        $this->assertNotNull($state['next_attempt_at']);
        $this->assertGreaterThan(time(), strtotime($state['next_attempt_at']));
        $this->assertNull($state['locked_at']);
    }

    public function testNonRetryableBusinessErrorMarksFailedImmediately(): void
    {
        $db = tests_new_database();
        $db->setIncomingInvoiceSyncCursorIfUnset('nav', gmdate('Y-m-d\TH:i:s\Z', time() - 3600));

        $body = '<?xml version="1.0"?><QueryInvoiceDigestResponse xmlns:common="http://schemas.nav.gov.hu/NTCA/1.0/common" xmlns="http://schemas.nav.gov.hu/OSA/3.0/api">'
            . '<common:result><funcCode>ERROR</funcCode><errorCode>SCHEMA_VIOLATION</errorCode><message>hiba</message></common:result>'
            . '</QueryInvoiceDigestResponse>';
        $sync = $this->syncWith($db, fn () => ['status' => 400, 'body' => $body]);
        $worker = new NavIncomingInvoiceSyncWorker($db, $sync);

        $result = $worker->processDueSync();

        $this->assertSame('failed', $result['outcome']);
        $state = $db->getIncomingInvoiceSyncState('nav');
        $this->assertSame('failed', $state['status']);
    }

    // ---- Kimaradt cron utáni felzárkózás ----

    public function testMissedCronPeriodIsCaughtUpAcrossSubsequentRuns(): void
    {
        $db = tests_new_database();
        // Egy ~100 napja "elakadt" sync szimulációja — mintha a cron ennyi
        // ideje nem futott volna (vagy sose lett admin által elindítva).
        $db->setIncomingInvoiceSyncCursorIfUnset('nav', gmdate('Y-m-d\TH:i:s\Z', time() - 100 * 86400));

        $sync = $this->syncWith($db, fn () => ['status' => 200, 'body' => $this->emptyDigestPageXml()]);
        $worker = new NavIncomingInvoiceSyncWorker($db, $sync);

        $initialState = $db->getIncomingInvoiceSyncState('nav');
        $initialCursorTs = strtotime($initialState['sync_cursor_ins_date']);

        // Minden egyes processDueSync()-hívás EGY cron-tick-et szimulál,
        // ablakonként max. 1 ablakot dolgozva fel (100 nap / 35 = 3 ablak).
        $cursorsSeen = [$initialCursorTs];
        $hadMore = true;
        $iterations = 0;
        while ($hadMore && $iterations < 10) {
            $result = $worker->processDueSync(1);
            $this->assertSame('success', $result['outcome']);
            $state = $db->getIncomingInvoiceSyncState('nav');
            $cursorsSeen[] = strtotime($state['sync_cursor_ins_date']);
            $hadMore = $result['has_more'];
            $iterations++;
        }

        $this->assertGreaterThanOrEqual(3, $iterations, 'a 100 napos elmaradást legalább 3, egyenként max 35 napos ablakban kell felzárkózni');
        $this->assertFalse($hadMore, 'az utolsó futás után nem maradhat feldolgozatlan ablak');
        // Minden egyes tick szigorúan ELŐRE tolja a cursor-t — egy korábban
        // már feldolgozott ablakot a KÖVETKEZŐ tick sose dolgoz fel újra.
        for ($i = 1; $i < count($cursorsSeen); $i++) {
            $this->assertGreaterThan($cursorsSeen[$i - 1], $cursorsSeen[$i], 'a cursor-nak minden tick-nél szigorúan előre kell haladnia');
        }
        $finalState = $db->getIncomingInvoiceSyncState('nav');
        $this->assertLessThan(60, abs(time() - strtotime($finalState['sync_cursor_ins_date'])), 'a felzárkózás végén a cursor gyakorlatilag "most"-nál áll');
        $this->assertSame('success', $finalState['status']);
    }
}
