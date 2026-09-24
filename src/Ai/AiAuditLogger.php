<?php

declare(strict_types=1);

require_once __DIR__ . '/AgentRunResult.php';
require_once __DIR__ . '/CopilotRunResult.php';
require_once __DIR__ . '/AiPricing.php';

/**
 * Egy AI-agent futásának naplózása a MEGLÉVŐ két napló-mechanizmuson
 * keresztül (lásd a kör 9. pontja) — nincs új tábla/migráció: a
 * tevékenységnapló (audit_log, "ki mit csinált") a logAudit()-tal, a
 * rendszeresemény-napló (system_events, "sikerült-e, meddig tartott")
 * a logSystemEvent()-tel, 'ai' kategóriával (lásd Database::
 * SYSTEM_EVENT_CATEGORIES). Mindkét hívás a MEGLÉVŐ, admin által
 * beállítható megőrzési idő-beállítást használja — nincs külön AI-
 * specifikus retention beállítás.
 *
 * SOSE naplózza: a nyers modell-választ, API-kulcsokat/titkokat, vagy
 * korlátlan hosszúságú szöveget — a kérdés és a hibaüzenet is bounded
 * (lásd truncate()), ugyanazzal az indoklással, mint a kör 9. pontja
 * ("Keep audit records bounded").
 *
 * Fázis 10 — a kör 16. pontja ("Do not create a parallel logging
 * system"): a `technical_detail` JSON MOST már MINDIG tartalmazza a
 * Fázis 9-ben bevezetett mezőket (streamed/tokenek/becsült
 * költség/limit_reached/context_compacted) ÉS az új `failure_category`-t
 * is — mindegyiket a MÁR átadott `$result` (és `$provider`/`$model`)
 * objektumból származtatva, ÚJ paraméter NÉLKÜL. Korábban EZEKET a
 * mezőket `webroot/api/ai-agent-stream.php` KÜLÖN, ezzel párhuzamos,
 * duplikált naplózó-logikával írta — az a végpont MOST már ezt a
 * KÖZÖS metódust hívja, a párhuzamos naplózás megszűnt.
 */
final class AiAuditLogger
{
    private const MAX_QUESTION_LENGTH = 500;
    private const MAX_ERROR_LENGTH = 200;

    public static function logRun(
        Database $db,
        array $appSettings,
        ?int $staffId,
        string $agent,
        string $provider,
        string $model,
        string $question,
        AgentRunResult $result,
        float $durationMs,
    ): void {
        $truncatedQuestion = self::truncate($question, self::MAX_QUESTION_LENGTH);
        $toolsSummary = $result->toolsUsed ? implode(', ', $result->toolsUsed) : '(nincs)';
        $outcomeText = $result->success
            ? 'Sikeres'
            : 'Sikertelen: ' . self::truncate((string) $result->error, self::MAX_ERROR_LENGTH);

        $auditDetails = sprintf(
            'Kérdés: %s | Provider: %s | Modell: %s | Eszközök: %s | Iterációk: %d | %s (%d ms)',
            $truncatedQuestion,
            $provider,
            $model,
            $toolsSummary,
            $result->iterations,
            $outcomeText,
            (int) $durationMs
        );

        try {
            $db->logAudit(
                $staffId,
                'ai_agent_run',
                'ai_agent',
                null,
                $auditDetails,
                (int) ($appSettings['audit_log_retention_days'] ?? 30)
            );
        } catch (Throwable $e) {
            // A naplózás sikertelensége SOSE buktassa meg magát az agent-
            // választ — ugyanaz az elv, mint a projekt többi hívási
            // helyén (pl. backup-restore.php logSystemEvent try/catch-e).
            error_log('[fountaintrade] AI audit logAudit sikertelen: ' . $e->getMessage());
        }

        $technicalDetail = json_encode(array_merge([
            'agent' => $agent,
            'provider' => $provider,
            'model' => $model,
            'tools_used' => $result->toolsUsed,
            'iterations' => $result->iterations,
            'duration_ms' => (int) $durationMs,
            'error' => $result->success ? null : self::truncate((string) $result->error, self::MAX_ERROR_LENGTH),
        ], self::extendedFields($result, $provider, $model)), JSON_UNESCAPED_UNICODE);

        try {
            $db->logSystemEvent(
                'ai',
                'agent_run',
                $result->success ? 'info' : 'warning',
                $result->success ? 'success' : 'failure',
                $result->success ? "AI-agent futás sikeres ($agent)." : "AI-agent futás sikertelen ($agent).",
                $technicalDetail,
                (int) ($appSettings['system_events_retention_days'] ?? 14)
            );
        } catch (Throwable $e) {
            error_log('[fountaintrade] AI audit logSystemEvent sikertelen: ' . $e->getMessage());
        }
    }

