<?php

require_once __DIR__ . '/AppVersion.php';

/**
 * 1.4.0 — "Operations & Reliability". EGYETLEN, központi állapot-
 * összesítő (lásd a kör 11. pontja: "egy rendszer → egy igazságforrás
 * arról, hogy van-e aktuális probléma") — a Dashboard "Figyelmet
 * igényel" blokkja, a Dashboard kompakt "Rendszer állapota" widgetje
 * ÉS a teljes Rendszerállapot oldal MIND ugyanezt a két metódust hívja
 * (computeAttentionItems() / computeComponentStatuses()), nem
 * párhuzamos, egymástól eltérő "van-e probléma" logikákat.
 *
 * Szándékosan PurchaseDecisionService mintáját követi: tiszta,
 * DB-független statikus metódusok, amik már lekérdezett primitíveket
 * (Database::get*StatusSummary()/Settings::read() eredményeket) kapnak
 * paraméterként — SOSE maguk futtatnak lekérdezést. Ez teszi
 * egyszerűen tesztelhetővé adatbázis nélkül, ÉS ez garantálja, hogy a
 * hívó (dashboard-summary.php / system-health.php) pontosan egyszer,
 * bulk lekérdezésekkel gyűjti be az adatot (lásd a kör 16. pontja,
 * "ne legyen N+1 / teljes event-log betöltés csak a Dashboard miatt").
 */
final class HealthMonitor
{
    public const STATUS_OK = 'ok';
    public const STATUS_WARNING = 'warning';
    public const STATUS_ERROR = 'error';
    public const STATUS_NOT_CONFIGURED = 'not_configured';
    public const STATUS_UNKNOWN = 'unknown';

    // Cadence-alapú "elakadt" küszöbök (másodperc) — MINDEGYIK a saját
    // dokumentált cron-gyakoriságához igazítva (README "Automatikus
    // feladatok" / install.txt §8), NEM egyetlen globális timeout (lásd
    // a kör 7. pontja). Kb. 3x-os szorzó a normál ingadozás/lassú
    // cron-tick elnyelésére, hogy ne legyen hamis riasztás.
    private const WC_QUEUE_STALE_SECONDS = 600;        // dokumentált cadence: percenként
    private const NAV_QUEUE_STALE_SECONDS = 600;       // dokumentált cadence: percenként
    private const NAV_INCOMING_STALE_SECONDS = 10800;  // dokumentált cadence: óránként (3 óra)
    private const BACKUP_STALE_SECONDS = 93600;        // napi mentés + kb. 2 óra puffer (26 óra)
    private const UPDATE_CHECK_STALE_MULTIPLIER = 3;   // × update_check_interval_hours (konfigurálható)

    /**
     * A cron-végpontok (auto-backup-run.php, auto-sync-run.php,
     * wc-queue-run.php, nav-queue-run.php, nav-incoming-sync-run.php)
     * MIND ugyanazt a konvenciót követik: sikertelen futáskor a mentett
     * summary-string "Hiba:" előtaggal kezdődik. FONTOS: ezek a
     * végpontok a `last_X_at` időbélyeget SIKERTELEN futáskor IS
     * frissítik (lásd auto-backup-run.php) — tehát a "friss-e az
     * időbélyeg" önmagában NEM elég a sikeresség eldöntéséhez, a
     * summary-t IS meg kell nézni. Enélkül egy tartósan hibázó (de
     * rendszeresen lefutó) cron hamis zöld státuszt mutatna.
     */
    private static function isCronSummaryFailure(?string $summary): bool
    {
        return $summary !== null && str_starts_with($summary, 'Hiba:');
    }

    private static function secondsSince(?string $isoOrDb): ?int
    {
        if (!$isoOrDb) {
            return null;
        }
        $ts = strtotime($isoOrDb);
        if ($ts === false) {
            return null;
        }
        return max(0, time() - $ts);
    }

