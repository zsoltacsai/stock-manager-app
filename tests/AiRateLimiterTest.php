<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 9 — AiRateLimiter (a kör 19. pontja: "accidental abuse"
 * védelem, a MEGLÉVŐ audit_log-ra épülve, nincs új tábla).
 */
final class AiRateLimiterTest extends TestCase
{
    public function testFirstRequestIsAlwaysAllowed(): void
    {
        $db = tests_new_database();
        $result = AiRateLimiter::check($db, ['ai_min_seconds_between_requests' => 5], 1);
        $this->assertTrue($result['ok']);
    }

    public function testImmediateSecondRequestIsBlocked(): void
    {
        $db = tests_new_database();
        $staffId = $db->saveStaff(['name' => 'Teszt', 'pin' => '1234', 'role' => 'admin']);
        $db->logAudit($staffId, 'ai_agent_run', 'ai_agent', null, 'teszt', 30);
        $result = AiRateLimiter::check($db, ['ai_min_seconds_between_requests' => 5], $staffId);
        $this->assertFalse($result['ok']);
        $this->assertGreaterThan(0, $result['retry_after_seconds']);
    }

    public function testDifferentStaffIsNotBlockedByAnothersRequest(): void
    {
        $db = tests_new_database();
        $staffId1 = $db->saveStaff(['name' => 'Teszt1', 'pin' => '1234', 'role' => 'admin']);
        $staffId2 = $db->saveStaff(['name' => 'Teszt2', 'pin' => '5678', 'role' => 'admin']);
        $db->logAudit($staffId1, 'ai_agent_run', 'ai_agent', null, 'teszt', 30);
        $result = AiRateLimiter::check($db, ['ai_min_seconds_between_requests' => 5], $staffId2);
        $this->assertTrue($result['ok']);
    }

    public function testNullStaffIdOnNoPinSystemWorksConsistently(): void
    {
        $db = tests_new_database();
        $db->logAudit(null, 'ai_agent_run', 'ai_agent', null, 'teszt', 30);
        $result = AiRateLimiter::check($db, ['ai_min_seconds_between_requests' => 5], null);
        $this->assertFalse($result['ok'], 'Egy dolgozói PIN-rendszer nélküli (null staff_id) telepítésen is érvényesülnie kell a hűtési időnek.');
    }

    public function testZeroConfiguredLimitDisablesRateLimiting(): void
    {
        $db = tests_new_database();
        $staffId = $db->saveStaff(['name' => 'Teszt', 'pin' => '1234', 'role' => 'admin']);
        $db->logAudit($staffId, 'ai_agent_run', 'ai_agent', null, 'teszt', 30);
        $result = AiRateLimiter::check($db, ['ai_min_seconds_between_requests' => 0], $staffId);
        $this->assertTrue($result['ok']);
    }

    public function testOldEnoughPreviousRequestIsAllowed(): void
    {
        $db = tests_new_database();
        $staffId = $db->saveStaff(['name' => 'Teszt', 'pin' => '1234', 'role' => 'admin']);
        $db->logAudit($staffId, 'ai_agent_run', 'ai_agent', null, 'teszt', 30);
        $pdoProp = new ReflectionProperty(Database::class, 'pdo');
        $pdoProp->setAccessible(true);
        $pdo = $pdoProp->getValue($db);
        $pdo->exec("UPDATE audit_log SET created_at = datetime('now', '-1 hour')");

        $result = AiRateLimiter::check($db, ['ai_min_seconds_between_requests' => 5], $staffId);
        $this->assertTrue($result['ok']);
    }

    public function testDoesNotBlockNormalPosOperationOtherAuditActions(): void
    {
        // A kör 19. pontja — "Do not block normal POS operation" — ez a
        // limit KIZÁRÓLAG az 'ai_agent_run' akciót nézi, egy MÁS,
        // nem-AI audit_log bejegyzés (pl. termék-mentés) SOSE
        // befolyásolja.
        $db = tests_new_database();
        $staffId = $db->saveStaff(['name' => 'Teszt', 'pin' => '1234', 'role' => 'admin']);
        $db->logAudit($staffId, 'product_save', 'product', 1, 'teszt', 30);
        $result = AiRateLimiter::check($db, ['ai_min_seconds_between_requests' => 5], $staffId);
        $this->assertTrue($result['ok']);
    }
}
