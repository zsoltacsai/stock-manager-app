<?php

require_once __DIR__ . '/NavIncomingInvoiceSync.php';

/**
 * A NAV bejövő-számla sync tényleges "worker" mechanikája — claim,
 * backoff-időzítés, cursor-kezelés, a Database-állapotátmenetek
 * alkalmazása. A NAV-specifikus DÖNTÉSEKET (ablak-darabolás, lapozás,
 * dedup-mentés, hiba-osztályozás) a NavIncomingInvoiceSync hozza meg —
 * pontosan a kimenő NavInvoiceProvider/NavInvoiceQueueWorker
 * szereposztását követve (lásd Phase 6 terv 10-12. pont).
 *
 * Két belépési pont van:
 *   - processDueSync()   — cron hívja (webroot/api/nav-incoming-sync-run.php).
 *   - triggerManualSync() — admin "Számlák frissítése" gombja hívja
 *                            (webroot/api/nav-incoming-sync-trigger.php).
 * Mindkettő UGYANAZT az atomikus claim-et próbálja
 * (Database::claimIncomingInvoiceSync()) — emiatt a manuális és az
 * automatikus sync SOSE futhat egyszerre (a második 'already_running'-ot
 * kap, a hívó endpoint ebből ad 409-et).
 */
class NavIncomingInvoiceSyncWorker
{
    private const PROVIDER = 'nav';

    /**
     * Egy 'running' zár ennyi másodpercig tekinthető még legitimnek. Egy
     * sync-futás akár TÖBB 35-napos ablakot és lapozott NAV-hívást is
     * végezhet egyetlen invocation alatt (lásd $maxWindowsPerRun) — ez
     * hosszabb, mint a kimenő queue egyetlen manageInvoice-hívása, ezért a
     * NavInvoiceQueueWorker::STALE_LOCK_SECONDS (600s) helyett 1800s
     * (30 perc) a tartalék, hogy egy ténylegesen lassú, de élő futást ne
     * szakítson félbe idő előtt egy másik worker-invocation.
     */
    private const STALE_LOCK_SECONDS = 1800;

    /**
     * Ugyanaz a 9-lépéses (1p/5p/15p/30p/1ó/3ó/6ó/12ó/24ó) exponenciális
     * backoff-ütemezés, mint a kimenő NAV queue-nál (lásd
     * NavInvoiceQueueWorker::BACKOFF_SECONDS) — a Phase 6 kérés nem adott
     * indokot egy eltérő ütemezésre, az egységesség a karbantarthatóságot
     * segíti.
     */
    private const BACKOFF_SECONDS = [60, 300, 900, 1800, 3600, 10800, 21600, 43200, 86400];

    /**
     * Ha a `incoming_invoice_sync` sor cursor-ja MÉG SOSE lett beállítva
     * (sem admin-kezdeményezett első sync, sem korábbi sikeres/részleges
     * futás), a CRON worker ezt a konzervatív alapértelmezést használja —
     * ez SAJÁT, dokumentált Stock Manager-döntés (NEM NAV-mező/-előírás),
     * hogy a cron sose induljon el egy admin által még soha nem
     * jóváhagyott, tetszőlegesen nagy kezdő időponttal.
     */
    private const DEFAULT_FIRST_SYNC_DAYS = 7;

    private Database $db;
    private NavIncomingInvoiceSync $sync;

    public function __construct(Database $db, NavIncomingInvoiceSync $sync)
    {
        $this->db = $db;
        $this->sync = $sync;
    }

    /**
     * Cron hívja — CSAK a ténylegesen esedékes syncet futtatja. A
     * claimIncomingInvoiceSync() WHERE-feltétele önmagában NEM nézi a
     * `next_attempt_at`-ot (csak a `status`/`locked_at`-ot) — ha egy sor
     * `retry` állapotban van, de a backoff-ütemezés szerinti következő
     * próbálkozás még a JÖVŐBEN esedékes, ez a metódus a claim ELŐTT
     * kiszűri, hogy a cron ne próbálkozzon idő előtt (a claim-en belüli
     * versenyhelyzet-védelem ettől függetlenül továbbra is fennáll, lásd
     * runClaimedSync()).
     *
     * @return array{outcome:string, ...}
     */
    public function processDueSync(int $maxWindowsPerRun = 3): array
    {
        $state = $this->db->getIncomingInvoiceSyncState(self::PROVIDER);
        if ($state === null) {
            return ['outcome' => 'skipped', 'reason' => 'A beérkező-számla sync állapotsora nem található.'];
        }

        if ($state['status'] === 'retry' && !empty($state['next_attempt_at']) && strtotime($state['next_attempt_at']) > time()) {
            return ['outcome' => 'not_due', 'next_attempt_at' => $state['next_attempt_at']];
        }

        return $this->runClaimedSync($maxWindowsPerRun, null);
    }

