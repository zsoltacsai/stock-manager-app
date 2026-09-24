<?php

declare(strict_types=1);

require_once __DIR__ . '/ExecutableActionStrategy.php';
require_once __DIR__ . '/ActionExecutionStaleException.php';
require_once __DIR__ . '/ActionProposalService.php';
require_once __DIR__ . '/Executors/ReorderDraftExecutor.php';

/**
 * Fázis 8B — Validated Action Execution. Ez az EGYETLEN hely, ahol egy
 * JÓVÁHAGYOTT `ActionProposal` ténylegesen egy üzleti mutációvá válhat.
 *
 * KRITIKUS BIZTONSÁGI SZABÁLY (a kör bevezető szakasza): az LLM SOSE
 * hajthat végre üzleti műveletet közvetlenül — ez az osztály
 * DETERMINISZTIKUS, SOSE hív AI-providert/LLM-et, SOSE fogad el
 * tetszőleges paramétert a hívótól a javaslat ID-ján kívül (lásd
 * webroot/api/ai-action-proposal-execute.php — KIZÁRÓLAG `id`-t fogad
 * el a böngészőtől, minden más érték a szerveren, ebből az osztályból
 * ÉS a végrehajtási stratégiából származik).
 *
 * WHITELIST (a kör 3. pontja): `EXECUTABLE_TYPES` egy STATIKUS,
 * KÓDBA ÉGETETT `match`-leképezés — SOSE dinamikus osztálynév-feloldás
 * felhasználói/tárolt string alapján. `inventory_review`/`sales_review`
 * SOSE válik végrehajthatóvá — ez a lista NEM bővíthető futásidőben.
 *
 * ÁLLAPOTGÉP (lásd ActionProposal.php docblokkja):
 *   approved → (claim) → executing → (siker) → executed
 *                                  → (üzleti állapot elavult) → stale
 *                                  → (technikai hiba) → execution_failed
 *   execution_failed → (újrapróbálkozás, UGYANÚGY execute()-tal) → executing → ...
 *
 * IDEMPOTENCIA (a kör 11. pontja): a claim ÉS a tényleges mutáció+
 * állapotváltás UGYANABBAN a tranzakcióban történik (lásd execute()) —
 * vagy MINDKETTŐ commitolódik, vagy EGYIK SEM. Egy már 'executed'
 * javaslatra a MEGLÉVŐ, perzisztált eredményt adja vissza, SOSE fut le
 * újra a mutáció.
 */
final class ActionExecutor
{
    /**
     * SZIGORÚ, statikus whitelist — a kör 3. pontja: "reorder_draft
     * should be the preferred executable type"; `inventory_review`/
     * `sales_review` SZÁNDÉKOSAN NINCS itt, informatív marad.
     */
    public const EXECUTABLE_TYPES = ['reorder_draft'];

    private const MAX_ERROR_LENGTH = 300;
    private const MAX_RESULT_JSON_LENGTH = 1000;

    /**
     * Egy 'executing' sor ENNYI percnél régebbi állapota "elakadtnak"
     * (pl. a folyamat a claim után összeomlott) számít, és újra
     * lefoglalható — lásd Database::claimActionProposalExecution()
     * docblokkja. UGYANAZ a minta/konvenció, mint AiDailyIntelligence::
     * DEFAULT_STALE_RUNNING_MINUTES.
     */
    public const DEFAULT_STALE_EXECUTING_MINUTES = 30;

    public function __construct(
        private readonly Database $db,
        private readonly array $appSettings,
    ) {
    }

    public static function isExecutableType(string $proposalType): bool
    {
        return in_array($proposalType, self::EXECUTABLE_TYPES, true);
    }

