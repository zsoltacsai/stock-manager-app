<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * N-2 / N-3 frontend regresszió — a készletmozgatás és a részleges visszáru
 * beküldő gombja (webroot/telephelyek.js, webroot/eladasok.js) egy hamis
 * DOM-on, node:vm alatt: dupla kattintás egyetlen kérés, elveszett válasz
 * utáni újraküldés UGYANAZZAL az idempotencia-kulccsal, siker vagy
 * megváltozott adatok után új kulcs. Lásd tests/js/frontend-idempotency.cjs.
 * Nem böngészős teszt: a valódi kattintás-kezelőket hívja, a fetch()-et a
 * harness vezérli.
 */
final class FrontendIdempotencyKeyTest extends TestCase
{
    public function testTransferAndReturnButtonsKeepAStableKeyAndSendOnce(): void
    {
        $node = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('A node nem érhető el — a frontend-harness nem futtatható.');
        }
        $node = strtok($node, "\r\n");

        $proc = proc_open([$node, __DIR__ . '/js/frontend-idempotency.cjs'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        $code = proc_close($proc);

        $json = json_decode(trim($out), true);
        $this->assertIsArray($json, "Harness kimenet: $out\n$err");
        foreach ($json['results'] as $r) {
            $this->assertTrue($r['ok'], $r['name'] . ': ' . json_encode($r['detail'] ?? null));
        }
        $this->assertGreaterThanOrEqual(14, count($json['results']));
        $this->assertSame(0, $code, $err);
    }
}
