<?php

declare(strict_types=1);

// Minimális, önálló teszt-bootstrap — nincs Composer autoload ebben a
// projektben, úgyhogy a src/ osztályokat közvetlenül requireoljuk.
require_once __DIR__ . '/../src/Database.php';
require_once __DIR__ . '/../src/Settings.php';
require_once __DIR__ . '/../src/GeoBlocker.php';
require_once __DIR__ . '/../src/SimpleXlsWriter.php';

/**
 * Minden tesztfüggvény saját, egyszer használatos SQLite fájllal dolgozik
 * (nem az éles data/stock.sqlite-tal!), hogy a tesztek egymástól és az
 * éles adatoktól is teljesen függetlenek legyenek.
 */
function tests_new_database(): Database
{
    $path = sys_get_temp_dir() . '/sm_test_' . bin2hex(random_bytes(8)) . '.sqlite';
    register_shutdown_function(static function () use ($path) {
        @unlink($path);
        @unlink($path . '-shm');
        @unlink($path . '-wal');
    });

    return new Database(
        ['driver' => 'sqlite', 'sqlite' => ['path' => $path]],
        dirname(__DIR__)
    );
}
