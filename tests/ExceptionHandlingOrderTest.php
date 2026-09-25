<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * B-12 (correctness audit) — statikus sweep és egységszintű ellenőrzés.
 *
 * A PDOException a RuntimeException leszármazottja: egy catch-láncban a
 * `catch (RuntimeException)` a PDOException-t is elkapja, ha előbb áll —
 * így egy DB-hiba üzleti 409-cé válhatott nyers SQL-szöveggel, és az utána
 * álló `catch (PDOException)` (pl. idempotens visszajátszás) halott kód lett.
 * Ez a teszt a PHP tokenizerrel minden try-blokk catch-láncát végignézi.
 */
final class ExceptionHandlingOrderTest extends TestCase
{
    /**
     * @return list<array{file:string, line:int, types:list<string>, bodies:list<string>}>
     */
    private static function catchChains(string $file): array
    {
        $tokens = token_get_all(file_get_contents($file));
        $chains = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_TRY) {
                continue;
            }
            $line = $tokens[$i][2];
            $j = self::skipBlock($tokens, $i);
            $types = [];
            $bodies = [];
            while (true) {
                $k = $j;
                while ($k < $count && is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    $k++;
                }
                if ($k >= $count || !is_array($tokens[$k]) || $tokens[$k][0] !== T_CATCH) {
                    break;
                }
                $typeText = '';
                $k++;
                while ($k < $count && $tokens[$k] !== ')') {
                    $typeText .= is_array($tokens[$k]) ? $tokens[$k][1] : $tokens[$k];
                    $k++;
                }
                preg_match_all('/\\\\?([A-Za-z_]\w*)\s*(?=\||\$)/', $typeText, $m);
                $types[] = implode('|', $m[1]);
                $end = self::skipBlock($tokens, $k);
                $body = '';
                for ($b = $k; $b < $end; $b++) {
                    $body .= is_array($tokens[$b]) ? $tokens[$b][1] : $tokens[$b];
                }
                $bodies[] = $body;
                $j = $end;
            }
            $chains[] = ['file' => $file, 'line' => $line, 'types' => $types, 'bodies' => $bodies];
        }
        return $chains;
    }

    /** A $from utáni első {…} blokk vége utáni tokenindex. */
    private static function skipBlock(array $tokens, int $from): int
    {
        $count = count($tokens);
        $k = $from;
        while ($k < $count && $tokens[$k] !== '{' && !(is_array($tokens[$k]) && in_array($tokens[$k][0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
            $k++;
        }
        $depth = 0;
        for (; $k < $count; $k++) {
            $t = $tokens[$k];
            if ($t === '{' || (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true))) {
                $depth++;
            } elseif ($t === '}') {
                $depth--;
                if ($depth === 0) {
                    return $k + 1;
                }
            }
        }
        return $count;
    }

    /** @return list<string> */
    private static function phpFiles(): array
    {
        $root = dirname(__DIR__);
        $files = glob($root . '/webroot/api/*.php');
        $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/src', FilesystemIterator::SKIP_DOTS));
        foreach ($it as $f) {
            if (str_ends_with($f->getFilename(), '.php')) {
                $files[] = $f->getPathname();
            }
        }
        return $files;
    }

    private static function catches(string $types, string $class): bool
    {
        return in_array($class, explode('|', $types), true);
    }

    public function testNoRuntimeOrExceptionCatchShadowsALaterPdoExceptionCatch(): void
    {
        $violations = [];
        foreach (self::phpFiles() as $file) {
            foreach (self::catchChains($file) as $chain) {
                $broadSeen = null;
                foreach ($chain['types'] as $types) {
                    if (self::catches($types, 'PDOException') && $broadSeen !== null) {
                        $violations[] = basename($file) . ':' . $chain['line'] . " — PDOException a(z) $broadSeen után";
                    }
                    foreach (['RuntimeException', 'Exception', 'Throwable'] as $broad) {
                        if (self::catches($types, $broad)) {
                            $broadSeen ??= $broad;
                        }
                    }
                }
            }
        }
        $this->assertSame([], $violations);
    }

    public function testEveryEndpointRuntimeExceptionCatchIsPrecededByAPdoExceptionCatch(): void
    {
        $violations = [];
        $chainsSeen = 0;
        foreach (glob(dirname(__DIR__) . '/webroot/api/*.php') as $file) {
            foreach (self::catchChains($file) as $chain) {
                $pdoSeen = false;
                foreach ($chain['types'] as $types) {
                    if (self::catches($types, 'PDOException')) {
                        $pdoSeen = true;
                    }
                    if (self::catches($types, 'RuntimeException')) {
                        $chainsSeen++;
                        if (!$pdoSeen) {
                            $violations[] = basename($file) . ':' . $chain['line'];
                        }
                    }
                }
            }
        }
        $this->assertGreaterThanOrEqual(13, $chainsSeen, 'A sweep valóban megtalálta a RuntimeException-láncokat.');
        $this->assertSame([], $violations, 'Ezekben a RuntimeException-ág egy DB-hibát üzleti 409-nek látna.');
    }

    public function testNoEndpointEchoesAPdoExceptionMessage(): void
    {
        $violations = [];
        foreach (glob(dirname(__DIR__) . '/webroot/api/*.php') as $file) {
            foreach (self::catchChains($file) as $chain) {
                foreach ($chain['types'] as $i => $types) {
                    if (self::catches($types, 'PDOException') && preg_match("/send_json\(\[[^\]]*getMessage\(\)/", $chain['bodies'][$i])) {
                        $violations[] = basename($file) . ':' . $chain['line'];
                    }
                }
            }
        }
        $this->assertSame([], $violations);
    }

    public function testKnownDeadReplayBranchesAreNowReachable(): void
    {
        foreach (['cash-movement.php', 'cash-session-open.php'] as $name) {
            $chains = array_filter(self::catchChains(dirname(__DIR__) . '/webroot/api/' . $name), fn ($c) => in_array('PDOException', $c['types'], true));
            $this->assertCount(1, $chains, $name);
            $chain = array_values($chains)[0];
            $this->assertLessThan(array_search('RuntimeException', $chain['types'], true), array_search('PDOException', $chain['types'], true), "$name: a replay-ág a RuntimeException előtt");
            $pdoBody = $chain['bodies'][array_search('PDOException', $chain['types'], true)];
            $this->assertStringContainsString('ByIdempotencyKey', $pdoBody);
        }
    }

    public function testTheSweepDetectsTheOriginalBadOrder(): void
    {
        $file = sys_get_temp_dir() . '/sm_b12_sweep_' . bin2hex(random_bytes(4)) . '.php';
        file_put_contents($file, "<?php\ntry { x(); } catch (RuntimeException \$e) { send_json(['error' => \$e->getMessage()], 409); } catch (PDOException \$e) { replay(); }\n");
        try {
            $chain = self::catchChains($file)[0];
        } finally {
            @unlink($file);
        }
        $this->assertSame(['RuntimeException', 'PDOException'], $chain['types']);
    }

    // ------------------------------------------------------------------
    // Provider-szint: nem-UNIQUE DB-hiba nem "már folyamatban"
    // ------------------------------------------------------------------

    public function testInvoiceOperationDbFailureIsNotReportedAsAlreadyInProgress(): void
    {
        $db = tests_new_database();
        $saleId = $db->insertSale(1270.0, 'Készpénz');
        $db->pdo()->prepare("INSERT INTO invoices (sale_id, provider, operation_key, status, invoice_number, net_total, vat_total, gross_total, currency, issued_at, created_at, updated_at, invoice_type)
            VALUES (?, 'szamlazz', ?, 'done', 'SZ-B12-1', 1000, 270, 1270, 'HUF', ?, ?, ?, 'normal')")
            ->execute([$saleId, 'create:' . $saleId . ':szamlazz', date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), date('Y-m-d H:i:s')]);
        $original = $db->findInvoiceBySaleAndProvider($saleId, 'szamlazz');
        // Nem UNIQUE jellegű DB-hiba az operation-sor írásakor: az invoices
        // tábla helyén egy (írhatatlan) nézet áll.
        $db->pdo()->exec('ALTER TABLE invoices RENAME TO invoices_b12');
        $db->pdo()->exec('CREATE VIEW invoices AS SELECT * FROM invoices_b12');

        $provider = new SzamlazzInvoiceProvider(['agent_key' => 't', 'endpoint' => '', 'e_invoice' => true, 'download_pdf' => false, 'send_email' => false, 'payment_method' => 'Készpénz', 'currency' => 'HUF', 'language' => 'hu', 'default_vat_rate' => '27', 'unit_label' => 'db', 'default_buyer' => [], 'pdf_dir' => sys_get_temp_dir()]);
        $this->expectException(PDOException::class);
        $provider->requestStorno($db, $original, ['buyer' => [], 'items' => [], 'totals' => []]);
    }
}
