<?php

declare(strict_types=1);

require_once __DIR__ . '/AgentRunResult.php';

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

        $technicalDetail = json_encode([
            'agent' => $agent,
            'provider' => $provider,
            'model' => $model,
            'tools_used' => $result->toolsUsed,
            'iterations' => $result->iterations,
            'duration_ms' => (int) $durationMs,
            'error' => $result->success ? null : self::truncate((string) $result->error, self::MAX_ERROR_LENGTH),
        ], JSON_UNESCAPED_UNICODE);

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

    private static function truncate(string $value, int $maxLength): string
    {
        return mb_strlen($value) > $maxLength ? mb_substr($value, 0, $maxLength) . '…' : $value;
    }
}
