<?php

require_once __DIR__ . '/NavInvoiceProvider.php';

/**
 * A NAV invoice queue tényleges "worker" mechanikája — claim, backoff-
 * időzítés, max attempts, és a Database-állapotátmenetek alkalmazása. A
 * NAV-specifikus DÖNTÉSEKET (retryable-e egy hiba, mit jelent egy
 * queryTransactionStatus-válasz) a NavInvoiceProvider hozza meg — ez az
 * osztály csak a queue-mechanikát végzi, hogy a NAV-üzleti logika
 * önmagában (NavInvoiceProviderTest) is tesztelhető maradjon a
 * queue-mechanikától (NavInvoiceQueueWorkerTest) függetlenül.
 *
 * Három, egymástól független feldolgozási kör van (lásd
 * webroot/api/nav-queue-run.php, ami mindhármat meghívja egy cron-
 * futásban):
 *   - processDueSubmissions(): 'queued' sorok → manageInvoice.
 *   - processDueStatusChecks(): 'submitted' sorok → queryTransactionStatus.
 *   - processDueUncertainRecovery(): 'uncertain' sorok → queryTransactionList.
 */
class NavInvoiceQueueWorker
{
    /**
     * Exponenciális backoff-ütemezés másodpercben (1p/5p/15p/30p/1ó/3ó/
     * 6ó/12ó/24ó) — a Phase 1-4 tervben már rögzített, a felhasználó
     * Phase 5B kérése által is megerősített ütemezés. Ez 9 db RETRY-
     * késleltetést jelent az 1. próbálkozás UTÁN (attempts 1-9 mindegyike
     * a saját sorindexének megfelelő késleltetéssel újraütemeződik) — ha
     * a 10. próbálkozás (attempts=10, a 9. backoff-késleltetés UTÁN) IS
     * sikertelen, a sor 'dead_letter'-re vált (a backoff-ütemezés
     * kimerült, admin kézi újrapróbálkozása szükséges, lásd
     * Database::resetInvoiceForManualRetry()).
     */
    private const BACKOFF_SECONDS = [60, 300, 900, 1800, 3600, 10800, 21600, 43200, 86400];

    /**
     * Egy 'processing' zár ennyi másodpercig tekinthető még legitimnek —
     * utána "elavultnak" (stale) számít és újra claim-elhető. A NAV saját
     * specifikációja szerint a szinkron hívások abszolút időkorlátja 60
     * másodperc (lásd NavClient::defaultHttpPost() docblockja) — a 600
     * másodperces (10 perces) küszöb ennek tízszerese, bőséges
     * tartalékkal a lassú/visszapróbálkozó feldolgozásra, miközben egy
     * ténylegesen összeomlott worker zárja nem marad óriási ideig
     * beragadva. A felhasználó kérése 5-15 perces ablakot javasolt — 10
     * perc ennek középértéke.
     */
    private const STALE_LOCK_SECONDS = 600;

    /**
     * Egy 'submitted' sor státusz-ellenőrzése ilyen gyakran esedékes —
     * NEM retry-backoff, hanem egy sikeresen beküldött, még feldolgozás
     * alatt álló számla rendszeres pollozása. A NAV tipikus válaszideje
     * <200ms, de a TÉNYLEGES üzleti feldolgozás (a manageInvoice mögötti
     * validáció) másodperceket vehet igénybe — 60 másodperces pollozási
     * ütem elegendően gyors ahhoz, hogy a felhasználó ne várjon feleslegesen
     * sokáig egy admin-nézetben, anélkül hogy feleslegesen terhelné a NAV-ot.
     */
    private const STATUS_CHECK_INTERVAL_SECONDS = 60;

    /**
     * Egy 'uncertain' sor queryTransactionList-alapú egyeztetése ilyen
     * gyakran esedékes, MAX 3 alkalommal (lásd
     * MAX_UNCERTAIN_RECOVERY_ATTEMPTS) — a NAV insDate-alapú indexelése
     * nem feltétlenül azonnali, ezért néhány perces késleltetés
     * indokolt az első próbálkozás előtt/között is.
     */
    private const UNCERTAIN_RECOVERY_INTERVAL_SECONDS = 120;
    private const MAX_UNCERTAIN_RECOVERY_ATTEMPTS = 3;