    /**
     * Egyetlen cron-alapú komponens (backup/WC pull/WC queue/NAV queue/
     * NAV incoming) állapota a settings.json last_X_at/last_X_summary
     * párosából. `$enabled=false` esetén `$disabledStatus` (alapból
     * NOT_CONFIGURED — ez SOSE hamis zöld, lásd a kör 4. pontja).
     *
     * FONTOS, ÉLESBEN TALÁLT hiba javítása: a WooCommerce/NAV hívóknál
     * `$enabled` az AUTOMATA cron-kapcsoló (auto_sync_enabled/
     * nav_queue_enabled), NEM az "van-e egyáltalán beállítva az
     * integráció" kérdés — AZT a hívó KÜLÖN, előre ellenőrzi (lásd
     * computeComponentStatuses() `woocommerce_configured`/`nav_configured`
     * kapuja), mielőtt egyáltalán meghívná ezt a metódust. Egy admin
     * TUDATOSAN kikapcsolhatja az automata szinkront (pl. csak kézi/push
     * szinkront használ) anélkül, hogy ez "nincs beállítva" jelentene —
     * ezért ott a hívó STATUS_OK-t ad át `$disabledStatus`-ként, NEM
     * NOT_CONFIGURED-et. Egyedül a backup-nál marad NOT_CONFIGURED az
     * alapértelmezés, mert ott nincs külön "van-e konfigurálva" kapu —
     * a `backup_enabled` MAGA az egyetlen "aktív-e egyáltalán" jelzés.
     */
    private static function cronComponentStatus(bool $enabled, ?string $lastAt, ?string $lastSummary, int $staleSeconds, string $disabledStatus = self::STATUS_NOT_CONFIGURED, string $disabledMessage = 'Nincs bekapcsolva.'): array
    {
        if (!$enabled) {
            return ['status' => $disabledStatus, 'last_checked_at' => null, 'last_success_at' => null, 'message' => $disabledMessage];
        }
        if ($lastAt === null) {
            return ['status' => self::STATUS_WARNING, 'last_checked_at' => null, 'last_success_at' => null, 'message' => 'Még nem futott le.'];
        }

        $age = self::secondsSince($lastAt);
        $failed = self::isCronSummaryFailure($lastSummary);

        if ($failed) {
            return [
                'status' => self::STATUS_ERROR,
                'last_checked_at' => $lastAt,
                'last_success_at' => null,
                'message' => $lastSummary,
            ];
        }
        if ($age !== null && $age > $staleSeconds) {
            $minutes = intdiv($age, 60);
            return [
                'status' => self::STATUS_WARNING,
                'last_checked_at' => $lastAt,
                'last_success_at' => $lastAt,
                'message' => "A feladat hosszabb ideje ($minutes perce) nem jelentkezett.",
            ];
        }
        return ['status' => self::STATUS_OK, 'last_checked_at' => $lastAt, 'last_success_at' => $lastAt, 'message' => $lastSummary ?? 'Rendben.'];
    }

