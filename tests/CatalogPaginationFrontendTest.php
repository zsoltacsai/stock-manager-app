<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * PERF-09 frontend regresszió — a lapozott Termékek oldal (webroot/termekek.js)
 * egy hamis DOM-on, node:vm alatt: csak az aktuális oldal kerül a DOM-ba, a
 * kijelölés és a tömeges művelet a teljes szűrt halmazon dolgozik, a szűrés
 * késleltetett és az elavult válasz nem írja felül a frissebbet. Lásd
 * tests/js/catalog-pagination.cjs.
 */
final class CatalogPaginationFrontendTest extends TestCase
{
    public function testProductsPageRendersOnlyTheCurrentPageAndKeepsSelectionSemantics(): void
    {
        $node = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('A node nem érhető el — a frontend-harness nem futtatható.');
        }
        $node = strtok($node, "\r\n");

        $proc = proc_open([$node, __DIR__ . '/js/catalog-pagination.cjs'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        $code = proc_close($proc);

        $json = json_decode(trim($out), true);
        $this->assertIsArray($json, "Harness kimenet: $out\n$err");
        foreach ($json['results'] as $r) {
            $this->assertTrue($r['ok'], $r['name'] . ': ' . json_encode($r['detail'] ?? null, JSON_UNESCAPED_UNICODE));
        }
        $this->assertGreaterThanOrEqual(16, count($json['results']));
        $this->assertSame(0, $code, $err . ($json['error'] ?? ''));
    }
}
