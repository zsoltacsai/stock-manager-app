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
 *
 * AI-05 — a check() önmagában check-then-act (a futás a BEFEJEZÉSKOR
 * naplózódik), így párhuzamos kérések mind átjutnak rajta. Az
 * acquireRunSlot() ezt atomivá teszi: szereplőnként (dolgozó + terminál)
 * egyetlen futás-slot, egy nem-blokkoló fájlzárral (ugyanaz a
 * temp-könyvtár és flock-minta, mint az Auth fájl-alapú rate limitje). A
 * zár a futás végéig (a kérés végéig) él; egy összeomlott folyamat zárját
 * az operációs rendszer engedi el. A slot-fájl a legutóbbi indítás
 * idejét is tárolja, így a meglévő `ai_min_seconds_between_requests`
 * az indítások között is érvényes, nem csak a befejezett futások után.
 * A várakozási időt (mint eddig) csak a streamelő végpont érvényesíti
 * ($enforceMinInterval); a nem-streamelt végpontok csak a párhuzamossági
 * slotot kapják — a korábbi viselkedésük (egymás utáni kérések) változatlan.
 *
 * Terminál = a proxyzott kliens-gép (registered client) vagy 'local':
 * két dolgozó két terminálon, illetve ugyanaz a dolgozó két különböző
 * kliens-gépen egymástól függetlenül futtathat — a legitim multi-terminál
 * használat nem blokkolódik, csak az egy terminálról indított párhuzamos
 * futások.
 */
final class AiRateLimiter
{
    /**
     * @return array{ok:true, slot:AiRunSlot}|array{ok:false, reason:string, error:string, retry_after_seconds?:int}
     */
    public static function acquireRunSlot(Database $db, array $appSettings, ?int $staffId, ?int $registeredClientId, bool $enforceMinInterval = true, ?string $lockDir = null): array
    {
        $actorKey = ($staffId !== null ? 'staff-' . $staffId : 'nostaff') . '@' . ($registeredClientId !== null ? 'client-' . $registeredClientId : 'local');
        $dir = $lockDir ?? sys_get_temp_dir() . '/stockmanager-ratelimit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        $handle = @fopen($dir . '/ai-run-' . hash('sha256', $actorKey) . '.lock', 'c+');
        if ($handle === false) {
            // Zárfájl nélkül nem garantálható az egy-futás-szereplőnként szabály.
            return ['ok' => false, 'reason' => 'unavailable', 'error' => 'Az AI-kérés most nem indítható — próbáld újra.'];
        }
        if (!flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);
            return ['ok' => false, 'reason' => 'already_running', 'error' => 'Már fut egy AI-kérésed ezen a terminálon — várd meg, amíg befejeződik.'];
        }

        $minSeconds = $enforceMinInterval ? max(0, (int) ($appSettings['ai_min_seconds_between_requests'] ?? 2)) : 0;
        rewind($handle);
        $lastStart = (int) trim((string) stream_get_contents($handle));
        $sinceStart = time() - $lastStart;
        $check = $enforceMinInterval ? self::check($db, $appSettings, $staffId) : ['ok' => true];
        if (!$check['ok'] || ($minSeconds > 0 && $lastStart > 0 && $sinceStart < $minSeconds)) {
            flock($handle, LOCK_UN);
            fclose($handle);
            $retry = max((int) ($check['retry_after_seconds'] ?? 0), $minSeconds - $sinceStart, 1);
            return ['ok' => false, 'reason' => 'too_soon', 'error' => 'Túl gyorsan érkezett a következő AI-kérés — várj néhány másodpercet.', 'retry_after_seconds' => $retry];
        }

        ftruncate($handle, 0);
        rewind($handle);
        fwrite($handle, (string) time());
        fflush($handle);
        return ['ok' => true, 'slot' => new AiRunSlot($handle)];
    }

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

/** AI-05 — egy megszerzett futás-slot; a zár a release()-ig vagy az objektum megszűnéséig él. */
final class AiRunSlot
{
    /** @param resource $handle */
    public function __construct(private $handle)
    {
    }

    public function release(): void
    {
        if (is_resource($this->handle)) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
