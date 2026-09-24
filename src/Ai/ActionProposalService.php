<?php

declare(strict_types=1);

require_once __DIR__ . '/ActionProposal.php';

/**
 * Fázis 8A — AI Action Proposals + Human Approval. Ez az EGYETLEN hely,
 * ahol egy javaslat létrejöhet/érvényesíthető/jóváhagyható/elutasítható —
 * a kör 8. pontja explicit felsorolja a felelősségeket, a kör 7. pontja
 * pedig kifejezetten tiltja, hogy egy böngésző-kérés közvetlenül,
 * tetszőleges típussal/entitással/evidence-szel hozzon létre javaslatot:
 * EZ a réteg a bizalmi határ — minden mező itt VALIDÁLT/DETERMINISZTIKUS
 * forrásból (AnomalyTools/AnomalyDetector eredménye + friss `products`
 * lekérdezés) származik, SOSE a hívó (LLM VAGY böngésző) által küldött
 * nyers számokból (lásd a kör 9. pontja).
 *
 * NEM hajt végre üzleti műveletet (készlet/ár/rendelés/kassza/vevő/
 * számla) — SOSE, egyetlen metódusa sem. Lásd ActionProposal.php
 * docblokkja a pontos állapotgépért.
 */
final class ActionProposalService
{
    /**
     * A kör 24. pontja — "Use a centralized rule... Keep eligibility
     * deterministic. Do not let the LLM decide whether a proposal should
     * exist." Ez a leképezés az EGYETLEN hely, ahol eldől, mely
     * AnomalyDetector-találat típusból milyen javaslat-típus válhat —
     * SOSE a modell dönti el. `sales_spike` és `return_rate_anomaly`
     * SZÁNDÉKOSAN nincs itt: egy eladás-megugrás/visszáru-arány
     * anomáliának nincs a Fázis 8A három típusa közül egyértelmű,
     * termék-szintű operatív követő lépése (a visszáru-arány ráadásul
     * bolt-szintű, entity_id=0, nincs mihez revalidálni — lásd isStale()).
     */
    private const ELIGIBLE_FINDING_TYPES = [
        'sales_decline' => 'sales_review',
        'low_stock_elevated_sales' => 'reorder_draft',
        'stock_sales_divergence' => 'inventory_review',
        'slow_moving_stock' => 'inventory_review',
    ];

    /** A kör 24. pontja — "high/critical severity". */
    private const ELIGIBLE_SEVERITIES = ['critical', 'high'];

    /**
     * A fingerprint része (lásd computeFingerprint()) — SZÁNDÉKOSAN
     * KONSTANS, mert az AiDailyIntelligence/AnomalyTools jelenleg
     * egységesen 'last_30_days' ablakot vizsgál minden anomália-
     * ellenőrzéshez (lásd AiDailyIntelligence::gatherContext()) — ha ez a
     * jövőben konfigurálhatóvá válik, EZ az egyetlen hely, amit módosítani
     * kell.
     */
    private const PERIOD_LABEL = 'last_30_days';

    public function __construct(
        private readonly Database $db,
        private readonly array $appSettings,
    ) {
    }

    /**
     * A kör 24. pontja — determinisztikus jogosultsági döntés, hogy egy
     * MÁR megállapított (AnomalyDetector) anomália-rekordból egyáltalán
     * SZABAD-e javaslatot készíteni. Csak termék-szintű (entity_type
     * 'product') találatokra — a jelenlegi három javaslat-típus mindegyike
     * egy KONKRÉT termékre vonatkozó operatív felülvizsgálatot javasol.
     */
    public function isEligibleFinding(array $finding): bool
    {
        if (($finding['entity_type'] ?? '') !== 'product') {
            return false;
        }
        if (!isset(self::ELIGIBLE_FINDING_TYPES[$finding['type'] ?? ''])) {
            return false;
        }
        return in_array($finding['severity'] ?? '', self::ELIGIBLE_SEVERITIES, true);
    }

    public function proposalTypeForFinding(array $finding): ?string
    {
        return self::ELIGIBLE_FINDING_TYPES[$finding['type'] ?? ''] ?? null;
    }

