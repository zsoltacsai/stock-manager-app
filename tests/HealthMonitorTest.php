<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * A src/HealthMonitor.php DB-független, tisztán a HealthMonitor-logikát
 * ellenőrző tesztjei — lásd 1.4.0 "Operations & Reliability" kör 19.
 * pontja ("minden rendben; részleges hiba; több egyidejű hiba; nincs
 * konfigurálva integráció; nincs adat; stale task; failed queue; backup
 * failure").
 */
final class HealthMonitorTest extends TestCase
{
    private function baseSignals(array $overrides = []): array
    {
        return array_merge([
            'settings' => [],
            'db_ok' => true,
            'woocommerce_configured' => false,
            'wc_queue' => ['counts' => ['queued' => 0, 'processing' => 0, 'done' => 0, 'failed' => 0, 'dead_letter' => 0], 'recent_failed' => []],
            'sync_failures_24h' => 0,
            'nav_configured' => false,
            'nav_queue' => ['provider' => 'nav', 'buckets' => ['done' => 0, 'pending' => 0, 'failed' => 0], 'by_status' => []],
            'invoice_provider' => 'szamlazz',
            'invoice_failures_7d' => 0,
            'draft_webshop_orders' => 0,
            'urgent_purchase_count' => 0,
            'soon_purchase_count' => 0,
            'other_low_purchase_count' => 0,
            'update_state' => ['state' => 'idle', 'current_version' => AppVersion::CURRENT, 'latest_version' => null, 'latest_checked_at' => null, 'last_successful_update_at' => null, 'last_check_error' => null, 'progress_message' => null],
        ], $overrides);
    }

    // ---- Minden rendben ----

    public function testEverythingOkWhenNoIntegrationsConfiguredAndNoIssues(): void
    {
        $components = HealthMonitor::computeComponentStatuses($this->baseSignals());
        $this->assertSame(HealthMonitor::STATUS_OK, $components['database']['status']);
        $this->assertSame(HealthMonitor::STATUS_NOT_CONFIGURED, $components['backup']['status']);
        $this->assertSame(HealthMonitor::STATUS_NOT_CONFIGURED, $components['woocommerce']['status']);
        $this->assertSame(HealthMonitor::STATUS_NOT_CONFIGURED, $components['nav']['status']);
        $this->assertSame(HealthMonitor::STATUS_OK, $components['updater']['status']);

        $overall = HealthMonitor::computeOverallStatus($components);
        $this->assertSame('ok', $overall['level'], 'NOT_CONFIGURED komponensek NEM okozhatnak warning/error összesítést.');

        $attention = HealthMonitor::computeAttentionItems($this->baseSignals());
        $this->assertSame([], $attention);
    }

    public function testEverythingOkWithConfiguredAndHealthyIntegrations(): void
    {
        $signals = $this->baseSignals([
            'settings' => [
                'backup_enabled' => true, 'last_backup_at' => date('c'), 'last_backup_summary' => 'Mentés kész: x.sqlite',
                'auto_sync_enabled' => true, 'last_auto_sync_at' => date('c'), 'last_auto_sync_summary' => '5 termék frissítve',
            ],
            'woocommerce_configured' => true,
        ]);
        $components = HealthMonitor::computeComponentStatuses($signals);
        $this->assertSame(HealthMonitor::STATUS_OK, $components['backup']['status']);
        $this->assertSame(HealthMonitor::STATUS_OK, $components['woocommerce']['status']);
        $this->assertSame('ok', HealthMonitor::computeOverallStatus($components)['level']);
    }

    // ---- Nincs konfigurálva integráció ----

    public function testNotConfiguredIntegrationsAreReportedAsSuchNotAsFalseOk(): void
    {
        $components = HealthMonitor::computeComponentStatuses($this->baseSignals());
        foreach (['backup', 'woocommerce', 'nav', 'szamlazz', 'smtp', 'printer'] as $key) {
            $this->assertSame(HealthMonitor::STATUS_NOT_CONFIGURED, $components[$key]['status'], "$key: nincs beállítva esetén NOT_CONFIGURED legyen, sose hamis OK.");
        }
    }

    // Regresszió: egy VALÓDI, élesben megfigyelt hiba — a WooCommerce
    // integráció "konfigurálva van" ÉS az automata szinkron "ki van
    // kapcsolva" KÉT KÜLÖNBÖZŐ dolog. Egy admin tudatosan kikapcsolhatja
    // az automata pull-szinkront (csak kézi/push szinkront használ)
    // anélkül, hogy ez "nincs beállítva"-t jelentene.
    public function testWooCommerceConfiguredButAutoSyncDisabledIsOkNotNotConfigured(): void
    {
        $signals = $this->baseSignals([
            'settings' => ['auto_sync_enabled' => false],
            'woocommerce_configured' => true,
        ]);
        $component = HealthMonitor::computeComponentStatuses($signals)['woocommerce'];
        $this->assertSame(HealthMonitor::STATUS_OK, $component['status']);
        $this->assertStringContainsString('kikapcsolva', $component['message']);
    }

    // NAV esetén (a WooCommerce-től ELTÉRŐEN) a kikapcsolt queue-cron
    // TÉNYLEGES probléma, ha a NAV az aktív invoice_provider — a
    // README szerint enélkül a számlák örökre 'queued' állapotban
    // maradnak, sose kerülnek beküldésre.
    public function testNavConfiguredButQueueDisabledIsWarningNotNotConfigured(): void
    {
        $signals = $this->baseSignals([
            'settings' => ['nav_queue_enabled' => false],
            'nav_configured' => true,
        ]);
        $component = HealthMonitor::computeComponentStatuses($signals)['nav'];
        $this->assertSame(HealthMonitor::STATUS_WARNING, $component['status']);
    }

    // ---- Nincs adat ----

    public function testEnabledButNeverRunReportsWarningNotFalseOk(): void
    {
        $signals = $this->baseSignals(['settings' => ['backup_enabled' => true, 'last_backup_at' => null]]);
        $component = HealthMonitor::computeComponentStatuses($signals)['backup'];
        $this->assertSame(HealthMonitor::STATUS_WARNING, $component['status']);
        $this->assertStringContainsString('nem futott', $component['message']);
    }

    // ---- Stale task ----

    public function testStaleBackupIsWarningEvenThoughLastRunWasNominallySuccessful(): void
    {
        $signals = $this->baseSignals(['settings' => [
            'backup_enabled' => true,
            'last_backup_at' => date('c', strtotime('-30 hours')), // > 26 órás küszöb
            'last_backup_summary' => 'Mentés kész: x.sqlite',
        ]]);
        $component = HealthMonitor::computeComponentStatuses($signals)['backup'];
        $this->assertSame(HealthMonitor::STATUS_WARNING, $component['status']);
        $this->assertStringContainsString('hosszabb ideje', $component['message']);
    }

    public function testRecentBackupIsNotFlaggedAsStale(): void
    {
        $signals = $this->baseSignals(['settings' => [
            'backup_enabled' => true,
            'last_backup_at' => date('c', strtotime('-2 hours')),
            'last_backup_summary' => 'Mentés kész: x.sqlite',
        ]]);
        $component = HealthMonitor::computeComponentStatuses($signals)['backup'];
        $this->assertSame(HealthMonitor::STATUS_OK, $component['status']);
    }

    // ---- Backup failure ----

    // FONTOS, ÉLESBEN felfedezett részlet: a backup cron a last_backup_at
    // időbélyeget SIKERTELEN futáskor IS frissíti (lásd auto-backup-run.php)
    // — tehát a "friss-e az időbélyeg" önmagában NEM elég, a "Hiba:"
    // előtagot KELL vizsgálni, különben egy tartósan hibázó, de
    // rendszeresen lefutó cron hamis zöld státuszt mutatna.
    public function testFailedBackupIsErrorEvenThoughTimestampIsFresh(): void
    {
        $signals = $this->baseSignals(['settings' => [
            'backup_enabled' => true,
            'last_backup_at' => date('c'), // ÉPP MOST frissült, a hiba ELLENÉRE
            'last_backup_summary' => 'Hiba: A mentési fájl írása sikertelen.',
        ]]);
        $component = HealthMonitor::computeComponentStatuses($signals)['backup'];
        $this->assertSame(HealthMonitor::STATUS_ERROR, $component['status']);
        $this->assertSame('Hiba: A mentési fájl írása sikertelen.', $component['message']);
    }

    // ---- Failed queue ----

    public function testWooCommerceFailedQueueItemsDowngradeOkPullStatusToWarning(): void
    {
        $signals = $this->baseSignals([
            'settings' => ['auto_sync_enabled' => true, 'last_auto_sync_at' => date('c'), 'last_auto_sync_summary' => '5 termék frissítve'],
            'woocommerce_configured' => true,
            'wc_queue' => ['counts' => ['queued' => 1, 'processing' => 0, 'done' => 10, 'failed' => 2, 'dead_letter' => 1], 'recent_failed' => []],
        ]);
        $component = HealthMonitor::computeComponentStatuses($signals)['woocommerce'];
        $this->assertSame(HealthMonitor::STATUS_WARNING, $component['status']);
        $this->assertSame(1, $component['queue_summary']['pending']);
        $this->assertSame(3, $component['queue_summary']['failed'], 'failed + dead_letter összege.');
    }

    public function testSyncLogFailureEscalatesWooCommerceToErrorEvenOverAnOkQueue(): void
    {
        $signals = $this->baseSignals([
            'settings' => ['auto_sync_enabled' => true, 'last_auto_sync_at' => date('c'), 'last_auto_sync_summary' => '5 termék frissítve'],
            'woocommerce_configured' => true,
            'sync_failures_24h' => 3,
        ]);
        $component = HealthMonitor::computeComponentStatuses($signals)['woocommerce'];
        $this->assertSame(HealthMonitor::STATUS_ERROR, $component['status'], 'A sync_log-beli technikai hiba a LEGERŐSEBB jelzés, felülírja az OK queue-állapotot is.');
    }

    public function testNavFailedQueueItemsDowngradeOkStatusToWarning(): void
    {
        $signals = $this->baseSignals([
            'settings' => ['nav_queue_enabled' => true, 'last_nav_queue_run_at' => date('c'), 'last_nav_queue_run_summary' => '2 claim, 2 submitted'],
            'nav_configured' => true,
            'nav_queue' => ['provider' => 'nav', 'buckets' => ['done' => 5, 'pending' => 1, 'failed' => 2], 'by_status' => []],
        ]);
        $component = HealthMonitor::computeComponentStatuses($signals)['nav'];
        $this->assertSame(HealthMonitor::STATUS_WARNING, $component['status']);
        $this->assertSame(1, $component['queue_summary']['pending']);
        $this->assertSame(2, $component['queue_summary']['failed']);
    }

    // ---- Részleges hiba / több egyidejű hiba ----

    public function testPartialFailureProducesWarningOverallNotError(): void
    {
        $signals = $this->baseSignals(['settings' => [
            'backup_enabled' => true,
            'last_backup_at' => date('c', strtotime('-30 hours')),
            'last_backup_summary' => 'Mentés kész: x.sqlite',
        ]]);
        $overall = HealthMonitor::computeOverallStatus(HealthMonitor::computeComponentStatuses($signals));
        $this->assertSame('warning', $overall['level']);
    }

    public function testMultipleSimultaneousFailuresProduceErrorOverall(): void
    {
        $signals = $this->baseSignals([
            'settings' => [
                'backup_enabled' => true, 'last_backup_at' => date('c'), 'last_backup_summary' => 'Hiba: lemez megtelt.',
                'auto_sync_enabled' => true, 'last_auto_sync_at' => date('c'), 'last_auto_sync_summary' => '5 termék frissítve',
            ],
            'woocommerce_configured' => true,
            'sync_failures_24h' => 5,
        ]);
        $components = HealthMonitor::computeComponentStatuses($signals);
        $this->assertSame(HealthMonitor::STATUS_ERROR, $components['backup']['status']);
        $this->assertSame(HealthMonitor::STATUS_ERROR, $components['woocommerce']['status']);
        $overall = HealthMonitor::computeOverallStatus($components);
        $this->assertSame('error', $overall['level'], 'Legalább egy ERROR komponens -> összesített ERROR.');
    }

    // ---- Verzió-összehasonlítás (regresszió — élesben megfigyelt hiba) ----

    // Az update_state.current_version mező KIZÁRÓLAG egy TÉNYLEGES
    // self-update lefutásakor frissül — ha a telepített kód időközben
    // (fejlesztői kézi frissítéssel) újabbra változott, ez a mező elavult
    // maradhat. A TÉNYLEGESEN futó kódhoz (AppVersion::CURRENT) kell
    // hasonlítani, sose a DB-ben cache-elt mezőhöz.
    public function testUpdaterDoesNotFalselyReportUpdateAvailableWhenStoredCurrentVersionIsStale(): void
    {
        $signals = $this->baseSignals([
            'update_state' => [
                'state' => 'idle',
                'current_version' => '1.0.2', // ELAVULT — a valóban futó kód AppVersion::CURRENT
                'latest_version' => '1.3.0',  // RÉGEBBI, mint a ténylegesen futó kód
                'latest_checked_at' => date('c'),
                'last_successful_update_at' => null,
                'last_check_error' => null,
                'progress_message' => null,
            ],
        ]);
        $component = HealthMonitor::computeComponentStatuses($signals)['updater'];
        $this->assertSame(HealthMonitor::STATUS_OK, $component['status'], 'Az elavult stored current_version NEM okozhat hamis "van új verzió" riasztást.');
        $this->assertSame('Naprakész.', $component['message']);
    }

    public function testUpdaterReportsWarningWhenLatestVersionIsGenuinelyNewer(): void
    {
        $futureVersion = implode('.', [
            (int) explode('.', AppVersion::CURRENT)[0],
            (int) explode('.', AppVersion::CURRENT)[1] + 1,
            0,
        ]);
        $signals = $this->baseSignals([
            'update_state' => [
                'state' => 'idle',
                'current_version' => AppVersion::CURRENT,
                'latest_version' => $futureVersion,
                'latest_checked_at' => date('c'),
                'last_successful_update_at' => null,
                'last_check_error' => null,
                'progress_message' => null,
            ],
        ]);
        $component = HealthMonitor::computeComponentStatuses($signals)['updater'];
        $this->assertSame(HealthMonitor::STATUS_WARNING, $component['status']);
        $this->assertStringContainsString($futureVersion, $component['message']);
    }

    public function testUpdaterReportsErrorOnFailedState(): void
    {
        $signals = $this->baseSignals([
            'update_state' => [
                'state' => 'failed', 'current_version' => AppVersion::CURRENT, 'latest_version' => null,
                'latest_checked_at' => date('c'), 'last_successful_update_at' => null,
                'last_check_error' => 'Checksum-eltérés.', 'progress_message' => null,
            ],
        ]);
        $component = HealthMonitor::computeComponentStatuses($signals)['updater'];
        $this->assertSame(HealthMonitor::STATUS_ERROR, $component['status']);
        $this->assertSame('Checksum-eltérés.', $component['message']);
    }

    // ---- Figyelmet igényel — meglévő típusok VÁLTOZATLAN alakja ----
    // (lásd tests/HttpSecurityTest.php testDashNav1/testDashNav2 — ugyanaz
    // a szerződés, itt DB nélkül, tisztán a HealthMonitor-on bizonyítva.)

    public function testAttentionItemsPreserveExactExistingShapeAndTypes(): void
    {
        $signals = $this->baseSignals([
            'urgent_purchase_count' => 2,
            'draft_webshop_orders' => 3,
            'invoice_failures_7d' => 1,
        ]);
        $attention = HealthMonitor::computeAttentionItems($signals);
        $byType = [];
        foreach ($attention as $item) {
            foreach (['type', 'count', 'label', 'link'] as $key) {
                $this->assertArrayHasKey($key, $item);
            }
            $this->assertGreaterThan(0, $item['count'], 'SOSE lehet 0 darabszámú (fiktív) figyelmeztető tétel.');
            $byType[$item['type']] = $item;
        }
        $this->assertSame('beszerzesi-javaslat.php?urgency=urgent', $byType['purchase_urgent']['link']);
        $this->assertSame('beerkezo-eladasok.php', $byType['draft_webshop_orders']['link']);
        $this->assertSame('eladasok.php', $byType['invoice_failed']['link']);
    }

    public function testAttentionItemsIncludeNewOperationalSignalsWithoutDuplicatingLogic(): void
    {
        $signals = $this->baseSignals(['settings' => [
            'backup_enabled' => true,
            'last_backup_at' => date('c'),
            'last_backup_summary' => 'Hiba: lemez megtelt.',
        ]]);
        $attention = HealthMonitor::computeAttentionItems($signals);
        $types = array_column($attention, 'type');
        $this->assertContains('backup_failed', $types);
    }
}