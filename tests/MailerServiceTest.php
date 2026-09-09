<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * MailerService (PHPMailer-alapú SMTP küldés) tesztek. A sikeres-küldés
 * tesztekhez egy VALÓDI, minimális SMTP-protokollt beszélő "stub"
 * szervert indítunk külön PHP-folyamatként (proc_open) — ez ténylegesen
 * gyakorolja be a PHPMailer TCP/SMTP-parancssorozatát, nem mock.
 */
final class MailerServiceTest extends TestCase
{
    private static ?array $stubProcess = null;
    private static int $stubPort = 0;
    private static string $stubLogFile = '';

    public static function tearDownAfterClass(): void
    {
        if (self::$stubProcess !== null && is_resource(self::$stubProcess['handle'])) {
            proc_terminate(self::$stubProcess['handle']);
            proc_close(self::$stubProcess['handle']);
        }
        if (self::$stubLogFile !== '') {
            @unlink(self::$stubLogFile);
        }
    }

    /**
     * A gyermek-szkript maga jelzi (egy "ready" fájl létrehozásával),
     * amikor MÁR figyel a porton, MIELŐTT elfogadna egy kapcsolatot —
     * enélkül egy külső "próba"-kapcsolódás (pl. connect-then-close
     * readiness-check) tévesen elfogyasztaná az EGYETLEN accept()-et,
     * amit a stub vár, és a VALÓDI (PHPMailer general) kapcsolódás már
     * egy halott folyamatot találna.
     */
    private function startStubSmtpServer(): int
    {
        $sock = stream_socket_server('tcp://127.0.0.1:0');
        $addr = stream_socket_get_name($sock, false);
        $port = (int) substr($addr, strrpos($addr, ':') + 1);
        fclose($sock);

        $readyFile = sys_get_temp_dir() . '/sm_smtp_stub_ready_' . bin2hex(random_bytes(6)) . '.txt';
        register_shutdown_function(static function () use ($readyFile) { @unlink($readyFile); });

        $lines = [
            '<?php',
            '$port = (int) $argv[1];',
            '$readyFile = $argv[2];',
            '$server = stream_socket_server("tcp://127.0.0.1:$port", $errno, $errstr);',
            'if (!$server) { fwrite(STDERR, "bind failed: $errstr\n"); exit(1); }',
            'file_put_contents($readyFile, "ready");',
            '$conn = @stream_socket_accept($server, 10);',
            'if (!$conn) { exit(1); }',
            'fwrite($conn, "220 stub.smtp.test ready\r\n");',
            'while (($line = fgets($conn)) !== false) {',
            '    $line = trim($line);',
            "    if (stripos(\$line, 'EHLO') === 0 || stripos(\$line, 'HELO') === 0) {",
            '        fwrite($conn, "250-stub.smtp.test\r\n250 OK\r\n");',
            "    } elseif (stripos(\$line, 'MAIL FROM') === 0) {",
            '        fwrite($conn, "250 OK\r\n");',
            "    } elseif (stripos(\$line, 'RCPT TO') === 0) {",
            '        fwrite($conn, "250 OK\r\n");',
            "    } elseif (stripos(\$line, 'DATA') === 0) {",
            '        fwrite($conn, "354 Go ahead\r\n");',
            "    } elseif (\$line === '.') {",
            '        fwrite($conn, "250 OK queued\r\n");',
            "    } elseif (stripos(\$line, 'QUIT') === 0) {",
            '        fwrite($conn, "221 Bye\r\n");',
            '        break;',
            '    }',
            '}',
            'fclose($conn);',
            'fclose($server);',
        ];

        $scriptPath = sys_get_temp_dir() . '/sm_smtp_stub_' . bin2hex(random_bytes(6)) . '.php';
        file_put_contents($scriptPath, implode("\n", $lines) . "\n");
        register_shutdown_function(static function () use ($scriptPath) { @unlink($scriptPath); });

        $logFile = sys_get_temp_dir() . '/sm_smtp_stub_log_' . bin2hex(random_bytes(4)) . '.txt';
        self::$stubLogFile = $logFile;
        $handle = proc_open(
            [PHP_BINARY, $scriptPath, (string) $port, $readyFile],
            [1 => ['file', $logFile, 'w'], 2 => ['file', $logFile, 'w']],
            $pipes
        );
        self::$stubProcess = ['handle' => $handle];

        // Nem "próba-kapcsolódással" várunk, hanem a ready-fájl
        // megjelenésére — lásd a metódus docblockja.
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            if (is_file($readyFile)) {
                return $port;
            }
            usleep(50000);
        }
        $this->fail('A teszt SMTP stub szerver nem indult el időben.');
    }

    private function baseSmtpConfig(int $port, array $overrides = []): array
    {
        return array_merge([
            'host' => '127.0.0.1',
            'port' => $port,
            'username' => '',
            'password' => '',
            'encryption' => 'none',
            'from_email' => 'sender@example.com',
            'from_name' => 'Teszt Feladó',
        ], $overrides);
    }

    // ---- Sikeres küldés ----

    public function testSuccessfulSendAgainstStubSmtpServer(): void
    {
        $port = $this->startStubSmtpServer();
        $result = MailerService::send($this->baseSmtpConfig($port), 'target@example.com', 'Test Subject', '<p>Hello</p>');
        $this->assertTrue($result['success'], 'Hiba: ' . ($result['error'] ?? ''));
        $this->assertNull($result['error']);
    }

    // ---- Kapcsolódási hiba ----

    public function testConnectionFailureReturnsGracefulError(): void
    {
        // 127.0.0.1:1 -- gyakorlatilag garantáltan nincs semmi ezen a porton.
        $result = MailerService::send($this->baseSmtpConfig(1), 'target@example.com', 'Subject', '<p>Body</p>');
        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['error']);
    }

    // ---- Titok-védelem ----

    public function testPasswordNeverAppearsInErrorMessage(): void
    {
        $config = $this->baseSmtpConfig(1, ['username' => 'user@example.com', 'password' => 'SUPER-SECRET-PW-12345']);
        $result = MailerService::send($config, 'target@example.com', 'Subject', '<p>Body</p>');
        $this->assertFalse($result['success']);
        $this->assertStringNotContainsString('SUPER-SECRET-PW-12345', (string) $result['error']);
    }

    public function testInvalidHostDoesNotThrowUncaughtException(): void
    {
        $config = $this->baseSmtpConfig(9999, ['host' => 'this-host-does-not-exist.invalid']);
        $result = MailerService::send($config, 'target@example.com', 'Subject', '<p>Body</p>');
        $this->assertFalse($result['success']);
        $this->assertNotEmpty($result['error']);
    }
}
