<?php

declare(strict_types=1);

require_once __DIR__ . '/../src/Pagination.php';

use PHPUnit\Framework\TestCase;

/**
 * B-15 (correctness audit) regresszió — korlátos, túlcsordulás-mentes
 * lapozás (Pagination::fromQuery()), az ai-history-list és az
 * ai-action-proposals-list végpont közös szabálya.
 *
 * Korábban page=9223372036854775807 esetén (page − 1) × page_size float-tá
 * csordult túl → TypeError az int $offset paraméteren → HTTP 500. A
 * meglévő szerződés (érvénytelen/0/negatív → 1. oldal, szám-előtag mint az
 * (int) átalakításnál) megmarad; új a MAX_PAGE felső korlát.
 */
final class PaginationBoundaryTest extends TestCase
{
    public static function pageInputs(): array
    {
        $max = Pagination::MAX_PAGE;
        return [
            'hiányzik'                => [null, 1],
            'page=0'                  => ['0', 1],
            'page=1'                  => ['1', 1],
            'page=2'                  => ['2', 2],
            'page=-1'                 => ['-1', 1],
            'page=abc'                => ['abc', 1],
            'üres'                    => ['', 1],
            'PHP_INT_MAX'             => [(string) PHP_INT_MAX, $max],
            'PHP_INT_MAX-1'           => [(string) (PHP_INT_MAX - 1), $max],
            'PHP_INT_MAX int'         => [PHP_INT_MAX, $max],
            'nagyon nagy string'      => [str_repeat('9', 400), $max],
            'MAX_PAGE'                => [(string) $max, $max],
            'MAX_PAGE+1'              => [(string) ($max + 1), $max],
            'float-szerű'             => ['2.5', 2],
            'exponens'                => ['1e3', 1],
            'vezető nullák'           => ['007', 7],
            'csupa nulla'             => ['0000', 1],
            'szóköz + előjel'         => [' +4', 4],
            'tömb (page[]=1)'         => [['1'], 1],
            'negatív óriás'           => ['-' . str_repeat('9', 30), 1],
        ];
    }

    /** @dataProvider pageInputs */
    public function testPageIsNormalisedToABoundedInteger(mixed $raw, int $expectedPage): void
    {
        $query = $raw === null ? [] : ['page' => $raw];
        $p = Pagination::fromQuery($query, 20, 100);

        $this->assertSame($expectedPage, $p['page']);
        $this->assertIsInt($p['offset']);
        $this->assertSame(($expectedPage - 1) * 20, $p['offset']);
    }

    public static function pageSizeInputs(): array
    {
        return [
            'hiányzik' => [null, 20], '0' => ['0', 1], '1' => ['1', 1], '50' => ['50', 50],
            '100' => ['100', 100], '101' => ['101', 100], 'óriás' => [str_repeat('9', 50), 100],
            'abc' => ['abc', 1], '-5' => ['-5', 1],
        ];
    }

    /** @dataProvider pageSizeInputs */
    public function testPageSizeKeepsTheExistingContract(mixed $raw, int $expected): void
    {
        $query = $raw === null ? [] : ['page_size' => $raw];
        $this->assertSame($expected, Pagination::fromQuery($query, 20, 100)['page_size']);
    }

    public function testWorstCaseOffsetIsSmallAndIntegral(): void
    {
        $p = Pagination::fromQuery(['page' => (string) PHP_INT_MAX, 'page_size' => '100'], 20, 100);
        $this->assertSame((Pagination::MAX_PAGE - 1) * 100, $p['offset']);
        $this->assertLessThan(PHP_INT_MAX / 1000, $p['offset']);
    }

    public function testFuzzNeverThrowsAndAlwaysStaysInRange(): void
    {
        mt_srand(1515);
        $alphabet = str_split("0123456789+-. eE abc\t");
        $violations = [];
        for ($i = 0; $i < 3000; $i++) {
            $raw = '';
            for ($j = 0, $n = mt_rand(0, 40); $j < $n; $j++) {
                $raw .= $alphabet[mt_rand(0, count($alphabet) - 1)];
            }
            $p = Pagination::fromQuery(['page' => $raw, 'page_size' => strrev($raw)], 20, 100);
            if ($p['page'] < 1 || $p['page'] > Pagination::MAX_PAGE || $p['page_size'] < 1 || $p['page_size'] > 100
                || !is_int($p['offset']) || $p['offset'] !== ($p['page'] - 1) * $p['page_size']) {
                $violations[] = $raw;
            }
        }
        $this->assertSame([], $violations);
    }

    public function testBothEndpointsUseTheSharedPolicy(): void
    {
        foreach (['ai-history-list.php', 'ai-action-proposals-list.php'] as $name) {
            $src = file_get_contents(dirname(__DIR__) . '/webroot/api/' . $name);
            $this->assertStringContainsString('Pagination::fromQuery($_GET, 20, 100)', $src, $name);
            $this->assertStringNotContainsString("(int) \$_GET['page']", $src, $name);
        }
    }

    public function testDatabaseQueriesAcceptTheWorstCaseOffset(): void
    {
        $db = tests_new_database();
        $p = Pagination::fromQuery(['page' => (string) PHP_INT_MAX], 20, 100);
        $this->assertSame([], $db->getAiRunHistory([], $p['page_size'], $p['offset']));
        $this->assertSame([], $db->listActionProposals([], $p['page_size'], $p['offset']));
    }
}