    /**
     * @param array $signals Minden kulcs KÖTELEZŐ, de a hívó (dashboard-
     *   summary.php / system-health.php) állítja össze már meglévő,
     *   egyszeri lekérdezésekből — lásd a két hívó fájl docblockját a
     *   pontos forrásért.
     */
    public static function computeComponentStatuses(array $signals): array
    {
        $s = $signals['settings'];
        $components = [];

        // Adatbázis — az egyetlen komponens, ami MINDIG "konfigurált" (a
        // hívó egy triviális SELECT 1-et futtat le előtte és adja át az
        // eredményt, hogy a HealthMonitor maga DB-független maradjon).
        $components['database'] = [
            'status' => $signals['db_ok'] ? self::STATUS_OK : self::STATUS_ERROR,
            'last_checked_at' => date('c'),
            'last_success_at' => $signals['db_ok'] ? date('c') : null,
            'message' => $signals['db_ok'] ? 'Rendben.' : 'Az adatbázis nem érhető el.',
        ];

        $components['backup'] = self::cronComponentStatus(
            !empty($s['backup_enabled']),
            $s['last_backup_at'] ?? null,
            $s['last_backup_summary'] ?? null,
            self::BACKUP_STALE_SECONDS
        );

        if (!empty($signals['woocommerce_configured'])) {
            $wcQueue = $signals['wc_queue'];
            $wcFailed = $wcQueue['counts']['failed'] + $wcQueue['counts']['dead_letter'];
            $pullStatus = self::cronComponentStatus(
                !empty($s['auto_sync_enabled']),
                $s['last_auto_sync_at'] ?? null,
                $s['last_auto_sync_summary'] ?? null,
                max(self::WC_QUEUE_STALE_SECONDS, 3 * 60 * (int) ($s['auto_sync_interval_minutes'] ?? 15)),
                self::STATUS_OK,
                'Beállítva, az automatikus szinkron kikapcsolva (kézi/push szinkron aktív lehet).'
            );
            // A push-queue állapota (van-e elakadt/sikertelen elem) FELÜLÍRJA
            // a pull-cron önmagában OK állapotát — egy néma pull mellett is
            // lehet felhalmozódott, sikertelen kiküldés.
            if ($wcFailed > 0 && $pullStatus['status'] === self::STATUS_OK) {
                $pullStatus['status'] = self::STATUS_WARNING;
                $pullStatus['message'] = "$wcFailed sikertelen készlet-kiküldés a várólistán.";
            }
            // A sync_log-beli tényleges hiba (pl. webhook/push technikai
            // hiba, lásd Database::countRecentSyncFailures()) a LEGERŐSEBB
            // jelzés — ez volt a korábbi dashboard-summary.php
            // $systemStatus-ának is az elsődleges "error" jelzése, ezt itt
            // megtartjuk, hogy a komponens-alapú összesítő ne gyengítse le.
            if (($signals['sync_failures_24h'] ?? 0) > 0) {
                $pullStatus['status'] = self::STATUS_ERROR;
                $pullStatus['message'] = $signals['sync_failures_24h'] . ' technikai szinkron-hiba az elmúlt 24 órában.';
            }
            // 1.4.0 — explicit queue-összesítő mező (lásd a kör 10. pontja:
            // "WooCommerce queue: 0 pending / 0 failed") — a fenti
            // $pullStatus['message'] csak AKKOR említi a queue-t, ha van
            // benne hiba; ez a mező MINDIG jelen van, függetlenül attól.
            $pullStatus['queue_summary'] = [
                'pending' => $wcQueue['counts']['queued'] + $wcQueue['counts']['processing'],
                'failed'  => $wcFailed,
                'link'    => 'woocommerce-sync.php',
            ];
            $components['woocommerce'] = $pullStatus;
        } else {
            $components['woocommerce'] = ['status' => self::STATUS_NOT_CONFIGURED, 'last_checked_at' => null, 'last_success_at' => null, 'message' => 'Nincs beállítva.'];
        }

        if (!empty($signals['nav_configured'])) {
            $navQueue = $signals['nav_queue'];
            // NAV esetén (a WooCommerce-től ELTÉRŐEN) a kikapcsolt cron
            // TÉNYLEGESEN probléma, ha a NAV az aktív invoice_provider
            // (ide csak akkor jutunk el, lásd `nav_configured` kapu fent)
            // — README: enélkül a kiállított NAV-számlák örökre 'queued'
            // állapotban maradnak, SOSE kerülnek ténylegesen beküldésre.
            // Ezért itt WARNING marad az alapértelmezés (nem OK, mint a
            // WC pull-cronnál), csak a hívó-üzenetet pontosítjuk.
            $queueStatus = self::cronComponentStatus(
                !empty($s['nav_queue_enabled']),
                $s['last_nav_queue_run_at'] ?? null,
                $s['last_nav_queue_run_summary'] ?? null,
                self::NAV_QUEUE_STALE_SECONDS,
                self::STATUS_WARNING,
                'A NAV számla-várólista háttér-feldolgozása KI van kapcsolva — a kiállított számlák beküldés nélkül maradnának.'
            );
            if ($navQueue['buckets']['failed'] > 0 && $queueStatus['status'] === self::STATUS_OK) {
                $queueStatus['status'] = self::STATUS_WARNING;
                $queueStatus['message'] = $navQueue['buckets']['failed'] . ' sikertelen/bizonytalan NAV-számla a várólistán.';
            }
            $queueStatus['queue_summary'] = [
                'pending' => $navQueue['buckets']['pending'],
                'failed'  => $navQueue['buckets']['failed'],
                'link'    => 'kimeno-szamlak.php',
            ];
            $components['nav'] = $queueStatus;
        } else {
            $components['nav'] = ['status' => self::STATUS_NOT_CONFIGURED, 'last_checked_at' => null, 'last_success_at' => null, 'message' => 'Nincs beállítva.'];
        }

        // Számlázz.hu — SZÁNDÉKOSAN nincs "Kapcsolat tesztelése" gomb (lásd
        // a kör 9. pontja: "ne hozzon létre számlát") — a Számlázz.hu Agent
        // API-nak nincs dokumentált, dokumentum-létrehozás nélküli
        // kapcsolat-teszt művelete, ezért itt UNKNOWN marad (nem hamis OK),
        // az üzenet pedig ezt a korlátot explicit jelzi, nem egy nem-létező
        // tesztelési lehetőséget ígér.
        $components['szamlazz'] = empty($s['szamlazz_agent_key'])
            ? ['status' => self::STATUS_NOT_CONFIGURED, 'last_checked_at' => null, 'last_success_at' => null, 'message' => 'Nincs beállítva.']
            : ['status' => self::STATUS_UNKNOWN, 'last_checked_at' => null, 'last_success_at' => null, 'message' => 'Agent kulcs beállítva — a Számlázz.hu API-nak nincs számla-kiállítás nélküli kapcsolat-tesztje, a tényleges működés az első valódi számlázáskor derül ki.'];

        $components['smtp'] = empty($s['smtp_host'])
            ? ['status' => self::STATUS_NOT_CONFIGURED, 'last_checked_at' => null, 'last_success_at' => null, 'message' => 'Nincs beállítva.']
            : self::manualTestComponentStatus($s['last_smtp_test_at'] ?? null, $s['last_smtp_test_status'] ?? null, $s['last_smtp_test_message'] ?? null);

        $components['printer'] = empty($s['printer_enabled']) || empty($s['printer_ip'])
            ? ['status' => self::STATUS_NOT_CONFIGURED, 'last_checked_at' => null, 'last_success_at' => null, 'message' => 'Nincs beállítva.']
            : self::manualTestComponentStatus($s['last_printer_test_at'] ?? null, $s['last_printer_test_status'] ?? null, $s['last_printer_test_message'] ?? null);

        $updateState = $signals['update_state'];
        $components['updater'] = self::updaterComponentStatus($s, $updateState);

        return $components;
    }

