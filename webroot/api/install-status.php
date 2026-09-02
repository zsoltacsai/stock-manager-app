<?php

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

$markerPath = __DIR__ . '/../../data/.installed';
$dataDir = __DIR__ . '/../../data';

function is_stock_manager_installed(string $markerPath, string $dataDir): bool
{
    if (is_file($markerPath)) {
        return true;
    }

    $config = require __DIR__ . '/../../config/config.php';
    if (($config['db']['driver'] ?? 'sqlite') === 'sqlite' && is_file($config['db']['sqlite']['path'] ?? '')) {
        @mkdir($dataDir, 0775, true);
        @touch($markerPath);
        return true;
    }
    if (is_file(__DIR__ . '/../../config/installer-generated.php')) {
        @mkdir($dataDir, 0775, true);
        @touch($markerPath);
        return true;
    }

    return false;
}

echo json_encode(['installed' => is_stock_manager_installed($markerPath, $dataDir)]);