    /**
     * Egyetlen, MÁR determinisztikusan megállapított AnomalyDetector-
     * találatból (lásd AnomalyDetector::buildRecord() a pontos alakért)
     * próbál javaslatot létrehozni. Nem dob kivételt jogosulatlan/
     * duplikált esetben — egyszerűen null-t ad vissza, a hívó (pl.
     * AiDailyIntelligence) ezt csendben, no-op-ként kezeli (a kör 24.
     * pontja: "Do not make every finding a proposal").
     *
     * @param array<string,mixed> $finding AnomalyDetector::buildRecord() alakja
     * @return array<string,mixed>|null a létrejött javaslat sora (Database::getActionProposal() alakja), vagy null
     */
    public function createFromFinding(
        array $finding,
        string $agent,
        ?string $provider,
        ?string $model,
        ?int $sourceRunId
    ): ?array {
        if (!in_array($agent, ActionProposal::AGENTS, true)) {
            throw new InvalidArgumentException("Érvénytelen javaslat-forrás agent: $agent");
        }
        if (!$this->isEligibleFinding($finding)) {
            return null;
        }
        $proposalType = $this->proposalTypeForFinding($finding);
        if ($proposalType === null) {
            return null;
        }

        $entityId = (int) $finding['entity_id'];
        // A HITELES, jelenlegi termékadat — SOSE a finding-ből esetlegesen
        // származó (már a lekérdezés pillanatában is másodkézből jövő)
        // névre/állapotra hagyatkozunk, lásd a kör 9. pontja.
        $product = $this->db->findProductById($entityId);
        if ($product === null) {
            // A termék időközben törölve — nincs mihez javaslatot kötni.
            return null;
        }

        $reasonCode = (string) ($finding['reason_code'] ?? '');
        $fingerprint = $this->computeFingerprint($proposalType, 'product', $entityId, $reasonCode);
        $entityName = mb_substr((string) ($product['name'] ?? $finding['entity_name'] ?? ''), 0, ActionProposal::MAX_ENTITY_NAME_LENGTH);

        $evidence = [
            'product_id' => $entityId,
            'product_name' => $entityName,
            'current_stock' => (int) ($product['stock_qty'] ?? 0),
            'metric' => $finding['metric'] ?? null,
            'current_value' => $finding['current_value'] ?? null,
            'baseline_value' => $finding['baseline_value'] ?? null,
            'change_percent' => $finding['change_percent'] ?? null,
            'severity' => $finding['severity'],
            'reason_code' => $reasonCode,
        ];
        $proposedAction = $this->describeProposedAction($proposalType, $entityName);

        $ttlHours = max(1, (int) ($this->appSettings['ai_action_proposal_ttl_hours'] ?? ActionProposal::DEFAULT_TTL_HOURS));
        $now = date('Y-m-d H:i:s');
        $expiresAt = date('Y-m-d H:i:s', strtotime("+{$ttlHours} hours", strtotime($now)));

        $evidenceJson = mb_substr((string) json_encode($evidence, JSON_UNESCAPED_UNICODE), 0, ActionProposal::MAX_EVIDENCE_JSON_LENGTH);
        $proposalJson = mb_substr((string) json_encode($proposedAction, JSON_UNESCAPED_UNICODE), 0, ActionProposal::MAX_PROPOSAL_JSON_LENGTH);

        $id = $this->db->createActionProposal([
            'proposal_type' => $proposalType,
            'agent' => $agent,
            'provider' => $provider,
            'model' => $model,
            'source_run_id' => $sourceRunId,
            'entity_type' => 'product',
            'entity_id' => $entityId,
            'entity_name' => $entityName,
            'evidence_json' => $evidenceJson,
            'proposal_json' => $proposalJson,
            'fingerprint' => $fingerprint,
            'created_at' => $now,
            'updated_at' => $now,
            'expires_at' => $expiresAt,
        ]);
        if ($id === null) {
            // Duplikátum — MÁR létezik javaslat erre a fingerprintre,
            // lásd Database::createActionProposal() docblokkja.
            return null;
        }

        try {
            $this->db->logSystemEvent(
                'ai',
                'action_proposal_created',
                'info',
                'success',
                'Új AI-javaslat létrehozva.',
                json_encode([
                    'proposal_id' => $id,
                    'proposal_type' => $proposalType,
                    'agent' => $agent,
                    'entity_type' => 'product',
                    'entity_id' => $entityId,
                    'fingerprint' => $fingerprint,
                ], JSON_UNESCAPED_UNICODE),
                (int) ($this->appSettings['system_events_retention_days'] ?? 14)
            );
        } catch (Throwable $e) {
            error_log('[fountaintrade] Action proposal logSystemEvent sikertelen: ' . $e->getMessage());
        }

        return $this->db->getActionProposal($id);
    }

