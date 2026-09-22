<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class ClientNonceStoreTest extends TestCase
{
    private function uniqueClientId(): string
    {
        return 'cl_test_' . bin2hex(random_bytes(6));
    }

    public function testFirstUseOfANonceIsAccepted(): void
    {
        $this->assertTrue(ClientNonceStore::claim($this->uniqueClientId(), bin2hex(random_bytes(8)), 120));
    }

    public function testReusingTheSameNonceForTheSameClientIsRejected(): void
    {
        // A user pontosan ezt kérte tesztként: request A + nonce X kétszer.
        $clientId = $this->uniqueClientId();
        $nonce = bin2hex(random_bytes(8));
        $this->assertTrue(ClientNonceStore::claim($clientId, $nonce, 120), 'Az első felhasználásnak sikeresnek kell lennie.');
        $this->assertFalse(ClientNonceStore::claim($clientId, $nonce, 120), 'A második, megismételt felhasználásnak el kell buknia.');
    }

    public function testADifferentNonceForTheSameClientIsIndependentlyAccepted(): void
    {
        $clientId = $this->uniqueClientId();
        $this->assertTrue(ClientNonceStore::claim($clientId, 'nonceX', 120));
        $this->assertTrue(ClientNonceStore::claim($clientId, 'nonceY', 120), 'Egy MÁSIK nonce ugyanahhoz a klienshez önállóan elfogadható kell legyen.');
    }

    public function testTheSameNonceForADifferentClientIsIndependentlyAccepted(): void
    {
        $nonce = bin2hex(random_bytes(8));
        $this->assertTrue(ClientNonceStore::claim('cl_a_' . bin2hex(random_bytes(4)), $nonce, 120));
        $this->assertTrue(ClientNonceStore::claim('cl_b_' . bin2hex(random_bytes(4)), $nonce, 120), 'Két KÜLÖNBÖZŐ kliens nonce-tere egymástól független.');
    }

    public function testEmptyClientIdOrNonceIsAlwaysRejected(): void
    {
        $this->assertFalse(ClientNonceStore::claim('', 'nonce', 120));
        $this->assertFalse(ClientNonceStore::claim('cl_x', '', 120));
    }

    public function testExpiredEntriesAreEvictedAndDoNotGrowTheStoreUnbounded(): void
    {
        $clientId = $this->uniqueClientId();
        // 0 másodperces ablak — a bejegyzés a KÖVETKEZŐ hívás pillanatában
        // már lejártnak számít, tehát ugyanaz a nonce újra elfogadható.
        $this->assertTrue(ClientNonceStore::claim($clientId, 'shortlived', 0));
        sleep(1);
        $this->assertTrue(ClientNonceStore::claim($clientId, 'shortlived', 120), 'Egy lejárt bejegyzésnek ki kell gyomlálódnia, nem véglegesen foglalnia a nonce-ot.');
    }

    public function testManyDistinctNoncesForOneClientAllSucceedIndependently(): void
    {
        $clientId = $this->uniqueClientId();
        for ($i = 0; $i < 20; $i++) {
            $this->assertTrue(ClientNonceStore::claim($clientId, "nonce-$i", 120));
        }
        // Mind a 20 újra próbálva már mind elutasítva.
        for ($i = 0; $i < 20; $i++) {
            $this->assertFalse(ClientNonceStore::claim($clientId, "nonce-$i", 120));
        }
    }
}