    /**
     * Fázis 6 — az AiCopilot futásainak naplózása, a MEGLÉVŐ logRun()
     * MELLÉ, azt NEM módosítva (a kör 13. pontja: "Reuse AiAuditLogger" —
     * de a Copilot eredménye CopilotRunResult, nem AgentRunResult, ezért
     * egy külön, párhuzamos metódus a legkisebb változtatás, ami a
     * meglévő három agent naplózási viselkedését bit-pontosan
     * érintetlenül hagyja). Ugyanaz a két napló-mechanizmus (logAudit +
     * logSystemEvent), 'copilot' agent-névvel, KIEGÉSZÍTVE a részt vevő
     * ügynökök listájával — de továbbra is bounded, SOSE a nyers
     * agent-válaszok teljes szövegével (lásd truncate()).
     */
    public static function logCopilotRun(
        Database $db,
        array $appSettings,
        ?int $staffId,
        string $provider,
        string $model,
        string $question,
        CopilotRunResult $result,
        float $durationMs,
    ): void {
        $truncatedQuestion = self::truncate($question, self::MAX_QUESTION_LENGTH);
        $agentsSummary = $result->agentsUsed ? implode(', ', $result->agentsUsed) : '(nincs)';
        $toolsSummary = $result->toolsUsed ? implode(', ', $result->toolsUsed) : '(nincs)';
        $outcomeText = $result->success
            ? 'Sikeres'
            : 'Sikertelen: ' . self::truncate((string) $result->error, self::MAX_ERROR_LENGTH);

        $auditDetails = sprintf(
            'Kérdés: %s | Provider: %s | Modell: %s | Ügynökök: %s | Eszközök: %s | Iterációk: %d | %s (%d ms)',
            $truncatedQuestion,
            $provider,
            $model,
            $agentsSummary,
            $toolsSummary,
            $result->iterations,
            $outcomeText,
            (int) $durationMs
        );

        try {
            $db->logAudit(
                $staffId,
                'ai_agent_run',
                'ai_agent',
                null,
                $auditDetails,
                (int) ($appSettings['audit_log_retention_days'] ?? 30)
            );
        } catch (Throwable $e) {
            error_log('[fountaintrade] AI Copilot audit logAudit sikertelen: ' . $e->getMessage());
        }

        $agentResultsBounded = [];
        foreach ($result->agentResults as $agentName => $agentResult) {
            $agentResultsBounded[$agentName] = [
                'success' => $agentResult['success'],
                'tools_used' => $agentResult['tools_used'],
                'error' => $agentResult['error'] !== null ? self::truncate((string) $agentResult['error'], self::MAX_ERROR_LENGTH) : null,
            ];
        }

        $technicalDetail = json_encode(array_merge([
            'agent' => 'copilot',
            'provider' => $provider,
            'model' => $model,
            'agents_used' => $result->agentsUsed,
            'agent_results' => $agentResultsBounded,
            'tools_used' => $result->toolsUsed,
            'iterations' => $result->iterations,
            'duration_ms' => (int) $durationMs,
            'error' => $result->success ? null : self::truncate((string) $result->error, self::MAX_ERROR_LENGTH),
        ], self::extendedFields($result, $provider, $model)), JSON_UNESCAPED_UNICODE);

        try {
            $db->logSystemEvent(
                'ai',
                'agent_run',
                $result->success ? 'info' : 'warning',
                $result->success ? 'success' : 'failure',
                $result->success ? 'AI-agent futás sikeres (copilot).' : 'AI-agent futás sikertelen (copilot).',
                $technicalDetail,
                (int) ($appSettings['system_events_retention_days'] ?? 14)
            );
        } catch (Throwable $e) {
            error_log('[fountaintrade] AI Copilot audit logSystemEvent sikertelen: ' . $e->getMessage());
        }
    }

    /**
     * Fázis 10 — a kör 15. pontja: determinisztikus, SOSE kitalált
     * költség-becslés (lásd AiPricing.php docblokkja) — ha a modellhez
     * nincs ellenőrzött árazás, `estimated_cost` `null` marad, SOSE 0
     * vagy egy találgatott szám.
     *
     * @return array{streamed:bool,input_tokens:?int,output_tokens:?int,total_tokens:?int,estimated_cost:?float,limit_reached:?string,context_compacted:bool,failure_category:?string}
     */
    private static function extendedFields(AgentRunResult|CopilotRunResult $result, string $provider, string $model): array
    {
        $estimatedCost = null;
        if ($result->usage !== null) {
            $estimate = AiPricing::estimate($provider, $model, $result->usage);
            $estimatedCost = $estimate['cost'] ?? null;
        }

        return [
            'streamed' => $result->streamed,
            'input_tokens' => $result->usage->inputTokens ?? null,
            'output_tokens' => $result->usage->outputTokens ?? null,
            'total_tokens' => $result->usage->totalTokens ?? null,
            'estimated_cost' => $estimatedCost,
            'limit_reached' => $result->limitReached,
            'context_compacted' => $result->wasCompacted,
            'failure_category' => $result->failureCategory,
        ];
    }

    private static function truncate(string $value, int $maxLength): string
    {
        return mb_strlen($value) > $maxLength ? mb_substr($value, 0, $maxLength) . '…' : $value;
    }
}
