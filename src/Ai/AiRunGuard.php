<?php

declare(strict_types=1);

require_once __DIR__ . '/../Auth.php';
require_once __DIR__ . '/../Database.php';

/**
 * AI-09 — egy hosszú (streamelt vagy több fordulós) AI-futás közbeni
 * jogosultsági checkpoint. A kérés elején a _bootstrap.php és a
 * require_admin() egyszer dönt; ez az ellenőrzés UGYANAZT a döntést
 * értékeli újra a futás közben (az AgentRunner minden provider-hívás és
 * minden tool-végrehajtás előtt hívja, lásd AiRunContext):
 *
 *  - a dolgozó még aktív vezető (dolgozó nélküli telepítésen: továbbra
 *    sincs dolgozó) — deaktiválás vagy szerepkör-váltás leállítja a futást;
 *  - proxyzott (Kliens) kérésnél a kliens-gép még aktív, nincs visszavonva;
 *  - a kliens-munkamenet még létezik, ugyanahhoz a géphez és dolgozóhoz
 *    tartozik, és nem járt le (kijelentkezés/törlés leállítja a futást).
 *
 * Nem érzékeli: a Szerver saját PHP-munkamenetének kijelentkezését egy
 * futó kérés alatt (a PHP-session a kérés elején olvasódik be) — ezt a
 * dolgozói (PIN) réteg deaktiválása/szerepkör-váltása fedi le.
 */
final class AiRunGuard
{
    public static function forCurrentRequest(Database $db): Closure
    {
        $staffId = Auth::currentStaffId();
        $registeredClientId = Auth::proxiedRegisteredClientId();
        $clientSession = Auth::proxiedClientSession();
        $clientSessionId = $clientSession !== null ? (string) $clientSession['client_session_id'] : null;

        return static fn (): bool => self::isStillAuthorized($db, $staffId, $registeredClientId, $clientSessionId);
    }

    public static function isStillAuthorized(Database $db, ?int $staffId, ?int $registeredClientId, ?string $clientSessionId): bool
    {
        if ($db->listStaff(true) && !$db->isStaffAdmin($staffId)) {
            return false;
        }
        if ($registeredClientId === null) {
            return true;
        }

        $client = $db->findRegisteredClientById($registeredClientId);
        if ($client === null || (int) $client['is_active'] !== 1 || $client['revoked_at'] !== null) {
            return false;
        }
        if ($clientSessionId === null) {
            return false;
        }
        $session = $db->findClientSession($clientSessionId);
        return $session !== null
            && (int) $session['registered_client_id'] === $registeredClientId
            && (int) $session['staff_id'] === (int) $staffId
            && strtotime((string) $session['expires_at']) > time();
    }
}