    /**
     * @return array{ok:bool, status?:string, reason?:string, result?:array<string,mixed>, error?:string, already_executed?:bool}
     */
    public function execute(int $proposalId, ?int $staffId): array
    {
        $proposal = $this->db->getActionProposal($proposalId);
        if ($proposal === null) {
            return ['ok' => false, 'reason' => 'not_found'];
        }

        if (!self::isExecutableType((string) $proposal['proposal_type'])) {
            $this->audit($proposalId, $staffId, 'execution_rejected', 'warning', 'failure', 'Nem végrehajtható javaslat-típus.');
            return ['ok' => false, 'reason' => 'not_executable'];
        }

        $this->audit($proposalId, $staffId, 'execution_requested', 'info', 'started', 'Végrehajtás kérve.');

        // Idempotens rövidzár — a kör 11. pontja ("detect completed
        // execution... return the existing result"): egy MÁR végrehajtott
        // javaslatra SOSE fut le újra a mutáció, csak a MÁR perzisztált
        // eredményt adjuk vissza.
        if ($proposal['status'] === 'executed') {
            return ['ok' => true, 'status' => 'executed', 'result' => $this->decodeResult($proposal), 'already_executed' => true];
        }

        // Atomi claim — MINDKÉT induló állapotból: 'approved' (első
        // végrehajtás) VAGY 'execution_failed' (újrapróbálkozás, lásd az
        // osztály docblokkja — a tranzakciós minta miatt egy sikertelen
        // kísérlet SOSE hagy félkész mutációt, tehát az újrapróbálkozás
        // MINDIG biztonságos). A WHERE-feltétel (Database::
        // claimActionProposalExecution()) az EGYETLEN döntési pont — ez
        // a metódushívás SOSE egy megelőző SELECT-re épít.
        $staleExecutingAfterMinutes = max(5, (int) ($this->appSettings['ai_action_execution_stale_minutes'] ?? self::DEFAULT_STALE_EXECUTING_MINUTES));
        $claimed = $this->db->claimActionProposalExecution($proposalId, ['approved', 'execution_failed'], $staleExecutingAfterMinutes);
        if (!$claimed) {
            $fresh = $this->db->getActionProposal($proposalId);
            $status = $fresh['status'] ?? 'unknown';
            if ($status === 'executed') {
                return ['ok' => true, 'status' => 'executed', 'result' => $this->decodeResult($fresh), 'already_executed' => true];
            }
            if ($status === 'executing') {
                $this->audit($proposalId, $staffId, 'concurrent_execution', 'info', 'failure', 'Egy másik kérés már végrehajtás alatt tartja ezt a javaslatot.');
                return ['ok' => false, 'reason' => 'already_executing'];
            }
            // pending/rejected/expired/stale — nem jóváhagyott, nem
            // végrehajtható innen.
            return ['ok' => false, 'reason' => $status];
        }

        $this->audit($proposalId, $staffId, 'execution_started', 'info', 'started', 'Végrehajtás elindult.');

        // ÚJRA lekérdezve — MOST már status='executing', ÉS ez a friss
        // olvasás adja a revalidáláshoz szükséges alapot is (a kör 6.
        // pontja).
        $proposal = $this->db->getActionProposal($proposalId);
        $proposalService = new ActionProposalService($this->db, $this->appSettings);
        if ($proposalService->isStale($proposal)) {
            $this->db->markActionProposalExecutionStale($proposalId);
            $this->audit($proposalId, $staffId, 'stale', 'info', 'failure', 'A javaslat alapjául szolgáló adat időközben megváltozott.');
            return ['ok' => false, 'reason' => 'stale'];
        }

        $strategy = $this->strategyFor((string) $proposal['proposal_type']);

        $this->db->beginTransaction();
        try {
            $result = $strategy->execute($this->db, $proposal);
            $resultJson = mb_substr((string) json_encode($result, JSON_UNESCAPED_UNICODE), 0, self::MAX_RESULT_JSON_LENGTH);
            $this->db->finalizeActionProposalExecution($proposalId, $resultJson);
            $this->db->commit();
        } catch (ActionExecutionStaleException $e) {
            $this->db->rollBack();
            $this->db->markActionProposalExecutionStale($proposalId);
            $this->audit($proposalId, $staffId, 'stale', 'info', 'failure', 'A javaslat a végrehajtás közben elavulttá vált: ' . self::truncate($e->getMessage()));
            return ['ok' => false, 'reason' => 'stale'];
        } catch (Throwable $e) {
            $this->db->rollBack();
            $boundedError = self::truncate($e->getMessage());
            // A NYERS kivétel-üzenet SOSE kerül a kliens felé (lásd a
            // kör 16/23. pontja: "no stack traces") — a DB-be (diagnosz-
            // tikai célra) ÉS a szerver-naplóba MEGY a bounded üzenet,
            // a kliens felé csak egy generikus, biztonságos szöveg.
            $this->db->failActionProposalExecution($proposalId, $boundedError);
            error_log('[fountaintrade] Action execution sikertelen (proposal #' . $proposalId . '): ' . get_class($e) . ': ' . $e->getMessage());
            $this->audit($proposalId, $staffId, 'execution_failed', 'warning', 'failure', 'Végrehajtás sikertelen: ' . $boundedError);
            return ['ok' => false, 'reason' => 'execution_failed', 'error' => 'A végrehajtás sikertelen. Próbáld újra, vagy értesítsd az üzemeltetőt.'];
        }

        $this->audit($proposalId, $staffId, 'execution_succeeded', 'info', 'success', 'Végrehajtás sikeres.');
        return ['ok' => true, 'status' => 'executed', 'result' => $result];
    }

