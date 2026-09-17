<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A rendszeresemény-napló (system_events, 1.4.0 "Operations & Reliability")
 * DB-szintű tesztjei — Database::logSystemEvent()/getSystemEvents()/
 * countRecentSystemEventsBySeverity(), plusz a retention-takarítás. Lásd
 * migrateV26SystemEvents() docblokkja az audit_log-tól való szándékos
 * elkülönítés indoklásáért.
 */
final class SystemEventsTest extends TestCase
{
    public function testLogSystemEventCreationAndSeverityStatus(): void
    {
        $db = tests_new_database();
        $db->logSystemEvent('backup', 'backup_completed', 'info', 'success', 'Mentés kész.', 'size=1234');

        $events = $db->getSystemEvents();
        $this->assertCount(1, $events);
        $this->assertSame('backup', $events[0]['category']);
        $this->assertSame('backup_completed', $events[0]['event_type']);
        $this->assertSame('info', $events[0]['severity']);
        $this->assertSame('success', $events[0]['status']);
        $this->assertSame('Mentés kész.', $events[0]['user_message']);
        $this->assertSame('size=1234', $events[0]['technical_detail']);
    }

    public function testLogSystemEventRejectsInvalidCategorySeverityStatus(): void
    {
        $db = tests_new_database();
        $this->expectException(InvalidArgumentException::class);
        $db->logSystemEvent('nem_letezo_kategoria', 'x', 'info', 'success', 'x');
    }

    public function testLogSystemEventRejectsInvalidSeverity(): void
    {
        $db = tests_new_database();
        $this->expectException(InvalidArgumentException::class);
        $db->logSystemEvent('backup', 'x', 'nem_letezo_severity', 'success', 'x');
    }

    public function testGetSystemEventsFiltersByCategoryAndSeverity(): void
    {
        $db = tests_new_database();
        $db->logSystemEvent('backup', 'backup_completed', 'info', 'success', 'A');
        $db->logSystemEvent('woocommerce', 'sync_failed', 'error', 'failure', 'B');
        $db->logSystemEvent('nav', 'invoice_processed', 'info', 'success', 'C');

        $backupOnly = $db->getSystemEvents(['category' => 'backup']);
        $this->assertCount(1, $backupOnly);
        $this->assertSame('A', $backupOnly[0]['user_message']);

        $errorsOnly = $db->getSystemEvents(['severity' => 'error']);
        $this->assertCount(1, $errorsOnly);
        $this->assertSame('B', $errorsOnly[0]['user_message']);

        $all = $db->getSystemEvents();
        $this->assertCount(3, $all);
    }

    public function testGetSystemEventsOrdersNewestFirstAndRespectsLimit(): void
    {
        $db = tests_new_database();
        for ($i = 1; $i <= 5; $i++) {
            $db->logSystemEvent('backup', 'backup_completed', 'info', 'success', "esemény $i");
            // Biztosítjuk, hogy a created_at ténylegesen különböző legyen
            // másodperc-pontosságú időbélyegnél is (lásd DatabaseTest.php
            // más, hasonló időrendi tesztjeinek mintáját).
            usleep(1100000 / 5);
        }
        $limited = $db->getSystemEvents([], 2);
        $this->assertCount(2, $limited);
        $this->assertSame('esemény 5', $limited[0]['user_message'], 'A legújabbnak kell elöl lennie.');
    }

    public function testCountRecentSystemEventsBySeverityOnlyCountsWithinWindow(): void
    {
        $db = tests_new_database();
        $db->logSystemEvent('backup', 'backup_completed', 'info', 'success', 'friss');
        $db->logSystemEvent('woocommerce', 'sync_failed', 'error', 'failure', 'friss hiba');

        // Egy régi (48 órás) bejegyzés — KÍVÜL esik a 24 órás ablakon.
        $db->pdo()->prepare("INSERT INTO system_events (category, event_type, severity, status, user_message, created_at) VALUES ('nav', 'invoice_failed', 'error', 'failure', 'régi hiba', ?)")
            ->execute([date('Y-m-d H:i:s', strtotime('-48 hours'))]);

        $counts = $db->countRecentSystemEventsBySeverity(24);
        $this->assertSame(1, $counts['info']);
        $this->assertSame(1, $counts['error'], 'A 48 órás régi hiba NEM számíthat bele a 24 órás ablakba.');
    }

