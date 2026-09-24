<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 8A — a kör 25. pontja: "Copilot may NOT approve/reject proposals.
 * Do not add approval as an LLM tool." Ez a fázis SZÁNDÉKOSAN NEM adott
 * a Copilot-nak semmilyen javaslat-tudatosságot (lásd README "Ismert
 * korlátok") — az AiCopilot.php EGYETLEN sora sem módosult, a MEGLÉVŐ,
 * audit­ált, pontosan három (ask_inventory_agent/ask_sales_agent/
 * ask_anomaly_agent) eszközt tartalmazó ToolRegistry-je változatlan. Ez a
 * teszt ezt bizonyítja — forrás-vizsgálattal (a kör 25. pontjának
 * elfogadási kritériuma trivilálisan teljesül, ha a képesség EGYÁLTALÁN
 * nem létezik) ÉS ténylegesen lefuttatott ToolRegistry-ellenőrzéssel.
 */
final class ActionProposalCopilotBoundaryTest extends TestCase
{
    public function testCopilotSourceNeverReferencesActionProposalApproval(): void
    {
        $source = file_get_contents(dirname(__DIR__) . '/src/Ai/Agents/AiCopilot.php');
        $this->assertStringNotContainsString('ActionProposal', $source);
        $this->assertStringNotContainsString('approve', strtolower($source));
        $this->assertStringNotContainsString('reject', strtolower($source));
    }

    public function testCopilotToolRegistryContainsExactlyTheThreeOriginalAgentTools(): void
    {
        // A Copilot::answer() belsőleg épít egy ÚJ ToolRegistry-t minden
        // híváskor (lásd AiCopilot.php) — ez az anonim AiProviderInterface-
        // implementáció UGYANAZT a mintát követi, mint a Fázis 7
        // AiDailyIntelligenceTest.php "üres ToolRegistry" bizonyítéka:
        // közvetlenül megvizsgálja, milyen eszközöket kapott a chat()-hívás.
        $capturedTools = null;
        $provider = new class($capturedTools) implements AiProviderInterface {
            private $captured;
            public function __construct(&$captured) { $this->captured = &$captured; }
            public function name(): string { return 'fake'; }
            public function chat(array $messages, array $tools): AiChatResponse
            {
                $this->captured = $tools;
                return new AiChatResponse('Teszt válasz.', []);
            }
            public function checkAvailability(): AiAvailability { return AiAvailability::available(); }
        };
        $db = tests_new_database();
        $copilot = new AiCopilot($provider, $db, []);

        $copilot->answer('Teszt kérdés.');

        $this->assertNotNull($capturedTools);
        $toolNames = array_map(static fn(ToolDefinition $t) => $t->name, $capturedTools);
        sort($toolNames);
        $this->assertSame(['ask_anomaly_agent', 'ask_inventory_agent', 'ask_sales_agent'], $toolNames);
    }

    // ------------------------------------------------------------------
    // Fázis 8B — a kör 17. pontja: "DO NOT give the LLM an execute tool.
    // Do NOT add execute_action/approve_proposal/reject_proposal to
    // ToolRegistry." Forrás- ÉS futásidejű bizonyíték, hogy SEM a
    // Copilot, SEM a három domain-agent nem kapott ilyen eszközt.
    // ------------------------------------------------------------------

    public function testNoAiSourceFileReferencesActionExecutorOrExecuteTools(): void
    {
        $srcDir = dirname(__DIR__) . '/src/Ai';
        $forbiddenNeedles = ['execute_action', 'approve_proposal', 'reject_proposal'];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($srcDir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }
            $path = $file->getPathname();
            // Az ActionExecutor/ExecutableActionStrategy/Executors/* fájlok
            // MAGUK a végrehajtási keretrendszer — értelemszerűen
            // tartalmazzák a saját nevüket/fogalmaikat; ez a teszt azt
            // ellenőrzi, hogy a TOOL-regisztráló agent-fájlok (Copilot/
            // InventoryAgent/SalesAgent/AnomalyAgent) SOSE hivatkoznak
            // ezekre mint LLM-nek felkínált eszközre.
            if (str_contains($path, DIRECTORY_SEPARATOR . 'Executors' . DIRECTORY_SEPARATOR)
                || str_ends_with($path, 'ActionExecutor.php')
                || str_ends_with($path, 'ExecutableActionStrategy.php')
                || str_ends_with($path, 'ActionExecutionStaleException.php')
                || str_ends_with($path, 'ActionProposal.php')
                || str_ends_with($path, 'ActionProposalService.php')
            ) {
                continue;
            }
            $source = strtolower((string) file_get_contents($path));
            foreach ($forbiddenNeedles as $needle) {
                $this->assertStringNotContainsString($needle, $source, "$path SOSE hivatkozhat '$needle'-re — az LLM nem kaphat végrehajtási/jóváhagyási eszközt.");
            }
        }
    }

    public function testInventorySalesAnomalyAgentToolRegistriesContainNoExecuteTool(): void
    {
        $db = tests_new_database();
        $capturedByAgent = [];

        foreach (['InventoryAgent', 'SalesAgent', 'AnomalyAgent'] as $agentClass) {
            $captured = null;
            $provider = new class($captured) implements AiProviderInterface {
                private $captured;
                public function __construct(&$captured) { $this->captured = &$captured; }
                public function name(): string { return 'fake'; }
                public function chat(array $messages, array $tools): AiChatResponse
                {
                    $this->captured = $tools;
                    return new AiChatResponse('Teszt válasz.', []);
                }
                public function checkAvailability(): AiAvailability { return AiAvailability::available(); }
            };
            $agent = new $agentClass($provider, $db, []);
            $agent->answer('Teszt kérdés.');
            $capturedByAgent[$agentClass] = $captured;
        }

        foreach ($capturedByAgent as $agentClass => $tools) {
            $this->assertNotNull($tools, "$agentClass nem kapott eszközlistát a teszthez.");
            $toolNames = array_map(static fn(ToolDefinition $t) => strtolower($t->name), $tools);
            foreach (['execute_action', 'approve_proposal', 'reject_proposal', 'execute'] as $forbidden) {
                $this->assertNotContains($forbidden, $toolNames, "$agentClass ToolRegistry-je SOSE tartalmazhat '$forbidden' nevű eszközt.");
            }
        }
    }
}
