<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/BackupManager.php';

// A mentés-fájlnevek listázása önmagában nem tesz elérhetővé adatot, de a
// visszaállítás/mentés-készítés melletti, azonos jogosultsági körbe tartozó
// művelet — konzisztensen ugyanazt a "vezetői jogszint kell" szabályt kapja.
require_admin($db);

$manager = new BackupManager($config['db'], __DIR__ . '/../../data/backups');

send_json(['backups' => $manager->listLocal()]);
