<?php

declare(strict_types=1);

/**
 * Fázis 9 — a kör 19. pontja: "protect the Copilot from accidental
 * abuse... do not create a completely separate auth/rate-limiting
 * subsystem." Ez az osztály a MEGLÉVŐ `audit_log` táblát (amit az
 * AiAuditLogger MINDEN AI-futás után ÍR — `action = 'ai_agent_run'`)
 * olvassa vissza — NINCS új tábla/mechanizmus, tisztán egy rövid
 * "hűtési idő" (cooldown) a véletlen dupla-kattintás/gomb-pöfékelés
 * ellen, NEM egy teljes, komoly rate-limit alrendszer (az a normál POS-
 * működést sose korlátozhatja, lásd a kör 19. pontja explicit tiltása).
 *
 * Egy dolgozói PIN-rendszer NÉLKÜLI telepítésen `$staffId` lehet `null`
 * — ilyenkor a limit "globálisan" (a legutóbbi, staff_id IS NULL AI-
 * futás óta eltelt idő alapján) érvényesül, ugyanazzal az elvvel, mint
 * más, staff_id-t nullable-ként kezelő helyek ebben a kódbázisban (lásd
 * pl. Database::openCashSession() docblokkja).
 */
final class AiRateLimiter
{
    /**
     * @return array{ok:bool, retry_after_seconds?:int}
     */
    public static function check(Database $db, array $appSettings, ?int $staffId): array
    {
        $minSeconds = max(0, (int) ($appSettings['ai_min_seconds_between_requests'] ?? 2));
        if ($minSeconds <= 0) {
            return ['ok' => true];
        }

        $lastRunAt = $db->getLastAiAgentRunAt($staffId);
        if ($lastRunAt === null) {
            return ['ok' => true];
        }

        $elapsed = time() - strtotime($lastRunAt);
        if ($elapsed >= $minSeconds) {
            return ['ok' => true];
        }

        return ['ok' => false, 'retry_after_seconds' => $minSeconds - $elapsed];
    }
}
