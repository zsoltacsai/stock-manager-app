<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 7 — a kör 2/3/25. pontja: az AI futás-előzmények lekérdező
 * rétegének (Database::getAiRunHistory()/countAiRunHistory()/
 * getAiRunHistoryEntry()) tesztjei, KÖZVETLENÜL a Database ellen, LLM
 * nélkül — a MEGLÉVŐ system_events (category='ai') táblát olvassák,
 * amit egy ÖNÁLLÓ segédfüggvény (seedAiEvent()) tölt fel, közvetlenül
 * a Database::logSystemEvent() valódi hívásán keresztül (nincs
 * párhuzamos írási útvonal a teszthez).
 */
final class AiHistoryQueryTest extends TestCase
{
    private function seedAiEvent(
        Database $db,
        string $agent = 'sales',
        string $provider = 'local',
        string $status = 'success',
        array $extra = []
    ): void {
        $detail = array_merge([
            'agent' => $agent,
            'provider' => $provider,
            'model' => 'qwen3:8b',
            'tools_used' => ['get_sales_summary'],
            'iterations' => 2,
            'duration_ms' => 123,
            'error' => $status === 'failure' ? 'Az AI-modell jelenleg nem érhető el.' : null,
        ], $extra);

        // FONTOS: nagy (365 napos) retentiont adunk át — a logSystemEvent()
        // MINDEN híváskor lefuttat egy "DELETE ... WHERE created_at < cutoff"
        // takarítást a SAJÁT, ténylegesen "most" időbélyegéhez képest (lásd
        // a metódus docblokkja). Ha ez a teszt egy KORÁBBI hívással már
        // visszadátumozott ('backdateLatestAiEvent()') sort egy rövid
        // retentionnel írt volna, a KÖVETKEZŐ seedAiEvent()-hívás takarítása
        // AZONNAL törölte volna azt — élőben megfigyelt hiba, nem elméleti.
        $db->logSystemEvent(
            'ai',
            'agent_run',
            $status === 'failure' ? 'warning' : 'info',
            $status,
            $status === 'success' ? "AI-agent futás sikeres ($agent)." : "AI-agent futás sikertelen ($agent).",
            json_encode($detail, JSON_UNESCAPED_UNICODE),
            365
        );
    }

    /** Közvetlen SQL-lel állít be egy MÚLTBELI created_at-ot — a logSystemEvent() mindig "most"-ot ír, a rendezés-teszthez ez KELL, hogy determinisztikusan eltérő időbélyegek legyenek. */
    private function backdateLatestAiEvent(Database $db, string $createdAt): void
    {
        $id = (int) $db->pdo()->query("SELECT MAX(id) FROM system_events WHERE category='ai'")->fetchColumn();
        $db->pdo()->prepare('UPDATE system_events SET created_at = ? WHERE id = ?')->execute([$createdAt, $id]);
    }

    public function testOnlyAiCategoryEventsAreListed(): void
    {
        $db = tests_new_database();
        $this->seedAiEvent($db, 'sales');
        $db->logSystemEvent('backup', 'run', 'info', 'success', 'Mentés kész.', null, 14);

        $rows = $db->getAiRunHistory();

        $this->assertCount(1, $rows);
        $this->assertSame('sales', $rows[0]['agent']);
    }

    public function testOrderingIsNewestFirst(): void
    {
        $db = tests_new_database();
        $this->seedAiEvent($db, 'inventory');
        $this->backdateLatestAiEvent($db, '2026-01-01 08:00:00');
        $this->seedAiEvent($db, 'sales');
        $this->backdateLatestAiEvent($db, '2026-01-03 08:00:00');
        $this->seedAiEvent($db, 'anomaly');
        $this->backdateLatestAiEvent($db, '2026-01-02 08:00:00');

        $rows = $db->getAiRunHistory();

        $this->assertSame(['sales', 'anomaly', 'inventory'], array_column($rows, 'agent'));
    }

    public function testPaginationRespectsLimitAndOffset(): void
    {
        $db = tests_new_database();
        for ($i = 0; $i < 25; $i++) {
            $this->seedAiEvent($db, 'sales');
            $this->backdateLatestAiEvent($db, sprintf('2026-01-%02d 08:00:00', $i + 1));
        }

        $page1 = $db->getAiRunHistory([], 10, 0);
        $page2 = $db->getAiRunHistory([], 10, 10);
        $page3 = $db->getAiRunHistory([], 10, 20);
        $total = $db->countAiRunHistory();

        $this->assertCount(10, $page1);
        $this->assertCount(10, $page2);
        $this->assertCount(5, $page3);
        $this->assertSame(25, $total);
        // Az első oldal legfrissebbje 2026-01-25, a lapozás nem fedi egymást.
        $this->assertNotSame($page1[0]['id'], $page2[0]['id']);
    }

    public function testPageSizeIsBoundedEvenWithAnOversizedRequest(): void
    {
        $db = tests_new_database();
        for ($i = 0; $i < 5; $i++) {
            $this->seedAiEvent($db, 'sales');
        }

        // A kör 3. pontja — "Maximum page size must be bounded": egy
        // 999999-es kérés se okozzon korlátlan lekérdezést.
        $rows = $db->getAiRunHistory([], 999999, 0);

        $this->assertLessThanOrEqual(100, count($rows));
    }