    /**
     * Determinisztikus, EMBER-olvasható javaslat-szöveg — SOSE az LLM
     * generálja (a kör 9. pontja: "The LLM may help explain the
     * proposal. The business evidence must come from deterministic
     * backend findings." — ez a "proposed action" mező is a hátteret
     * írja le, nem a modell szövegezi).
     *
     * @return array{type:string,summary:string}
     */
    private function describeProposedAction(string $proposalType, string $entityName): array
    {
        $summary = match ($proposalType) {
            'inventory_review' => "Tekintsd át \"$entityName\" készletszintjét.",
            'reorder_draft' => "Vizsgáld meg \"$entityName\" esetleges utánrendelésének szükségességét.",
            'sales_review' => "Tekintsd át \"$entityName\" eladás-visszaesését.",
            default => "Tekintsd át \"$entityName\" adatait.",
        };
        return ['type' => $proposalType, 'summary' => $summary];
    }

    /**
     * A kör 12. pontja — "Do NOT include: timestamps, random IDs, natural
     * language text." Csak a normalizált üzleti azonosság: javaslat-
     * típus + entitás + ok-kód + (konstans) időszak-címke.
     */
    public function computeFingerprint(string $proposalType, string $entityType, int $entityId, string $reasonCode): string
    {
        return hash('sha256', $proposalType . '|' . $entityType . '|' . $entityId . '|' . $reasonCode . '|' . self::PERIOD_LABEL);
    }

    /**
     * @param array{status?:string, proposal_type?:string, agent?:string} $filters
     */
    public function listProposals(array $filters, int $limit, int $offset): array
    {
        $this->db->sweepExpiredActionProposals();
        return $this->db->listActionProposals($filters, $limit, $offset);
    }

    public function countProposals(array $filters): int
    {
        return $this->db->countActionProposals($filters);
    }

    public function getProposal(int $id): ?array
    {
        $this->db->sweepExpiredActionProposals();
        return $this->db->getActionProposal($id);
    }

    /**
     * A kör 15/17/18. pontja — a JÓVÁHAGYÁS a legkritikusabb átmenet:
     *   1. A javaslatnak léteznie kell ÉS 'pending' állapotban.
     *   2. Ha már lejárt, atomikusan 'expired'-re vált — SOSE hagyható jóvá.
     *   3. REVALIDÁCIÓ (a kör 14. pontja) — a MOST lekérdezett termék
     *      készlete a javaslat LÉTREHOZÁSAKORI evidence-ével egyezik-e.
     *      Ha NEM, a javaslat atomikusan 'stale'-re vált — SOSE hagyható
     *      jóvá a régi adat alapján.
     *   4. KIZÁRÓLAG ha egyik fenti sem áll fenn, az ATOMI
     *      approveActionProposal() UPDATE (status='pending' AND
     *      expires_at > most) dönt véglegesen — ez, NEM a fenti PHP-szintű
     *      előzetes ellenőrzés, a tényleges konkurrencia-védelem: két
     *      versengő hívás közül a WHERE-feltétel miatt csak az egyik
     *      UPDATE-je talál még 'pending' sort (lásd Database::
     *      approveActionProposal() docblokkja).
     *
     * A $staffId NULLABLE — lásd Database::approveActionProposal()
     * docblokkja: a MEGLÉVŐ, projekt-szintű konvenció (pl. webroot/api/
     * cash-session-open.php) szerint egy dolgozói PIN-rendszer nélküli
     * (egy-üzemeltetős) telepítésen Auth::currentStaffId() legitim módon
     * null — ez NEM hitelesítési hiba, a jóváhagyás ilyenkor is
     * érvényes, csak a reviewed_by mező marad NULL.
     *
     * @return array{ok:bool, status?:string, reason?:string}
     */
    public function approve(int $id, ?int $staffId): array
    {
        $proposal = $this->db->getActionProposal($id);
        if ($proposal === null) {
            return ['ok' => false, 'reason' => 'not_found'];
        }
        if ($proposal['status'] !== 'pending') {
            return ['ok' => false, 'reason' => $proposal['status']];
        }
        if (strtotime((string) $proposal['expires_at']) <= time()) {
            $this->db->expireActionProposal($id);
            return ['ok' => false, 'reason' => 'expired'];
        }

        if ($this->isStale($proposal)) {
            $this->db->markActionProposalStale($id);
            try {
                $this->db->logSystemEvent(
                    'ai',
                    'action_proposal_stale',
                    'info',
                    'success',
                    'AI-javaslat elavulttá vált jóváhagyás előtti ellenőrzéskor.',
                    json_encode(['proposal_id' => $id], JSON_UNESCAPED_UNICODE),
                    (int) ($this->appSettings['system_events_retention_days'] ?? 14)
                );
            } catch (Throwable $e) {
                error_log('[fountaintrade] Action proposal stale logSystemEvent sikertelen: ' . $e->getMessage());
            }
            return ['ok' => false, 'reason' => 'stale'];
        }

        $approved = $this->db->approveActionProposal($id, $staffId);
        if (!$approved) {
            // Időközben (a fenti ellenőrzések ÓTA) egy másik kérés már
            // megváltoztatta az állapotot — lásd a metódus docblokkja.
            $fresh = $this->db->getActionProposal($id);
            return ['ok' => false, 'reason' => $fresh['status'] ?? 'unknown'];
        }

        try {
            $this->db->logAudit(
                $staffId,
                'ai_action_proposal_approve',
                'ai_action_proposal',
                $id,
                "AI-javaslat jóváhagyva (#{$id}, {$proposal['proposal_type']}). Üzleti művelet NEM történt.",
                (int) ($this->appSettings['audit_log_retention_days'] ?? 30)
            );
        } catch (Throwable $e) {
            error_log('[fountaintrade] Action proposal approve logAudit sikertelen: ' . $e->getMessage());
        }

        return ['ok' => true, 'status' => 'approved'];
    }