    private Database $db;
    private NavInvoiceProvider $provider;

    public function __construct(Database $db, NavInvoiceProvider $provider)
    {
        $this->db = $db;
        $this->provider = $provider;
    }

    /**
     * @return array{claimed:int, submitted:int, retried:int, permanent_failures:int, dead_letters:int, uncertain:int}
     */
    public function processDueSubmissions(int $limit = 10): array
    {
        $summary = ['claimed' => 0, 'submitted' => 0, 'retried' => 0, 'permanent_failures' => 0, 'dead_letters' => 0, 'uncertain' => 0];

        for ($i = 0; $i < $limit; $i++) {
            $row = $this->db->claimQueuedInvoiceForSubmission('nav', self::STALE_LOCK_SECONDS);
            if ($row === null) {
                break;
            }
            $summary['claimed']++;

            $result = $this->provider->submit($row);
            switch ($result['outcome']) {
                case 'submitted':
                    $this->db->markInvoiceSubmitted(
                        (int) $row['id'],
                        (string) $result['transaction_id'],
                        date('Y-m-d H:i:s', time() + self::STATUS_CHECK_INTERVAL_SECONDS)
                    );
                    $summary['submitted']++;
                    break;

                case 'uncertain':
                    $this->db->markInvoiceUncertain(
                        (int) $row['id'],
                        (string) $result['error'],
                        date('Y-m-d H:i:s', time() + self::UNCERTAIN_RECOVERY_INTERVAL_SECONDS),
                        0
                    );
                    $summary['uncertain']++;
                    break;

                case 'permanent':
                    $this->db->markInvoiceFailed((int) $row['id'], (string) $result['error']);
                    $summary['permanent_failures']++;
                    break;

                case 'retry':
                default:
                    $this->applyRetryOrDeadLetter($row, (string) ($result['error'] ?? 'Ismeretlen hiba.'), $summary);
                    break;
            }
        }

        return $summary;
    }

    /**
     * @return array{claimed:int, done:int, failed:int, still_pending:int, retried:int}
     */
    public function processDueStatusChecks(int $limit = 10): array
    {
        $summary = ['claimed' => 0, 'done' => 0, 'failed' => 0, 'still_pending' => 0, 'retried' => 0];

        for ($i = 0; $i < $limit; $i++) {
            $row = $this->db->claimSubmittedInvoiceForStatusCheck('nav', self::STALE_LOCK_SECONDS);
            if ($row === null) {
                break;
            }
            $summary['claimed']++;

            $result = $this->provider->checkStatus($row);
            switch ($result['outcome']) {
                case 'done':
                    $this->db->markInvoiceDone((int) $row['id'], date('Y-m-d H:i:s'));
                    $summary['done']++;
                    break;

                case 'failed':
                    $this->db->markInvoiceFailed((int) $row['id'], (string) ($result['error'] ?? 'A NAV elutasította a számlát.'));
                    $summary['failed']++;
                    break;

                case 'pending':
                    // Vissza 'submitted'-re, a következő pollozási ablakra —
                    // a transactionId-t és attempts-et NEM érinti, csak a
                    // zárat oldja fel és tolja ki a következő ellenőrzés
                    // időpontját.
                    $this->db->markInvoiceSubmitted(
                        (int) $row['id'],
                        (string) $row['provider_ref'],
                        date('Y-m-d H:i:s', time() + self::STATUS_CHECK_INTERVAL_SECONDS)
                    );
                    $summary['still_pending']++;
                    break;

                case 'permanent':
                    // P1-1 javítás: egy VÉGLEGES (nem-újrapróbálandó) hiba a
                    // queryTransactionStatus-hívás SORÁN (pl. időközben
                    // visszavont/érvénytelenített NAV hitelesítő adat) korábban
                    // csendben visszakerült 'submitted'-re, a last_error-t
                    // NULL-ra törölve — a számla ezután egy teljesen
                    // egészséges, feldolgozás alatt álló számlától
                    // megkülönböztethetetlennek TŰNT, miközben valójában
                    // véglegesen elakadt, örökké pollozva, admin számára
                    // láthatatlanul. Terminális 'failed'-re vált, a last_error
                    // MEGMARAD (diagnosztikai kontextus), admin kézi
                    // újrapróbálkozással indíthatja újra (lásd
                    // resetInvoiceForManualRetry()).
                    $this->db->markInvoiceFailed((int) $row['id'], (string) ($result['error'] ?? 'A NAV állapot-lekérdezése végleges hibát adott vissza.'));
                    $summary['failed']++;
                    break;

                case 'retry':
                default:
                    // Egy queryTransactionStatus-hívás ÁTMENETI hibája
                    // (hálózat/timeout/5xx) sose vezet 'uncertain'-re (nincs
                    // mellékhatása) — vissza 'submitted'-re, rövid
                    // késleltetéssel újra esedékes.
                    $this->db->markInvoiceSubmitted(
                        (int) $row['id'],
                        (string) $row['provider_ref'],
                        date('Y-m-d H:i:s', time() + self::STATUS_CHECK_INTERVAL_SECONDS)
                    );
                    $summary['retried']++;
                    break;
            }
        }

        return $summary;
    }

