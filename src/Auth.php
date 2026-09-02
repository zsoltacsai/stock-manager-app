<?php

declare(strict_types=1);

/**
 * Minden bejelentkezés/session-kezelés egy helyen. Fontos architekturális
 * korlát: a legtöbb oldal statikus .html fájl, amit a webszerver közvetlenül
 * kiszolgál — PHP session-ellenőrzés nem tud lefutni MIELŐTT egy .html fájl
 * tartalma elmegy a böngészőhöz. Emiatt a védelem két rétegű:
 *   1) Kliens-oldali JS (topbar.js) azonnal átirányít login.php-ra, ha nincs
 *      érvényes session — ez a UX-réteg, nem a tényleges védelem.
 *   2) A VALÓDI védelem itt van: minden api/*.php végpont (a _bootstrap.php-n
 *      keresztül) elutasítja a kérést, ha nincs érvényes session. Mivel
 *      minden adat és minden művelet kizárólag az API-n keresztül érhető el,
 *      even ha valaki a statikus HTML/JS "vázat" közvetlenül megnézi,
 *      tényleges adatot vagy funkciót nem tud elérni bejelentkezés nélkül.
 */
final class Auth
{
    private static bool $sessionStarted = false;

    private static function ensureSession(): void
    {
        if (self::$sessionStarted) {
            return;
        }
        if (session_status() === PHP_SESSION_NONE) {
            session_set_cookie_params([
                'lifetime' => 0,
                'path'     => '/',
                'secure'   => !empty($_SERVER['HTTPS']),
                'httponly' => true,
                // Strict jelentősen csökkenti a CSRF-kockázatot anélkül, hogy
                // minden POST-hívásba tokent kellene fűzni — a session-cookie
                // egyszerűen nem megy ki más oldalról induló kéréssel.
                'samesite' => 'Strict',
            ]);
            session_start();
        }
        self::$sessionStarted = true;
    }

    public static function isEnabled(array $settings): bool
    {
        return !empty($settings['app_password_enabled']) && !empty($settings['app_password_hash']);
    }

    public static function isLoggedIn(array $settings): bool
    {
        self::ensureSession();

        if (!self::isEnabled($settings)) {
            return true; // a funkció ki van kapcsolva — mindenki "bejelentkezettnek" számít
        }
        if (empty($_SESSION['auth_ok'])) {
            return false;
        }

        $timeoutMinutes = (int) ($settings['session_timeout_minutes'] ?? 240);
        $lastActivity = (int) ($_SESSION['last_activity'] ?? 0);
        if ($timeoutMinutes > 0 && (time() - $lastActivity) > $timeoutMinutes * 60) {
            self::logout();
            return false;
        }

        $_SESSION['last_activity'] = time();
        return true;
    }

    public static function login(string $password, array $settings): bool
    {
        self::ensureSession();
        if (empty($settings['app_password_hash']) || !password_verify($password, $settings['app_password_hash'])) {
            return false;
        }
        session_regenerate_id(true); // sikeres bejelentkezéskor új session-azonosító, session-fixation ellen
        $_SESSION['auth_ok'] = true;
        $_SESSION['last_activity'] = time();
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        return true;
    }

    public static function logout(): void
    {
        self::ensureSession();
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie('PHPSESSID', '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
        }
        session_destroy();
    }

    public static function csrfToken(): string
    {
        self::ensureSession();
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(?string $token): bool
    {
        self::ensureSession();
        if (empty($_SESSION['csrf_token']) || empty($token)) {
            return false;
        }
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    // -----------------------------------------------------------------
    // Rate limiting — egyszerű, fájl-alapú számláló (nincs szükség extra
    // adatbázis-táblára ehhez a viszonylag ritka művelethez).
    // -----------------------------------------------------------------

    private static function rateLimitFile(string $key): string
    {
        $dir = sys_get_temp_dir() . '/stockmanager-ratelimit';
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        return $dir . '/' . preg_replace('/[^a-zA-Z0-9_-]/', '_', $key) . '.json';
    }

    public static function checkRateLimit(string $key, int $maxAttempts, int $lockoutMinutes): array
    {
        $file = self::rateLimitFile($key);
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($data)) {
            $data = ['attempts' => 0, 'first_attempt_at' => time(), 'locked_until' => 0];
        }

        if ($data['locked_until'] > time()) {
            return ['locked' => true, 'remaining_seconds' => $data['locked_until'] - time(), 'attempts_left' => 0];
        }

        // A lockout ablak lejárt — kezdjünk friss számlálást.
        if ($data['locked_until'] > 0 && $data['locked_until'] <= time()) {
            $data = ['attempts' => 0, 'first_attempt_at' => time(), 'locked_until' => 0];
            file_put_contents($file, json_encode($data));
        }

        return ['locked' => false, 'remaining_seconds' => 0, 'attempts_left' => max(0, $maxAttempts - $data['attempts'])];
    }

    public static function recordFailedAttempt(string $key, int $maxAttempts, int $lockoutMinutes): void
    {
        $file = self::rateLimitFile($key);
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        if (!is_array($data)) {
            $data = ['attempts' => 0, 'first_attempt_at' => time(), 'locked_until' => 0];
        }
        $data['attempts']++;
        if ($data['attempts'] >= $maxAttempts) {
            $data['locked_until'] = time() + $lockoutMinutes * 60;
        }
        file_put_contents($file, json_encode($data));
    }

    public static function clearRateLimit(string $key): void
    {
        @unlink(self::rateLimitFile($key));
    }
}
