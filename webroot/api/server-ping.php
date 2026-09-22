<?php

declare(strict_types=1);

/**
 * Fázis 2, Checkpoint 4 — minimális, hitelesítés NÉLKÜLI reachability/
 * verzió-ellenőrző végpont, amit egy Kliens node saját ClientProxy-ja hív
 * (kimenő HTTP-hívásként, NEM a Kliens saját _bootstrap.php-ján keresztül —
 * lásd src/ClientServerHealth.php) a konfigurált távoli Szerver felé, MIELŐTT
 * bármilyen valódi, HMAC-hitelesített üzleti kérést továbbítana.
 *
 * SZÁNDÉKOSAN NEM megy át a _bootstrap.php-n (ugyanaz a minta, mint
 * install-status.php-nál) — egy könnyű, gyors, Settings/Database-független
 * probe, amit a Kliens akár percenként többször is meghívhat (TTL-cache
 * mellett ritkábban, lásd ClientServerHealth::TTL_SECONDS). NEM tartalmaz
 * semmilyen titkot/belső configot/pathot — csak a termék nevét és a
 * telepített verziót (AppVersion::CURRENT, az EGYETLEN forrás, lásd ott a
 * docblokkja — SOSE egy itt duplikált, hardcodolt verziószám).
 */

require_once __DIR__ . '/../../src/AppVersion.php';

header('Content-Type: application/json; charset=utf-8');
echo json_encode([
    'success' => true,
    'app' => AppVersion::PRODUCT,
    'version' => AppVersion::CURRENT,
], JSON_UNESCAPED_UNICODE);
