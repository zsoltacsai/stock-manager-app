<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/Support/AiFakeProviders.php';
require_once __DIR__ . '/../src/Ai/AiRunGuard.php';

/**
 * AI-09 — a futás közbeni jogosultsági checkpoint: AiRunGuard (a
 * _bootstrap/require_admin döntés újraértékelése) és az AgentRunner
 * checkpointjai (minden provider-hívás és minden tool-végrehajtás előtt).
 */
final class AiRunAuthorizationCheckpointTest extends TestCase
{
    private function adminId(Database $db): int
    {
        return $db->saveStaff(['name' => 'Guard Admin ' . bin2hex(random_bytes(3)), 'pin' => '4' . random_int(1000, 9999), 'role' => 'admin']);
    }

    private function setStaff(Database $db, int $id, string $role, bool $active): void
    {
        $db->saveStaff(['id' => $id, 'name' => 'Guard Admin', 'role' => $role, 'is_active' => $active]);
    }

    /** @return array{0:int,1:string} [registeredClientId, clientSessionId] */
    private function clientSession(Database $db, int $staffId, int $timeoutMinutes = 30): array
    {
        $client = $db->registerClient('Guard kliens');
        $session = $db->createClientSession((int) $client['id'], $staffId, hash('sha256', 'x'), $timeoutMinutes);
        return [(int) $client['id'], $session['client_session_id']];
    }

    // --- AiRunGuard ---

    public function testActiveAdminIsAuthorized(): void
    {
        $db = tests_new_database();
        $this->assertTrue(AiRunGuard::isStillAuthorized($db, $this->adminId($db), null, null));
    }

    public function testInstallWithoutStaffIsAuthorized(): void
    {
        $this->assertTrue(AiRunGuard::isStillAuthorized(tests_new_database(), null, null, null));
    }

    public function testDeactivatedStaffIsNotAuthorized(): void
    {
        $db = tests_new_database();
        $id = $this->adminId($db);
        $this->setStaff($db, $id, 'admin', false);
        $this->assertFalse(AiRunGuard::isStillAuthorized($db, $id, null, null));
    }

    public function testAdminDemotedToCashierIsNotAuthorized(): void
    {
        $db = tests_new_database();
        $id = $this->adminId($db);
        $this->setStaff($db, $id, 'cashier', true);
        $this->assertFalse(AiRunGuard::isStillAuthorized($db, $id, null, null));
    }

    public function testValidClientSessionIsAuthorized(): void
    {
        $db = tests_new_database();
        $staffId = $this->adminId($db);
        [$clientId, $sessionId] = $this->clientSession($db, $staffId);
        $this->assertTrue(AiRunGuard::isStillAuthorized($db, $staffId, $clientId, $sessionId));
    }

    public function testRevokedClientIsNotAuthorized(): void
    {
        $db = tests_new_database();
        $staffId = $this->adminId($db);
        [$clientId, $sessionId] = $this->clientSession($db, $staffId);
        $db->revokeClient($clientId);
        $this->assertFalse(AiRunGuard::isStillAuthorized($db, $staffId, $clientId, $sessionId));
    }

    public function testDisabledClientIsNotAuthorized(): void
    {
        $db = tests_new_database();
        $staffId = $this->adminId($db);
        [$clientId, $sessionId] = $this->clientSession($db, $staffId);
        $db->disableClient($clientId);
        $this->assertFalse(AiRunGuard::isStillAuthorized($db, $staffId, $clientId, $sessionId));
    }

    public function testDeletedClientSessionIsNotAuthorized(): void
    {
        $db = tests_new_database();
        $staffId = $this->adminId($db);
        [$clientId, $sessionId] = $this->clientSession($db, $staffId);
        $db->deleteClientSession($sessionId);
        $this->assertFalse(AiRunGuard::isStillAuthorized($db, $staffId, $clientId, $sessionId));
    }

    public function testExpiredClientSessionIsNotAuthorized(): void
    {
        $db = tests_new_database();
        $staffId = $this->adminId($db);
        [$clientId, $sessionId] = $this->clientSession($db, $staffId);
        $db->pdo()->prepare("UPDATE client_sessions SET expires_at = '2000-01-01 00:00:00' WHERE client_session_id = ?")->execute([$sessionId]);
        $this->assertFalse(AiRunGuard::isStillAuthorized($db, $staffId, $clientId, $sessionId));
    }

    public function testClientSessionOfAnotherClientOrStaffIsNotAuthorized(): void
    {
        $db = tests_new_database();
        $staffId = $this->adminId($db);
        $otherStaff = $this->adminId($db);
        [$clientId, $sessionId] = $this->clientSession($db, $staffId);
        [$otherClient] = $this->clientSession($db, $staffId);
        $this->assertFalse(AiRunGuard::isStillAuthorized($db, $staffId, $otherClient, $sessionId));
        $this->assertFalse(AiRunGuard::isStillAuthorized($db, $otherStaff, $clientId, $sessionId));
        $this->assertFalse(AiRunGuard::isStillAuthorized($db, $staffId, $clientId, null));
    }

    // --- AgentRunner checkpointok ---

    private function countingRegistry(object $counter): ToolRegistry
    {
        $registry = new ToolRegistry();
        $registry->register(new ToolDefinition('counted_tool', 'teszt', ['type' => 'object', 'properties' => []], function (array $a) use ($counter) {
            $counter->count++;
            return ['ok' => true];
        }));
        return $registry;
    }

