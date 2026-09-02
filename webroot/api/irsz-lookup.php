<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$irsz = trim((string) ($_GET['irsz'] ?? ''));
if (!preg_match('/^\d{4}$/', $irsz)) {
    send_json(['found' => false]);
}

$index = [];
$path = __DIR__ . '/../../data/irsz.csv';
if (is_file($path)) {
    $handle = fopen($path, 'r');
    while (($row = fgetcsv($handle)) !== false) {
        if (isset($row[0], $row[1])) {
            $index[$row[0]] = $row[1];
        }
    }
    fclose($handle);
}

if (isset($index[$irsz])) {
    send_json(['found' => true, 'telepules' => $index[$irsz]]);
}

send_json(['found' => false]);
