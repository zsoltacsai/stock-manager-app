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
 * KRITIKUS: a STATUSES listában SZÁNDÉKOSAN NINCS 'executed' állapot —
 * ez a fázis a javaslat JÓVÁHAGYÁSÁVAL/ELUTASÍTÁSÁVAL ér véget, SOSE hajt
 * végre semmilyen tényleges üzleti műveletet (készlet-, ár-, rendelés-,
 * kassza-, vevő- vagy számlaváltoztatást). "approved" ITT KIZÁRÓLAG azt
 * jelenti: "egy ember jóváhagyta ezt a javaslatot" — NEM azt, hogy "a
 * javasolt üzleti művelet megtörtént". Lásd a kör 3. pontja.
 */
final class ActionProposal
{
    /** A kör 6. pontja — a Fázis 8A induló, szándékosan szűk javaslat-típuskészlete. */
    public const TYPES = ['inventory_review', 'reorder_draft', 'sales_review'];

    /** A kör 3. pontja — szigorú whitelist, NINCS 'executed'. */
    public const STATUSES = ['pending', 'approved', 'rejected', 'expired', 'stale'];

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
        ];
    }
}