    public function testFilterByAgent(): void
    {
        $db = tests_new_database();
        $this->seedAiEvent($db, 'sales');
        $this->seedAiEvent($db, 'inventory');
        $this->seedAiEvent($db, 'anomaly');

        $rows = $db->getAiRunHistory(['agent' => 'inventory']);

        $this->assertCount(1, $rows);
        $this->assertSame('inventory', $rows[0]['agent']);
    }

    public function testFilterByProvider(): void
    {
        $db = tests_new_database();
        $this->seedAiEvent($db, 'sales', 'local');
        $this->seedAiEvent($db, 'sales', 'anthropic');
        $this->seedAiEvent($db, 'sales', 'openai');

        $rows = $db->getAiRunHistory(['provider' => 'anthropic']);

        $this->assertCount(1, $rows);
        $this->assertSame('anthropic', $rows[0]['provider']);
    }

    public function testFilterBySuccessFailureStatus(): void
    {
        $db = tests_new_database();
        $this->seedAiEvent($db, 'sales', 'local', 'success');
        $this->seedAiEvent($db, 'sales', 'local', 'failure');

        $failures = $db->getAiRunHistory(['status' => 'failure']);
        $successes = $db->getAiRunHistory(['status' => 'success']);

        $this->assertCount(1, $failures);
        $this->assertSame('failure', $failures[0]['status']);
        $this->assertCount(1, $successes);
    }

    public function testFilterByDateRange(): void
    {
        $db = tests_new_database();
        $this->seedAiEvent($db, 'sales');
        $this->backdateLatestAiEvent($db, '2026-01-01 08:00:00');
        $this->seedAiEvent($db, 'sales');
        $this->backdateLatestAiEvent($db, '2026-06-15 08:00:00');
        $this->seedAiEvent($db, 'sales');
        $this->backdateLatestAiEvent($db, '2026-12-31 08:00:00');

        $rows = $db->getAiRunHistory(['date_from' => '2026-03-01', 'date_to' => '2026-09-01']);

        $this->assertCount(1, $rows);
    }

    public function testCombinedFiltersNarrowCorrectly(): void
    {
        $db = tests_new_database();
        $this->seedAiEvent($db, 'sales', 'local', 'success');
        $this->seedAiEvent($db, 'sales', 'local', 'failure');
        $this->seedAiEvent($db, 'anomaly', 'local', 'failure');

        $rows = $db->getAiRunHistory(['agent' => 'sales', 'status' => 'failure']);

        $this->assertCount(1, $rows);
        $this->assertSame('sales', $rows[0]['agent']);
        $this->assertSame('failure', $rows[0]['status']);
    }

    public function testHistoryEntryExposesSafeStructuredFields(): void
    {
        $db = tests_new_database();
        $this->seedAiEvent($db, 'copilot', 'anthropic', 'success', [
            'agents_used' => ['sales', 'inventory'],
            'tools_used' => ['get_sales_summary', 'get_stock_status'],
        ]);

        $rows = $db->getAiRunHistory();
        $entry = $db->getAiRunHistoryEntry((int) $rows[0]['id']);

        $this->assertNotNull($entry);
        $this->assertSame('copilot', $entry['agent']);
        $this->assertSame(['sales', 'inventory'], $entry['agents_used']);
        $this->assertSame(['get_sales_summary', 'get_stock_status'], $entry['tools_used']);
    }

    public function testDetailReturnsNullForMissingOrNonAiEntry(): void
    {
        $db = tests_new_database();
        $db->logSystemEvent('backup', 'run', 'info', 'success', 'Mentés kész.', null, 14);
        $backupEventId = (int) $db->pdo()->query('SELECT MAX(id) FROM system_events')->fetchColumn();

        $this->assertNull($db->getAiRunHistoryEntry(999999));
        $this->assertNull($db->getAiRunHistoryEntry($backupEventId));
    }

    public function testRawTechnicalDetailJsonNeverLeaksOutOfDecoratedRows(): void
    {
        $db = tests_new_database();
        $this->seedAiEvent($db, 'sales');

        $rows = $db->getAiRunHistory();

        $this->assertArrayNotHasKey('technical_detail', $rows[0]);
    }

    public function testBoundedQuestionAndErrorTextSurviveIntact(): void
    {
        // Az AiAuditLogger MÁR bounded-re vágja a kérdést/hibát (lásd ott
        // MAX_QUESTION_LENGTH/MAX_ERROR_LENGTH) — itt csak azt igazoljuk,
        // hogy a lekérdező réteg NEM vág le/torzít semmit EZEN FELÜL.
        $db = tests_new_database();
        $longError = str_repeat('x', 200);
        $this->seedAiEvent($db, 'sales', 'local', 'failure', ['error' => $longError]);

        $rows = $db->getAiRunHistory();

        $this->assertSame($longError, $rows[0]['detail_error']);
    }
}
