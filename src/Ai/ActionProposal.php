<?php

declare(strict_types=1);

/**
 * Fázis 8A — AI Action Proposals + Human Approval. Ez az osztály a
 * javaslat READ-ONLY, típusos alakja (a perzisztencia továbbra is sima
 * tömbként megy a Database rétegen keresztül — ugyanaz a minta, mint az
 * ai_daily_reports/system_events soroknál, lásd Database::
 * getAiDailyReport()/decorateAiHistoryRow()), PLUSZ a teljes életciklus
 * whitelistjeit (típusok/státuszok) ÉS a bounded méretkorlátokat tartja
 * EGY helyen — ezeket az ActionProposalService.php ÉS minden végpont
 * innen olvassa, sose duplikálja.
 *
 * "approved" ÖNMAGÁBAN KIZÁRÓLAG azt jelenti: "egy ember jóváhagyta ezt a
 * javaslatot" — NEM azt, hogy a javasolt üzleti művelet megtörtént. Lásd
 * a Fázis 8A kör 3. pontja.
 *
 * FÁZIS 8B — Validated Action Execution: a STATUSES lista HÁROM ÚJ,
 * VÉGREHAJTÁS-KÖVETŐ állapottal bővült — `executing`/`executed`/
 * `execution_failed` — SZÁNDÉKOSAN a MEGLÉVŐ `status` mezőn, NEM egy
 * külön `execution_status` oszlopon (lásd Database::
 * migrateV32ActionExecution() docblokkja): a Fázis 8B kör 4. pontja
 * explicit tiltja, hogy "approved" jelentse "executed"-et — a
 * jóváhagyás és a végrehajtás ÉS annak eredménye SZEMANTIKAILAG
 * MEGKÜLÖNBÖZTETETT állapotok maradnak:
 *   pending → approved → executing → executed
 *   approved → executing → execution_failed (technikai hiba, retry engedett)
 *   approved → executing → stale (az üzleti állapot időközben megváltozott
 *     — UGYANAZ a jelentés, mint a jóváhagyás-előtti stale, lásd
 *     ActionExecutor.php)
 * A `executed` állapot NEM jelent semmilyen KÜLSŐ (beszállítói) műveletet
 * — lásd ActionExecutor.php/ReorderDraftExecutor.php docblokkja: a
 * végrehajtás KIZÁRÓLAG egy helyi, felülvizsgálható piszkozat-rekordot
 * hoz létre.
 */
final class ActionProposal
{
    /** A kör 6. pontja — a Fázis 8A induló, szándékosan szűk javaslat-típuskészlete. */
    public const TYPES = ['inventory_review', 'reorder_draft', 'sales_review'];

    /**
     * Szigorú whitelist. Fázis 8A: pending/approved/rejected/expired/
     * stale. Fázis 8B: + executing/executed/execution_failed (lásd az
     * osztály docblokkja) — a végrehajtható típusok tényleges
     * whitelistjét lásd ActionExecutor::EXECUTABLE_TYPES.
     */
    public const STATUSES = ['pending', 'approved', 'rejected', 'expired', 'stale', 'executing', 'executed', 'execution_failed'];

    /**
     * Melyik szerver-oldali, bizalmi komponens hozhat létre javaslatot
     * (a kör 7. pontja: "Proposal creation must be restricted to trusted
     * server-side AI/business code" — SOSE nyers böngésző-bemenet). A
     * Fázis 8A EGYETLEN létrehozási útvonala az AiDailyIntelligence (lásd
     * README "Ismert korlátok" — a manuális, egyedi-termékes "Javaslat
     * készítése" UI-gomb a kör 23. pontja szerint OPCIONÁLIS, ebben a
     * körben nem került megvalósításra) — a whitelist szándékosan csak
     * ténylegesen elérhető forrást tartalmaz.
     */
    public const AGENTS = ['daily_intelligence'];

    public const MAX_ENTITY_NAME_LENGTH = 191;
    public const MAX_REJECTION_REASON_LENGTH = 500;
    public const MAX_EVIDENCE_JSON_LENGTH = 2000;
    public const MAX_PROPOSAL_JSON_LENGTH = 1000;