    /**
     * Nyomtató/SMTP/NAV — nincs ambiens (háttérben futó) ellenőrzés,
     * csak admin által kézzel indított teszt (lásd a kör 9. pontja).
     * Ha még SOSE volt teszt: UNKNOWN, NEM hamis OK.
     */
    private static function manualTestComponentStatus(?string $lastAt, ?string $lastStatus, ?string $lastMessage): array
    {
        if ($lastAt === null) {
            return ['status' => self::STATUS_UNKNOWN, 'last_checked_at' => null, 'last_success_at' => null, 'message' => 'Még nem lett tesztelve.'];
        }
        $status = $lastStatus === 'success' ? self::STATUS_OK : self::STATUS_ERROR;
        return [
            'status' => $status,
            'last_checked_at' => $lastAt,
            'last_success_at' => $lastStatus === 'success' ? $lastAt : null,
            'message' => $lastMessage ?? ($status === self::STATUS_OK ? 'Rendben.' : 'Sikertelen teszt.'),
        ];
    }

    private static function updaterComponentStatus(array $s, ?array $updateState): array
    {
        if ($updateState === null) {
            return ['status' => self::STATUS_UNKNOWN, 'last_checked_at' => null, 'last_success_at' => null, 'message' => 'Ismeretlen állapot.'];
        }
        $state = $updateState['state'] ?? 'idle';
        $lastCheckedAt = $updateState['latest_checked_at'] ?? null;
        $lastSuccessAt = $updateState['last_successful_update_at'] ?? null;

        if (in_array($state, ['failed', 'rolled_back', 'manual_recovery_required'], true)) {
            return [
                'status' => self::STATUS_ERROR,
                'last_checked_at' => $lastCheckedAt,
                'last_success_at' => $lastSuccessAt,
                'message' => $updateState['last_check_error'] ?? $updateState['progress_message'] ?? 'A frissítés sikertelen volt.',
            ];
        }
        // FONTOS, ÉLESBEN IS MEGFIGYELT eset: nem elég az egyenlőtlenség-
        // vizsgálat (latest_version !== current_version), ÉS nem is
        // elég az update_state.current_version mezőt használni — az
        // KIZÁRÓLAG egy TÉNYLEGES self-update lefutásakor frissül,
        // tehát tetszőlegesen ELAVULT maradhat, ha a telepített kód
        // időközben (fejlesztői kézi frissítéssel, ami nem az
        // UpdateInstalleren át történt) újabbra változott — ilyenkor a
        // stored current_version egy RÉGI verziót mutatna, és egy RÉGEBBI
        // latest_version is "újnak" tűnne hozzá képest, holott a
        // TÉNYLEGESEN futó kód (AppVersion::CURRENT) már túl van rajta.
        // A ténylegesen futó kódhoz kell hasonlítani, sose a DB-ben
        // cache-elt (potenciálisan elavult) mezőhöz.
        if (
            !empty($updateState['latest_version'])
            && AppVersion::isValidSemver($updateState['latest_version'])
            && AppVersion::compare($updateState['latest_version'], AppVersion::CURRENT) > 0
        ) {
            return [
                'status' => self::STATUS_WARNING,
                'last_checked_at' => $lastCheckedAt,
                'last_success_at' => $lastSuccessAt,
                'message' => 'Új verzió érhető el: ' . $updateState['latest_version'],
            ];
        }
        if (!empty($s['update_auto_check_enabled']) && $lastCheckedAt !== null) {
            $intervalHours = max(1, (int) ($s['update_check_interval_hours'] ?? 24));
            $age = self::secondsSince($lastCheckedAt);
            if ($age !== null && $age > $intervalHours * 3600 * self::UPDATE_CHECK_STALE_MULTIPLIER) {
                return [
                    'status' => self::STATUS_WARNING,
                    'last_checked_at' => $lastCheckedAt,
                    'last_success_at' => $lastSuccessAt,
                    'message' => 'A frissítés-ellenőrzés hosszabb ideje nem futott le.',
                ];
            }
        }
        return ['status' => self::STATUS_OK, 'last_checked_at' => $lastCheckedAt, 'last_success_at' => $lastSuccessAt, 'message' => 'Naprakész.'];
    }

