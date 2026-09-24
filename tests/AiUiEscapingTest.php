<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Biztonsági audit F-02/F-06 — az AI Asszisztens oldal (webroot/ai-asszisztens.js)
 * MINDEN innerHTML-sablonjában minden ${…} interpoláció a window.escapeHtml
 * (esc) segédfüggvényen megy át: terméknév, entity_name, evidence, javaslat-
 * összegzés, eszköznév, napi findings, hibaüzenet — egyik sem futhat HTML-ként.
 * A böngészős (valódi DOM) viselkedési bizonyítás a remediációs jelentésben.
 */
final class AiUiEscapingTest extends TestCase
{
    private static function source(): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/webroot/ai-asszisztens.js');
    }

    /** @return string[] minden innerHTML-értékadás jobb oldala (a következő ";\n"-ig) */
    private static function innerHtmlAssignments(string $js): array
    {
        preg_match_all('/\.innerHTML\s*=\s*(.*?);\s*\n/s', $js, $m);
        return $m[1];
    }

    /** @return string[] a kifejezés összes ${…} interpolációjának belseje (egymásba ágyazott kapcsos zárójelekkel is) */
    private static function interpolations(string $expr): array
    {
        $result = [];
        $offset = 0;
        while (($start = strpos($expr, '${', $offset)) !== false) {
            $depth = 1;
            $i = $start + 2;
            while ($i < strlen($expr) && $depth > 0) {
                if ($expr[$i] === '{') {
                    $depth++;
                } elseif ($expr[$i] === '}') {
                    $depth--;
                }
                $i++;
            }
            $result[] = substr($expr, $start + 2, $i - $start - 3);
            $offset = $i;
        }
        return $result;
    }

    public function testEscapeHelperIsBoundToTheGlobalEscapeHtml(): void
    {
        $this->assertMatchesRegularExpression('/const esc = window\.escapeHtml;/', self::source());
    }

    public function testGlobalEscapeHtmlEscapesAllHtmlSignificantCharacters(): void
    {
        $topbar = (string) file_get_contents(dirname(__DIR__) . '/webroot/topbar.js');
        foreach (['&amp;', '&lt;', '&gt;', '&quot;', '&#039;'] as $entity) {
            $this->assertStringContainsString("'$entity'", $topbar);
        }
    }

    public function testEveryInnerHtmlInterpolationIsEscaped(): void
    {
        $assignments = self::innerHtmlAssignments(self::source());
        $this->assertGreaterThanOrEqual(10, count($assignments), 'A sablon-felismerés elromlott — túl kevés innerHTML-értékadást talált.');

        $checked = 0;
        foreach ($assignments as $assignment) {
            foreach (self::interpolations($assignment) as $expr) {
                $checked++;
                $this->assertMatchesRegularExpression('/^esc\(/', trim($expr), "Escape nélküli innerHTML-interpoláció: \${{$expr}}");
            }
        }
        $this->assertGreaterThanOrEqual(20, $checked);
    }

    public function testKnownAttackerInfluencedFieldsAreRenderedThroughEsc(): void
    {
        $js = self::source();
        foreach (['esc(t)', 'esc(f.entity_name', 'esc(p.entity_name', 'esc(k)', 'esc(v)', 'esc(err.message)'] as $needle) {
            $this->assertStringContainsString($needle, $js);
        }
    }
}