    /** @return ToolCall[] */
    private function calls(int $n): array
    {
        return array_map(static fn ($i) => new ToolCall("c$i", 'counted_tool', []), range(0, $n - 1));
    }

    public function testRevocationBetweenToolCallsOfOneResponseStopsFurtherTools(): void
    {
        $counter = new class { public int $count = 0; };
        $context = new AiRunContext(20, fn () => $counter->count < 3);
        $provider = new FakeAiProvider([new AiChatResponse(null, $this->calls(10))]);
        $runner = new AgentRunner($provider, $this->countingRegistry($counter), 5, null, null, true, $context);

        $result = $runner->run('sys', 'q');

        $this->assertFalse($result->success);
        $this->assertSame('authorization_revoked', $result->failureCategory);
        $this->assertSame(3, $counter->count);
        $this->assertSame(1, $provider->callCount());
    }

    public function testRevocationBeforeNextProviderIterationStopsTheRun(): void
    {
        $counter = new class { public int $count = 0; };
        $checks = 0;
        // 1. iteráció eleje + 2 tool előtt = 3 sikeres ellenőrzés; a 2. iteráció előtt visszavonva.
        $context = new AiRunContext(20, function () use (&$checks) { return ++$checks <= 3; });
        $provider = new FakeAiProvider([
            new AiChatResponse(null, $this->calls(2)),
            new AiChatResponse('nem kérjük le', []),
        ]);
        $events = [];
        $runner = new AgentRunner($provider, $this->countingRegistry($counter), 5, null, null, true, $context);

        $result = $runner->runStreaming('sys', 'q', function (AiStreamEvent $e) use (&$events) { $events[] = $e; }, 'inventory');

        $this->assertSame('authorization_revoked', $result->failureCategory);
        $this->assertSame(1, $provider->callCount(), 'a visszavonás után nincs újabb provider-hívás');
        $this->assertSame(2, $counter->count);
        $this->assertSame('error', end($events)->type);
    }

    public function testFailingAuthorizationCheckFailsClosed(): void
    {
        $context = new AiRunContext(20, function () { throw new RuntimeException('DB nem elérhető'); });
        $log = tempnam(sys_get_temp_dir(), 'ai09');
        $prev = ini_set('error_log', $log);
        try {
            $this->assertFalse($context->isStillAuthorized());
        } finally {
            ini_set('error_log', (string) $prev);
        }
        $this->assertStringContainsString('checkpoint sikertelen', (string) file_get_contents($log));
        @unlink($log);
    }

    /**
     * Valódi Database + AiRunGuard + InventoryAgent: a dolgozót a provider-hívás
     * KÖZBEN (a fake provider második válasza előtt) deaktiválják — a következő
     * tool nem fut le.
     */
    public function testStaffDeactivatedDuringProviderCallStopsInventoryAgentRun(): void
    {
        $db = tests_new_database();
        $staffId = $this->adminId($db);
        $context = AiRunContext::fromSettings([], fn () => AiRunGuard::isStillAuthorized($db, $staffId, null, null));
        $provider = new class ($db, $staffId) implements AiProviderInterface {
            public int $calls = 0;
            public function __construct(private Database $db, private int $staffId) {}
            public function name(): string { return 'fake'; }
            public function checkAvailability(): AiAvailability { return AiAvailability::available(); }
            public function chat(array $messages, array $tools): AiChatResponse
            {
                if (++$this->calls === 2) {
                    $this->db->saveStaff(['id' => $this->staffId, 'name' => 'x', 'role' => 'admin', 'is_active' => false]);
                }
                return new AiChatResponse(null, [new ToolCall('t' . $this->calls, 'get_low_stock_products', [])]);
            }
        };

        $result = (new InventoryAgent($provider, $db, [], 5, $context))->answer('Mi fogy ki?');

        $this->assertSame('authorization_revoked', $result->failureCategory);
        $this->assertSame(2, $provider->calls);
        $this->assertSame(1, $context->toolCallsUsed(), 'csak a deaktiválás előtti tool futott le');
    }

    public function testClientRevokedDuringCopilotRunStopsSubAgentTools(): void
    {
        $db = tests_new_database();
        $staffId = $this->adminId($db);
        [$clientId, $sessionId] = $this->clientSession($db, $staffId);
        $context = AiRunContext::fromSettings([], fn () => AiRunGuard::isStillAuthorized($db, $staffId, $clientId, $sessionId));
        $provider = new class ($db, $clientId) implements AiProviderInterface {
            public int $calls = 0;
            public function __construct(private Database $db, private int $clientId) {}
            public function name(): string { return 'fake'; }
            public function checkAvailability(): AiAvailability { return AiAvailability::available(); }
            public function chat(array $messages, array $tools): AiChatResponse
            {
                $this->calls++;
                if ($this->calls === 1) {
                    return new AiChatResponse(null, [new ToolCall('d1', 'ask_inventory_agent', ['question' => 'Mi fogy ki?'])]);
                }
                // Az al-ügynök provider-hívása közben visszavonják a kliens-gépet.
                $this->db->revokeClient($this->clientId);
                return new AiChatResponse(null, [new ToolCall('t1', 'get_low_stock_products', [])]);
            }
        };

        $result = (new AiCopilot($provider, $db, [], 5, $context))->answer('Mi fogy ki?');

        $this->assertFalse($result->success);
        $this->assertSame(1, $context->toolCallsUsed(), 'csak a delegálás futott, az al-ügynök toolja nem');
        $this->assertSame(2, $provider->calls, 'a Copilot sem hívja újra a providert');
    }
}