    /**
     * @return array{claimed:int, recovered:int, still_uncertain:int, gave_up:int}
     */
    public function processDueUncertainRecovery(int $limit = 10): array
    {
        $summary = ['claimed' => 0, 'recovered' => 0, 'still_uncertain' => 0, 'gave_up' => 0];

        for ($i = 0; $i < $limit; $i++) {
            $row = $this->db->claimUncertainInvoiceForRecovery('nav', self::STALE_LOCK_SECONDS);
            if ($row === null) {
                break;
            }
            $summary['claimed']++;

            $result = $this->provider->recoverUncertain($row);
            if ($result['outcome'] === 'submitted') {
                $this->db->markInvoiceSubmitted(
                    (int) $row['id'],
                    (string) $result['transaction_id'],
                    date('Y-m-d H:i:s', time() + self::STATUS_CHECK_INTERVAL_SECONDS)
                );
                $summary['recovered']++;
                continue;
            }

            $attempts = (int) $row['attempts'] + 1;
            if ($attempts >= self::MAX_UNCERTAIN_RECOVERY_ATTEMPTS) {
                // A bounded egyeztetési kísérletek kimerültek — innentől
                // csakis admin-kezdeményezett kézi feloldás (lásd
                // Database::resetInvoiceForManualRetry()), SOSE automatikus
                // vak manageInvoice-újraküldés. VALÓDI terminális állapotba
                // kerül ('uncertain_manual'), NEM 'uncertain'-be NULL
                // next_attempt_at-tal — az utóbbi a claim-lekérdezés
                // szerint "azonnal esedékes"-nek számított, ami végtelen,
                // öngerjesztő újra-feldolgozáshoz vezetett (P0-3, lásd
                // Database::markInvoiceUncertainManual() docblockja).
                $this->db->markInvoiceUncertainManual((int) $row['id'], (string) ($result['error'] ?? ''), $attempts);
                $summary['gave_up']++;
                continue;
            }

            $this->db->markInvoiceUncertain(
                (int) $row['id'],
                (string) ($result['error'] ?? ''),
                date('Y-m-d H:i:s', time() + self::UNCERTAIN_RECOVERY_INTERVAL_SECONDS),
                $attempts
            );
            $summary['still_uncertain']++;
        }

        return $summary;
    }

    private function applyRetryOrDeadLetter(array $row, string $error, array &$summary): void
    {
        $attempts = (int) $row['attempts'] + 1;

        if ($attempts > count(self::BACKOFF_SECONDS)) {
            $this->db->markInvoiceDeadLetter((int) $row['id'], $error);
            $summary['dead_letters']++;
            return;
        }

        $delay = self::BACKOFF_SECONDS[$attempts - 1];
        $this->db->scheduleInvoiceRetry((int) $row['id'], $error, date('Y-m-d H:i:s', time() + $delay), $attempts);
        $summary['retried']++;
    }
}