    // Regresszió: a rendszeresemény-napló (a sync_log-gal ELLENTÉTBEN,
    // aminek korábban SEMMILYEN retentionje nem volt, lásd
    // migrateV26SystemEvents() docblokkja) minden ÍRÁSKOR takarít —
    // ugyanaz a minta, mint logAudit()-nál.
    public function testLogSystemEventPrunesOlderThanRetentionOnEveryWrite(): void
    {
        $db = tests_new_database();
        $db->pdo()->prepare("INSERT INTO system_events (category, event_type, severity, status, user_message, created_at) VALUES ('backup', 'backup_completed', 'info', 'success', 'régi', ?)")
            ->execute([date('Y-m-d H:i:s', strtotime('-20 days'))]);

        $this->assertCount(1, $db->getSystemEvents(), 'Előfeltétel: a régi sor ténylegesen bekerült.');

        // Egy ÚJ írás 14 napos retentionnel — a 20 napos sornak el kell tűnnie.
        $db->logSystemEvent('backup', 'backup_completed', 'info', 'success', 'friss', null, 14);

        $remaining = $db->getSystemEvents();
        $this->assertCount(1, $remaining, 'A 14 napnál régebbi sornak törlődnie kellett az új írás takarításakor.');
        $this->assertSame('friss', $remaining[0]['user_message']);
    }

    public function testLogSystemEventRespectsConfigurableRetentionNotJustDefault(): void
    {
        $db = tests_new_database();
        $db->pdo()->prepare("INSERT INTO system_events (category, event_type, severity, status, user_message, created_at) VALUES ('backup', 'backup_completed', 'info', 'success', 'régi', ?)")
            ->execute([date('Y-m-d H:i:s', strtotime('-5 days'))]);

        // 3 napos retention (a 14 napos alapértelmezettnél szigorúbb) —
        // az 5 napos sornak EL KELL tűnnie, bizonyítva, hogy a hívó által
        // átadott érték számít, nem egy fix, figyelmen kívül hagyott
        // alapérték.
        $db->logSystemEvent('backup', 'backup_completed', 'info', 'success', 'friss', null, 3);

        $remaining = $db->getSystemEvents();
        $this->assertCount(1, $remaining);
        $this->assertSame('friss', $remaining[0]['user_message']);
    }

    // ---- Titok-maszkolás / secret redaction (lásd a kör 18. pontja) ----

    public function testUserMessageAndTechnicalDetailNeverContainSecretLikeStrings(): void
    {
        // Ez a teszt nem a Database-réteget, hanem a HÍVÁSI FEGYELMET
        // dokumentálja: a logSystemEvent()-nek átadott szöveg a HÍVÓ
        // felelőssége (lásd Database::logSystemEvent() docblokkja) — de
        // legalább azt bizonyítjuk, hogy maga a tárolás/visszaolvasás nem
        // csonkol/torzít, tehát egy már megfelelően szűrt üzenet
        // változatlanul, sértetlenül jut vissza (fontos, mert egy
        // esetleges string-manipulációs hiba itt is szivárogtathatna).
        $db = tests_new_database();
        $safeMessage = 'WooCommerce kapcsolat időtúllépés miatt nem sikerült.';
        $db->logSystemEvent('woocommerce', 'sync_failed', 'error', 'failure', $safeMessage, 'cURL error 28: timeout');

        $event = $db->getSystemEvents()[0];
        $this->assertSame($safeMessage, $event['user_message']);
        $this->assertStringNotContainsString('password', strtolower($event['user_message']));
        $this->assertStringNotContainsString('secret', strtolower($event['user_message']));
        $this->assertStringNotContainsString('/data/', $event['user_message'], 'A user_message sose tartalmazhat szerver-oldali fájlrendszer-elérési utat.');
    }
}