    /**
     * A $staffId NULLABLE, lásd approve() docblokkja.
     *
     * @return array{ok:bool, status?:string, reason?:string}
     */
    public function reject(int $id, ?int $staffId, ?string $reason): array
    {
        $proposal = $this->db->getActionProposal($id);
        if ($proposal === null) {
            return ['ok' => false, 'reason' => 'not_found'];
        }
        if ($proposal['status'] !== 'pending') {
            return ['ok' => false, 'reason' => $proposal['status']];
        }

        $boundedReason = $reason !== null && $reason !== ''
            ? mb_substr($reason, 0, ActionProposal::MAX_REJECTION_REASON_LENGTH)
            : null;

        $rejected = $this->db->rejectActionProposal($id, $staffId, $boundedReason);
        if (!$rejected) {
            $fresh = $this->db->getActionProposal($id);
            return ['ok' => false, 'reason' => $fresh['status'] ?? 'unknown'];
        }

        try {
            $this->db->logAudit(
                $staffId,
                'ai_action_proposal_reject',
                'ai_action_proposal',
                $id,
                "AI-javaslat elutasítva (#{$id}, {$proposal['proposal_type']})." . ($boundedReason !== null ? " Indoklás: $boundedReason" : ''),
                (int) ($this->appSettings['audit_log_retention_days'] ?? 30)
            );
        } catch (Throwable $e) {
            error_log('[fountaintrade] Action proposal reject logAudit sikertelen: ' . $e->getMessage());
        }

        return ['ok' => true, 'status' => 'rejected'];
    }

    /**
     * A kör 14. pontja — "Before approving a proposal, revalidate current
     * business state." Ebben a fázisban EGYETLEN, minden javaslat-
     * típusnál jelen lévő, jól definiált jelzőt hasonlít össze: a
     * termék JELENLEGI készletét a javaslat LÉTREHOZÁSAKORI evidence-ében
     * tárolt `current_stock` értékkel. Bármilyen eltérés (nem csak
     * csökkenés) elavulttá teszi a javaslatot — determinisztikus,
     * SZIGORÚ egyezés-vizsgálat, nincs "elég közeli" tolerancia-sáv,
     * amit valaki vitathatna.
     *
     * Fázis 8B — PUBLIC, mert az ActionExecutor UGYANEZT az ellenőrzést
     * futtatja a végrehajtás-claim UTÁN, a tényleges mutáció ELŐTT (a
     * kör 6. pontja: "Before execution, re-fetch current business
     * state") — nincs ok duplikálni ezt a logikát egy második helyen.
     */
    public function isStale(array $proposal): bool
    {
        if ($proposal['entity_type'] !== 'product') {
            return false;
        }
        $product = $this->db->findProductById((int) $proposal['entity_id']);
        if ($product === null) {
            // A termék a javaslat létrehozása óta törölve — megbízhatóan
            // nem hagyható jóvá.
            return true;
        }
        $evidence = [];
        if (!empty($proposal['evidence_json'])) {
            $decoded = json_decode((string) $proposal['evidence_json'], true);
            $evidence = is_array($decoded) ? $decoded : [];
        }
        if (!array_key_exists('current_stock', $evidence)) {
            return false;
        }
        return (int) $evidence['current_stock'] !== (int) ($product['stock_qty'] ?? 0);
    }
}
