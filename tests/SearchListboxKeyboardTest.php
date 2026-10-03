<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Phase 9 accessibility remediation (KBD-01, CRITICAL) — a POS/globális
 * keresők megosztott billentyűzet-navigációs segédje (webroot/topbar.js,
 * window.attachSearchListboxKeyboard) egy hamis DOM-on, node:vm alatt:
 * ArrowDown/ArrowUp mozgatja a kiemelést és az aria-activedescendant-ot,
 * Enter a kiemelt (vagy az egyetlen) opciót "kattintja", Escape meghívja az
 * onEscape callbacket és nem buborékol tovább. Lásd
 * tests/js/search-listbox-keyboard.cjs. Ez a teszt a TÉNYLEGES futásidejű
 * viselkedést ellenőrzi (nem csak a forrás szöveges jelenlétét, mint az
 * AccessibilityRemediationPhase9Test), ezért egy olyan mutáció is elbuktatja,
 * ami a kulcs-összehasonlítást hatástalanítja anélkül, hogy a "ArrowDown"
 * stringet eltávolítaná.
 */
final class SearchListboxKeyboardTest extends TestCase
{
    public function testArrowKeysEnterAndEscapeActuallyDriveTheListbox(): void
    {
        $node = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('A node nem érhető el — a frontend-harness nem futtatható.');
        }
        $node = strtok($node, "\r\n");

        $proc = proc_open([$node, __DIR__ . '/js/search-listbox-keyboard.cjs'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        $out = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        $code = proc_close($proc);

        $json = json_decode(trim($out), true);
        $this->assertIsArray($json, "Harness kimenet: $out\n$err");
        foreach ($json['results'] as $r) {
            $this->assertTrue($r['ok'], $r['name'] . ': ' . json_encode($r['detail'] ?? null));
        }
        $this->assertGreaterThanOrEqual(9, count($json['results']));
        $this->assertSame(0, $code, $err);
    }
}