    /**
     * Admin-kezdeményezett manuális sync — a backoff-esedékesség
     * vizsgálatot SZÁNDÉKOSAN kihagyja (egy explicit emberi kérést nem
     * indokolt a háttér-ütemezésnek visszatartania), de UGYANAZT az
     * atomikus claim-et próbálja, mint a cron worker — ha már fut egy
     * sync, itt is 'already_running'-ot ad (a hívó endpoint ebből 409-et).
     *
     * @param array{period?:string, date_from?:?string, date_to?:?string} $request
     */
    public function triggerManualSync(array $request, int $maxWindowsPerRun = 3): array
    {
        return $this->runClaimedSync($maxWindowsPerRun, $request);
    }

    private function runClaimedSync(int $maxWindowsPerRun, ?array $manualRequest): array
    {
        if ($manualRequest !== null) {
            $firstSyncFrom = $this->resolveFirstSyncFrom($manualRequest);
            if ($firstSyncFrom !== null) {
                // Csak akkor hat, ha még SOSE futott sync (cursor NULL) —
                // lásd Database::setIncomingInvoiceSyncCursorIfUnset() docblockja.
                $this->db->setIncomingInvoiceSyncCursorIfUnset(self::PROVIDER, $firstSyncFrom);
            }
        }

        $claimed = $this->db->claimIncomingInvoiceSync(self::PROVIDER, self::STALE_LOCK_SECONDS);
        if ($claimed === null) {
            return ['outcome' => 'already_running'];
        }

        $cursorFrom = !empty($claimed['sync_cursor_ins_date'])
            ? $claimed['sync_cursor_ins_date']
            : gmdate('Y-m-d\TH:i:s\Z', time() - self::DEFAULT_FIRST_SYNC_DAYS * 86400);
        $targetTo = gmdate('Y-m-d\TH:i:s\Z');
        $requestedInterval = (string) ($manualRequest['period'] ?? ($claimed['last_requested_interval'] ?: 'incremental'));

        $result = $this->sync->runOnce($cursorFrom, $targetTo, $maxWindowsPerRun);
        $newCursor = $result['new_cursor'] ?? $cursorFrom;

        if ($result['outcome'] === 'success') {
            $this->db->markIncomingInvoiceSyncSuccess(self::PROVIDER, $newCursor, $requestedInterval);
            return [
                'outcome' => 'success',
                'windows_processed' => $result['windows_processed'],
                'has_more' => $result['has_more'],
                'new_cursor' => $newCursor,
            ];
        }

        // Részleges előrehaladás (0 vagy több sikeresen VÉGIGLAPOZOTT
        // ablak) MÁR eltárolva a cursoron keresztül (NavIncomingInvoiceSync::
        // runOnce() minden sikeres ablak után azonnal ír) — itt csak a
        // sync-sor VÉGSŐ állapotát (retry/failed) és a hibaüzenetet
        // állítjuk be, a cursor-t NEM írjuk felül (markIncomingInvoiceSyncRetry/
        // Failed egyike sem érinti a sync_cursor_ins_date mezőt).
        $attempts = (int) $claimed['attempts'] + 1;

        if ($result['outcome'] === 'retry' && $attempts <= count(self::BACKOFF_SECONDS)) {
            $delay = self::BACKOFF_SECONDS[$attempts - 1];
            $this->db->markIncomingInvoiceSyncRetry(self::PROVIDER, (string) $result['error'], date('Y-m-d H:i:s', time() + $delay), $attempts);
            return ['outcome' => 'retry', 'error' => $result['error'], 'attempts' => $attempts, 'windows_processed' => $result['windows_processed']];
        }

        // 'failed' (üzleti/validációs hiba, permanens), VAGY a retry-
        // backoff kimerült — terminális állapot, de a legközelebbi
        // manuális VAGY automatikus próbálkozás továbbra is megpróbálhatja
        // (lásd Database::markIncomingInvoiceSyncFailed() docblockja: a
        // claim WHERE-je bármely nem-'running' státuszt enged).
        $this->db->markIncomingInvoiceSyncFailed(self::PROVIDER, (string) $result['error']);
        return ['outcome' => 'failed', 'error' => $result['error'], 'attempts' => $attempts, 'windows_processed' => $result['windows_processed']];
    }

    /**
     * @param array{period?:string, date_from?:?string, date_to?:?string} $request
     */
    private function resolveFirstSyncFrom(array $request): ?string
    {
        $period = $request['period'] ?? null;
        return match ($period) {
            '7d' => gmdate('Y-m-d\TH:i:s\Z', time() - 7 * 86400),
            '30d' => gmdate('Y-m-d\TH:i:s\Z', time() - 30 * 86400),
            'custom' => $this->parseCustomFrom($request['date_from'] ?? null),
            default => null,
        };
    }

    private function parseCustomFrom(?string $dateFrom): ?string
    {
        if ($dateFrom === null || $dateFrom === '') {
            return null;
        }
        $ts = strtotime($dateFrom);
        return $ts !== false ? gmdate('Y-m-d\TH:i:s\Z', $ts) : null;
    }
}