    /**
     * Statikus, kódba égetett leképezés — SOSE dinamikus osztálynév
     * (lásd az osztály docblokkja). Csak self::EXECUTABLE_TYPES-ban
     * szereplő típusra hívható (a hívó, execute(), már ellenőrizte).
     */
    private function strategyFor(string $proposalType): ExecutableActionStrategy
    {
        return match ($proposalType) {
            'reorder_draft' => new ReorderDraftExecutor($this->appSettings),
        };
    }

    /** @return array<string,mixed> */
    private function decodeResult(array $proposal): array
    {
        if (empty($proposal['execution_result_json'])) {
            return [];
        }
        $decoded = json_decode((string) $proposal['execution_result_json'], true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function truncate(string $value): string
    {
        return mb_strlen($value) > self::MAX_ERROR_LENGTH ? mb_substr($value, 0, self::MAX_ERROR_LENGTH) . '…' : $value;
    }

    /**
     * A kör 16. pontja — teljes életciklus-audit, UGYANAZON a két
     * MEGLÉVŐ mechanizmuson keresztül, mint AiAuditLogger/
     * ActionProposalService (logAudit + logSystemEvent) — nincs
     * harmadik naplózó réteg.
     */
    private function audit(int $proposalId, ?int $staffId, string $eventType, string $severity, string $status, string $message): void
    {
        try {
            $this->db->logSystemEvent(
                'ai',
                'action_' . $eventType,
                $severity,
                $status,
                $message,
                json_encode(['proposal_id' => $proposalId], JSON_UNESCAPED_UNICODE),
                (int) ($this->appSettings['system_events_retention_days'] ?? 14)
            );
        } catch (Throwable $e) {
            error_log('[fountaintrade] Action execution logSystemEvent sikertelen: ' . $e->getMessage());
        }

        if (in_array($eventType, ['execution_succeeded', 'execution_failed', 'stale', 'concurrent_execution'], true)) {
            try {
                $this->db->logAudit(
                    $staffId,
                    'ai_action_proposal_execute',
                    'ai_action_proposal',
                    $proposalId,
                    "Végrehajtás — $eventType (#$proposalId): $message",
                    (int) ($this->appSettings['audit_log_retention_days'] ?? 30)
                );
            } catch (Throwable $e) {
                error_log('[fountaintrade] Action execution logAudit sikertelen: ' . $e->getMessage());
            }
        }
    }
}
