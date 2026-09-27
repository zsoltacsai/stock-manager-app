<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * PERF-07 — a napi dátumszűrők indexelhető tartomány-feltétele
 * (`col >= 'from' AND col < 'to+1'`) pontosan ugyanazokat a sorokat adja,
 * mint a korábbi `substr(col, 1, 10) BETWEEN from AND to`: határidőpontok
 * (00:00:00, 23:59:59), hónap-/év-/szökőnap-határ, a DST-váltás napjai, régi
 * ISO-alakú és csak dátumot tartalmazó értékek, NULL. Nem 'ÉÉÉÉ-HH-NN'
 * (vagy nem létező) dátumnál a korábbi kifejezés marad. A feltétel az indexet
 * használja.
 */
final class DayRangeFilterTest extends TestCase
{
    private string $dir;
    private Database $db;

    protected function setUp(): void
    {
        $this->dir = sys_get_temp_dir() . '/sm_dayrange_' . bin2hex(random_bytes(5));
        mkdir($this->dir, 0775, true);
        $this->db = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $this->dir . '/db.sqlite']], dirname(__DIR__));
        $values = [];
        foreach (['2024-02-28', '2024-02-29', '2024-03-01', '2025-12-31', '2026-01-01', '2026-03-28', '2026-03-29', '2026-03-30',
                  '2026-10-24', '2026-10-25', '2026-10-26', '2026-09-30', '2026-10-01'] as $day) {
            foreach (['00:00:00', '00:00:01', '12:30:00', '23:59:59'] as $time) {
                $values[] = "$day $time";
            }
            $values[] = $day;                          // csak dátum
            $values[] = $day . 'T23:59:59+02:00';      // régi ISO-alak
            $values[] = $day . ' 23:59:59.999';
        }
        $values[] = null;
        $pdo = $this->db->pdo();
        $pdo->exec('DROP INDEX IF EXISTS idx_dayrange_probe');
        $pdo->exec('CREATE TABLE dayrange_probe (id INTEGER PRIMARY KEY, created_at TEXT)');
        $pdo->exec('CREATE INDEX idx_dayrange_probe ON dayrange_probe(created_at)');
        $stmt = $pdo->prepare('INSERT INTO dayrange_probe (created_at) VALUES (?)');
        foreach ($values as $v) {
            $stmt->execute([$v]);
        }
    }

    protected function tearDown(): void
    {
        unset($this->db);
        foreach (glob($this->dir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->dir);
    }

    private function condition(string $column, string $from, ?string $to = null): array
    {
        $m = new ReflectionMethod(Database::class, 'dayRangeCondition');
        return $m->invoke($this->db, $column, $from, $to);
    }

    private function idsWhere(string $where, array $params): array
    {
        $stmt = $this->db->pdo()->prepare("SELECT id FROM dayrange_probe WHERE $where ORDER BY id");
        $stmt->execute($params);
        return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /** @return array<string, array{string, string}> */
    public static function ranges(): array
    {
        return [
            'egy nap'               => ['2026-03-29', '2026-03-29'],
            'DST tavasz'            => ['2026-03-28', '2026-03-30'],
            'DST ősz'               => ['2026-10-25', '2026-10-25'],
            'hónaphatár'            => ['2026-09-30', '2026-10-01'],
            'évhatár'               => ['2025-12-31', '2026-01-01'],
            'szökőnap'              => ['2024-02-29', '2024-02-29'],
            'szökőnap körül'        => ['2024-02-28', '2024-03-01'],
            'teljes tartomány'      => ['2000-01-01', '2099-12-31'],
            'fordított tartomány'   => ['2026-10-01', '2026-09-30'],
            'üres nap'              => ['2026-05-05', '2026-05-05'],
        ];
    }

    /**
     * @dataProvider ranges
     */
    public function testRangeConditionSelectsExactlyTheRowsOfTheFormerSubstrPredicate(string $from, string $to): void
    {
        [$where, $params] = $this->condition('created_at', $from, $to);
        $this->assertSame('created_at >= ? AND created_at < ?', $where);
        $this->assertSame($this->idsWhere('substr(created_at, 1, 10) BETWEEN ? AND ?', [$from, $to]), $this->idsWhere($where, $params));
    }

    public function testSingleDayFormEqualsTheFormerEqualityPredicate(): void
    {
        foreach (['2026-03-29', '2026-10-25', '2024-02-29', '2025-12-31'] as $day) {
            [$where, $params] = $this->condition('created_at', $day);
            $this->assertSame($this->idsWhere('substr(created_at, 1, 10) = ?', [$day]), $this->idsWhere($where, $params), $day);
        }
    }

    public function testNonIsoOrInvalidDatesKeepThePreviousExpression(): void
    {
        foreach ([['2026-9-1', '2026-9-30'], ['2026-02-30', '2026-03-01'], ['', ''], ['2026-03-29', 'x'], ['2026-13-01', '2026-13-02']] as [$from, $to]) {
            [$where, $params] = $this->condition('created_at', $from, $to);
            $this->assertSame('substr(created_at, 1, 10) BETWEEN ? AND ?', $where, "$from..$to");
            $this->assertSame([$from, $to], $params);
        }
    }

    public function testRangeConditionUsesTheCreatedAtIndex(): void
    {
        [$where, $params] = $this->condition('created_at', '2026-03-29', '2026-03-30');
        $plan = implode(' | ', array_column($this->db->pdo()->query('EXPLAIN QUERY PLAN SELECT COUNT(*) FROM sales WHERE ' . str_replace('?', "'x'", $where))->fetchAll(PDO::FETCH_ASSOC), 'detail'));
        $this->assertStringContainsString('idx_sales_created_at', $plan);
        $old = implode(' | ', array_column($this->db->pdo()->query("EXPLAIN QUERY PLAN SELECT COUNT(*) FROM sales WHERE substr(created_at, 1, 10) BETWEEN 'a' AND 'b'")->fetchAll(PDO::FETCH_ASSOC), 'detail'));
        $this->assertStringNotContainsString('created_at>', $old, 'A korábbi kifejezés nem tudta használni az indexet (összevetés).');
    }
}
