<?php

declare(strict_types=1);

/**
 * A `staff-login.php`/`staff-logout.php` EGYETLEN, szükséges érintése a
 * Fázis 2 architektúrához — a döntési logika teljes egészében itt él, a
 * két végpont-fájl csak egy-egy plusz függvényhívást kap, a saját
 * `send_json()`-válaszuk formája VÁLTOZATLAN marad.
 *
 * Miért egyáltalán szükséges ez az érintés (a "sehol egy if(clientMode)"
 * elv egyetlen, elkerülhetetlen kivétele): a bejelentkezés az a pillanat,
 * amikor a dolgozói azonosság ténylegesen LÉTREJÖN — ezt valaminek el
 * kell döntenie, Standalone/Szerver esetén ez a meglévő
 * `Auth::setCurrentStaff()` ($_SESSION), proxyzott Kliens esetén viszont
 * egy `client_sessions` sor + CSRF-token, mert a böngésző saját
 * PHP-session-je a Kliens gépén él, nem itt.
 *
 * A Kliens felé az új `client_session_id`/CSRF-token NEM a JSON-törzsben,
 * hanem VÁLASZFEJLÉCEKBEN utazik (`X-Client-Session-Id`/
 * `X-Client-Csrf-Token`) — így a `ClientProxy` marad test-átlátszó a
 * válasz TÖRZSÉRE nézve (amit már amúgy is fejléc-szinten vizsgál a
 * hop-by-hop/Set-Cookie szűréshez), nem kell a JSON tartalmát értelmeznie.
 * Lásd `ClientProxy::filterResponseHeaderLines()` — ugyanő olvassa ki és
 * tárolja el ezeket a Kliens saját, helyi session-jébe, majd törli a
 * böngésző felé továbbadott válaszból.
 */
final class ClientSessionBridge
{
    /**
     * `staff-login.php` HÍVJA, minden sikeres PIN-ellenőrzés UTÁN,
     * feltétel nélkül. Direkt (nem proxyzott) kérésnél no-op — a meglévő
     * `Auth::setCurrentStaff()`-ot már a hívó (staff-login.php) elvégezte,
     * ez itt semmit sem ad hozzá.
     */
    public static function establishStaffSession(Database $db, array $appSettings): void
    {
        $registeredClientId = Auth::proxiedRegisteredClientId();
        if ($registeredClientId === null) {
            return; // direkt kérés — nincs mit hídalni
        }

        $staffId = Auth::currentStaffId();
        if ($staffId === null) {
            return; // elvileg sose fordulhat elő itt (a hívó már ellenőrizte a PIN-t), de defenzíven no-op
        }

        $csrfToken = bin2hex(random_bytes(32));
        $csrfTokenHash = hash('sha256', $csrfToken);
        $timeoutMinutes = (int) ($appSettings['session_timeout_minutes'] ?? 240);

        $session = $db->createClientSession($registeredClientId, $staffId, $csrfTokenHash, $timeoutMinutes);

        header('X-Client-Session-Id: ' . $session['client_session_id']);
        header('X-Client-Csrf-Token: ' . $csrfToken);
    }

    /**
     * `staff-logout.php` HÍVJA, `Auth::setCurrentStaff(null)` mellett,
     * feltétel nélkül. Direkt kérésnél no-op.
     */
    public static function clearStaffSession(Database $db): void
    {
        $clientSessionRow = Auth::proxiedClientSession();
        if ($clientSessionRow === null) {
            return;
        }
        $db->deleteClientSession((string) $clientSessionRow['client_session_id']);
        header('X-Client-Session-Cleared: 1');
    }
}
