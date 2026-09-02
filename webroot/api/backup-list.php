<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';
require_once __DIR__ . '/../../src/BackupManager.php';

$manager = new BackupManager($config['db'], __DIR__ . '/../../data/backups');

send_json(['backups' => $manager->listLocal()]);