    /** A kör 13. pontja — alapértelmezett élettartam, KÖZPONTOSÍTVA (lásd Settings::DEFAULTS['ai_action_proposal_ttl_hours']). */
    public const DEFAULT_TTL_HOURS = 48;

    private function __construct(
        public readonly int $id,
        public readonly string $proposalType,
        public readonly string $status,
        public readonly string $agent,
        public readonly ?string $provider,
        public readonly ?string $model,
        public readonly ?int $sourceRunId,
        public readonly string $entityType,
        public readonly int $entityId,
        public readonly ?string $entityName,
        public readonly array $evidence,
        public readonly array $proposedAction,
        public readonly string $fingerprint,
        public readonly string $createdAt,
        public readonly string $updatedAt,
        public readonly string $expiresAt,
        public readonly ?string $reviewedAt,
        public readonly ?int $reviewedBy,
        public readonly ?string $rejectionReason,
        public readonly ?string $executionStartedAt,
        public readonly ?string $executedAt,
        public readonly ?string $executionFailedAt,
        public readonly array $executionResult,
        public readonly ?string $executionError,
    ) {
    }

    /** @param array<string,mixed> $row a Database::getActionProposal()/listActionProposals() nyers sora */
    public static function fromRow(array $row): self
    {
        $evidence = [];
        if (!empty($row['evidence_json'])) {
            $decoded = json_decode((string) $row['evidence_json'], true);
            $evidence = is_array($decoded) ? $decoded : [];
        }
        $proposedAction = [];
        if (!empty($row['proposal_json'])) {
            $decoded = json_decode((string) $row['proposal_json'], true);
            $proposedAction = is_array($decoded) ? $decoded : [];
        }
        $executionResult = [];
        if (!empty($row['execution_result_json'])) {
            $decoded = json_decode((string) $row['execution_result_json'], true);
            $executionResult = is_array($decoded) ? $decoded : [];
        }

        return new self(
            (int) $row['id'],
            (string) $row['proposal_type'],
            (string) $row['status'],
            (string) $row['agent'],
            $row['provider'] ?? null,
            $row['model'] ?? null,
            isset($row['source_run_id']) ? (int) $row['source_run_id'] : null,
            (string) $row['entity_type'],
            (int) $row['entity_id'],
            $row['entity_name'] ?? null,
            $evidence,
            $proposedAction,
            (string) $row['fingerprint'],
            (string) $row['created_at'],
            (string) $row['updated_at'],
            (string) $row['expires_at'],
            $row['reviewed_at'] ?? null,
            isset($row['reviewed_by']) ? (int) $row['reviewed_by'] : null,
            $row['rejection_reason'] ?? null,
            $row['execution_started_at'] ?? null,
            $row['executed_at'] ?? null,
            $row['execution_failed_at'] ?? null,
            $executionResult,
            $row['execution_error'] ?? null,
        );
    }

    /**
     * Biztonságos, kliens felé küldhető alak — lásd a kör 2. pontja
     * ("Do NOT store API keys/CSRF tokens/HMAC secrets/unlimited raw
     * model output"): ez az osztály eleve sose tárol ilyet, ez a metódus
     * csak a mezőneveket rendezi a végpontok számára egységesen.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'proposal_type' => $this->proposalType,
            'status' => $this->status,
            'agent' => $this->agent,
            'provider' => $this->provider,
            'model' => $this->model,
            'source_run_id' => $this->sourceRunId,
            'entity_type' => $this->entityType,
            'entity_id' => $this->entityId,
            'entity_name' => $this->entityName,
            'evidence' => $this->evidence,
            'proposed_action' => $this->proposedAction,
            'created_at' => $this->createdAt,
            'updated_at' => $this->updatedAt,
            'expires_at' => $this->expiresAt,
            'reviewed_at' => $this->reviewedAt,
            'reviewed_by' => $this->reviewedBy,
            'rejection_reason' => $this->rejectionReason,
            'execution_started_at' => $this->executionStartedAt,
            'executed_at' => $this->executedAt,
            'execution_failed_at' => $this->executionFailedAt,
            'execution_result' => $this->executionResult,
            'execution_error' => $this->executionError,
        ];
    }
}
