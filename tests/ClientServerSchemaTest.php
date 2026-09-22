<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * Fázis 2 (kliens/szerver architektúra) séma-alapja — migrateV28ClientServer().
 * Csak a séma/migráció helyességét teszteli (fresh install, inkrementális
 * migráció, idempotencia); a registered_clients/client_sessions tényleges
 * CRUD-metódusai, HMAC-hitelesítés és a ClientProxy önálló, később készülő
 * lépések (lásd a Fázis 2 tervdokumentumot) — itt még nincs Database::-
 * metódus rájuk, szándékosan.
 */
final class ClientServerSchemaTest extends TestCase
{
    public function testFreshInstallCreatesRegisteredClientsAndClientSessionsTables(): void
    {
        $db = tests_new_database();
        $pdoProp = new ReflectionProperty(Database::class, 'pdo');
        $pdoProp->setAccessible(true);
        $pdo = $pdoProp->getValue($db);

        foreach (['registered_clients', 'client_sessions'] as $table) {
            $cols = array_column($pdo->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC), 'name');
            $this->assertNotEmpty($cols, "A(z) $table táblának létre kellett volna jönnie friss telepítésnél.");
        }

        $registeredClientsCols = array_column($pdo->query("PRAGMA table_info(registered_clients)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        $this->assertEquals(
            ['id', 'client_id', 'label', 'secret_hash', 'is_active', 'revoked_at', 'rotated_at', 'last_seen_at', 'last_seen_version', 'created_at'],
            $registeredClientsCols
        );

        $clientSessionsCols = array_column($pdo->query("PRAGMA table_info(client_sessions)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        $this->assertEquals(
            ['id', 'client_session_id', 'registered_client_id', 'staff_id', 'csrf_token_hash', 'created_at', 'expires_at'],
            $clientSessionsCols
        );
    }

    public function testSchemaVersionIs29AfterFreshInstall(): void
    {
        $db = tests_new_database();
        $pdoProp = new ReflectionProperty(Database::class, 'pdo');
        $pdoProp->setAccessible(true);
        $pdo = $pdoProp->getValue($db);

        $version = (int) $pdo->query('SELECT version FROM schema_version LIMIT 1')->fetchColumn();
        $this->assertSame(29, $version);
    }

    public function testClientIdAndSessionIdAreUniquelyIndexed(): void
    {
        $db = tests_new_database();
        $pdoProp = new ReflectionProperty(Database::class, 'pdo');
        $pdoProp->setAccessible(true);
        $pdo = $pdoProp->getValue($db);

        $pdo->prepare("
            INSERT INTO registered_clients (client_id, label, secret_hash, created_at)
            VALUES ('cl_abc123', 'Teszt kassza 2', 'hash1', datetime('now'))
        ")->execute();

        $this->expectException(PDOException::class);
        $pdo->prepare("
            INSERT INTO registered_clients (client_id, label, secret_hash, created_at)
            VALUES ('cl_abc123', 'Másik gép', 'hash2', datetime('now'))
        ")->execute();
    }

    public function testMigratingFromV27PreservesExistingCashManagementTables(): void
    {
        // Valódi v27-es állapotot épít fel (Fázis 1 lezárt sémája), majd
        // visszaállítja schema_version-t 27-re, hogy a migrateV28ClientServer()
        // a TÉNYLEGES, éles upgrade-útvonalon fusson le, ne egy szintetikus
        // sémán — ugyanaz a minta, mint a Fázis 1 saját migrációs tesztjeié.
        $db1 = tests_new_database();
        $pdoProp = new ReflectionProperty(Database::class, 'pdo');
        $pdoProp->setAccessible(true);
        $pdo1 = $pdoProp->getValue($db1);
        $dbPathProp = new ReflectionProperty(Database::class, 'dbConfig');
        $dbPathProp->setAccessible(true);
        $dbPath = $dbPathProp->getValue($db1)['sqlite']['path'];
        unset($db1);

        $pdo = new PDO('sqlite:' . $dbPath);
        $pdo->exec('DROP TABLE registered_clients');
        $pdo->exec('DROP TABLE client_sessions');
        $pdo->exec('UPDATE schema_version SET version = 27');
        $pdo = null;

        $root = dirname(__DIR__);
        $db2 = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $root);

        $pdo2 = new PDO('sqlite:' . $dbPath);
        $cashCols = array_column($pdo2->query("PRAGMA table_info(cash_sessions)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        $this->assertNotEmpty($cashCols, 'A Fázis 1 cash_sessions táblájának változatlanul meg kell maradnia a V28 migráció után.');

        $clientSessionsCols = array_column($pdo2->query("PRAGMA table_info(client_sessions)")->fetchAll(PDO::FETCH_ASSOC), 'name');
        $this->assertNotEmpty($clientSessionsCols);

        $version = (int) $pdo2->query('SELECT version FROM schema_version LIMIT 1')->fetchColumn();
        $this->assertSame(29, $version);
    }

    public function testReRunningMigrationAgainstAnAlreadyMigratedDatabaseIsANoOp(): void
    {
        $db = tests_new_database();
        $pdoProp = new ReflectionProperty(Database::class, 'pdo');
        $pdoProp->setAccessible(true);
        $dbConfigProp = new ReflectionProperty(Database::class, 'dbConfig');
        $dbConfigProp->setAccessible(true);
        $dbPath = $dbConfigProp->getValue($db)['sqlite']['path'];
        unset($db);

        $root = dirname(__DIR__);
        $again = new Database(['driver' => 'sqlite', 'sqlite' => ['path' => $dbPath]], $root);
        $this->assertInstanceOf(Database::class, $again);
    }
}
