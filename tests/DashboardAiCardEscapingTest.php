<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * AI-02 — a Dashboard AI-kártyája (webroot/dashboard.js
 * renderAiUsageStatus()) a provider/modell nevet és minden más AI-mezőt
 * nem megbízható adatként, textContent-tel jelenít meg. A statikus
 * ellenőrzés mellett a függvényt valódi JS-motorban (Node) is lefuttatja
 * egy minimális DOM-helyettesítővel. A valódi böngészős bizonyítás a
 * remediációs jelentésben.
 */
final class DashboardAiCardEscapingTest extends TestCase
{
    private const PAYLOADS = [
        '<img src=x onerror="window.__ai02=1">',
        '<svg/onload=window.__ai02=2>',
        '"\'><b>idézőjel</b>',
        '</script><script>window.__ai02=3</script>',
        'qwen3:8b — ünnepi ŐŰ 模型 🚀 ‮fdp.exe',
    ];

    private static function functionSource(string $name): string
    {
        $js = (string) file_get_contents(dirname(__DIR__) . '/webroot/dashboard.js');
        $start = strpos($js, "function $name(");
        self::assertNotFalse($start, "$name nem található");
        $bodyStart = strpos($js, '{', $start);
        $depth = 0;
        for ($i = $bodyStart; $i < strlen($js); $i++) {
            if ($js[$i] === '{') {
                $depth++;
            } elseif ($js[$i] === '}' && --$depth === 0) {
                return substr($js, $start, $i - $start + 1);
            }
        }
        self::fail("$name törzse nem zárult le");
    }

    public function testRenderAiUsageStatusNeverWritesInnerHtml(): void
    {
        $source = self::functionSource('renderAiUsageStatus');

        $this->assertStringNotContainsString('innerHTML', $source);
        $this->assertStringContainsString('textContent', $source);
    }

    public function testRenderAiUsageStatusRendersHostileModelAndProviderNamesAsTextInRealJsEngine(): void
    {
        $node = trim((string) shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where node 2>NUL' : 'command -v node 2>/dev/null'));
        if ($node === '') {
            $this->markTestSkipped('Node nem elérhető — a JS-motoros futtatás itt nem futott le.');
        }
        $node = strtok($node, "\r\n");
        $js = (string) file_get_contents(dirname(__DIR__) . '/webroot/dashboard.js');
        preg_match('/const AI_PROVIDER_LABELS = \{[^}]*\};/', $js, $labels);
        $this->assertNotEmpty($labels);

        $harness = <<<'JS'
const writes = { innerHTML: 0 };
function el(id) {
    const node = {
        id, dataset: {}, style: {}, className: '', children: [], _text: '',
        set innerHTML(v) { writes.innerHTML++; this._html = v; },
        get innerHTML() { return this._html; },
        set textContent(v) { this._text = String(v); this.children = []; },
        get textContent() { return this._text + this.children.map(c => c.textContent).join(''); },
        replaceChildren(...c) { this._text = ''; this.children = c; },
        appendChild(c) { this.children.push(c); return c; },
    };
    return node;
}
const nodes = { 'dash-ai-daily-card': el('card'), 'dash-ai-usage-status': el('box'), 'dash-ai-daily-status': el('daily') };
const document = { getElementById: id => nodes[id] || null, createElement: tag => el(tag) };
JS;
        $harness .= "\n" . $labels[0] . "\n" . self::functionSource('updateAiDailyCardVisibility') . "\n" . self::functionSource('renderAiUsageStatus') . "\n";
        $harness .= 'const payloads = ' . json_encode(self::PAYLOADS, JSON_UNESCAPED_UNICODE) . ";\n";
        $harness .= <<<'JS'
const out = [];
for (const p of payloads) {
    renderAiUsageStatus({ enabled: true, provider: p, model: p, today_run_count: 1, last_run_at: '2026-09-26 ' + p, today_total_tokens: 5, show_usage_cost: true, today_estimated_cost: 0.5, today_has_unknown_cost_runs: false });
    out.push(nodes['dash-ai-usage-status'].textContent);
}
console.log(JSON.stringify({ innerHTML: writes.innerHTML, texts: out }));
JS;
        $file = tempnam(sys_get_temp_dir(), 'ai02') . '.js';
        file_put_contents($file, $harness);
        $output = shell_exec(escapeshellarg($node) . ' ' . escapeshellarg($file) . ' 2>&1');
        @unlink($file);
        $result = json_decode((string) $output, true);

        $this->assertIsArray($result, (string) $output);
        $this->assertSame(0, $result['innerHTML'], 'a renderelő egyszer sem írt innerHTML-t');
        foreach (self::PAYLOADS as $i => $payload) {
            $this->assertStringContainsString($payload . ' — ' . $payload, $result['texts'][$i], 'a provider- és modellnév szó szerint, szövegként jelenik meg');
        }
    }
}