    /**
     * A Dashboard "Figyelmet igényel" blokkjának EGYETLEN forrása — a
     * korábbi (1.2.0-1.3.0) dashboard-summary.php-beli, helyben épített
     * $attention tömböt ez váltja fel, KIBŐVÍTVE az új, operatív
     * jelzésekkel (backup_stale/backup_failed/updater_issue), de a
     * MEGLÉVŐ elem-alakot (type/count/label/link) és a MEGLÉVŐ típusokat
     * (purchase_urgent/soon/low, draft_webshop_orders, invoice_failed,
     * wc_failed) VÁLTOZATLANUL megtartva — lásd
     * tests/HttpSecurityTest.php testDashNav1/testDashNav2, amik erre
     * a pontos alakra/típusra épülnek.
     */
    public static function computeAttentionItems(array $signals): array
    {
        $attention = [];
        $s = $signals['settings'];

        if ($signals['urgent_purchase_count'] > 0) {
            $attention[] = [
                'type' => 'purchase_urgent', 'count' => $signals['urgent_purchase_count'],
                'label' => $signals['urgent_purchase_count'] . ' sürgősen beszerzendő termék',
                'link' => 'beszerzesi-javaslat.php?urgency=urgent',
            ];
        }
        if ($signals['soon_purchase_count'] > 0) {
            $attention[] = [
                'type' => 'purchase_soon', 'count' => $signals['soon_purchase_count'],
                'label' => $signals['soon_purchase_count'] . ' termék ' . PurchaseDecisionService::SOON_DAYS_THRESHOLD . ' napon belül várhatóan elfogy',
                'link' => 'beszerzesi-javaslat.php?urgency=soon',
            ];
        }
        if ($signals['other_low_purchase_count'] > 0) {
            $attention[] = [
                'type' => 'purchase_low', 'count' => $signals['other_low_purchase_count'],
                'label' => $signals['other_low_purchase_count'] . ' további termék alacsony készleten',
                'link' => 'beszerzesi-javaslat.php?urgency=low',
            ];
        }
        if ($signals['draft_webshop_orders'] > 0) {
            $attention[] = [
                'type' => 'draft_webshop_orders', 'count' => $signals['draft_webshop_orders'],
                'label' => $signals['draft_webshop_orders'] . ' webshop rendelés várakozik',
                'link' => 'beerkezo-eladasok.php',
            ];
        }
        if ($signals['invoice_provider'] === 'nav' && $signals['nav_queue']['buckets']['failed'] > 0) {
            $attention[] = [
                'type' => 'invoice_failed', 'count' => $signals['nav_queue']['buckets']['failed'],
                'label' => $signals['nav_queue']['buckets']['failed'] . ' sikertelen NAV-számla',
                'link' => 'kimeno-szamlak.php',
            ];
        } elseif ($signals['invoice_provider'] !== 'nav' && $signals['invoice_failures_7d'] > 0) {
            $attention[] = [
                'type' => 'invoice_failed', 'count' => $signals['invoice_failures_7d'],
                'label' => $signals['invoice_failures_7d'] . ' sikertelen számla (7 nap)',
                'link' => 'eladasok.php',
            ];
        }
        $wcFailedTotal = $signals['wc_queue']['counts']['failed'] + $signals['wc_queue']['counts']['dead_letter'];
        if ($wcFailedTotal > 0) {
            $attention[] = [
                'type' => 'wc_failed', 'count' => $wcFailedTotal,
                'label' => $wcFailedTotal . ' sikertelen WooCommerce szinkron',
                'link' => 'woocommerce-sync.php',
            ];
        }

        // ÚJ (1.4.0): operatív jelzések — ugyanabból a komponens-
        // összesítőből (computeComponentStatuses()) származnak, hogy ne
        // legyen KÉT külön "van-e probléma" logika (lásd a kör 11. pontja).
        $components = self::computeComponentStatuses($signals);
        if ($components['backup']['status'] === self::STATUS_ERROR) {
            $attention[] = ['type' => 'backup_failed', 'count' => 1, 'label' => 'Az utolsó biztonsági mentés sikertelen volt', 'link' => 'rendszerallapot.php'];
        } elseif ($components['backup']['status'] === self::STATUS_WARNING && !empty($s['backup_enabled'])) {
            $attention[] = ['type' => 'backup_stale', 'count' => 1, 'label' => 'A biztonsági mentés hosszabb ideje nem futott le', 'link' => 'rendszerallapot.php'];
        }
        if ($components['updater']['status'] === self::STATUS_ERROR) {
            $attention[] = ['type' => 'updater_issue', 'count' => 1, 'label' => 'A frissítési rendszer beavatkozást igényel', 'link' => 'rendszerallapot.php'];
        }
        foreach (['woocommerce', 'nav'] as $key) {
            if ($components[$key]['status'] === self::STATUS_WARNING && strpos($components[$key]['message'], 'hosszabb ideje') !== false) {
                $attention[] = ['type' => $key . '_stale', 'count' => 1, 'label' => ucfirst($key) . ' szinkron hosszabb ideje nem jelentkezett', 'link' => 'rendszerallapot.php'];
            }
        }

        return $attention;
    }

    /**
     * A Dashboard tetejének 3-állapotú (ok/warning/error) jelzője — a
     * KOMPONENS-állapotokból vezetve le (nem egy harmadik, külön
     * logikából), hogy a Dashboard és a Rendszerállapot oldal SOSE
     * mondhasson ellent egymásnak.
     */
    public static function computeOverallStatus(array $componentStatuses): array
    {
        $hasError = false;
        $hasWarning = false;
        foreach ($componentStatuses as $c) {
            if ($c['status'] === self::STATUS_ERROR) {
                $hasError = true;
            } elseif ($c['status'] === self::STATUS_WARNING) {
                $hasWarning = true;
            }
        }
        if ($hasError) {
            return ['level' => 'error', 'label' => 'Hiba'];
        }
        if ($hasWarning) {
            return ['level' => 'warning', 'label' => 'Figyelmet igényel'];
        }
        return ['level' => 'ok', 'label' => 'Minden rendszer működik'];
    }
}