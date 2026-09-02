<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$profiles = require __DIR__ . '/../../src/ImportProfiles.php';

$list = [];
foreach ($profiles as $key => $p) {
    $list[] = [
        'key'         => $key,
        'label'       => $p['label'],
        'description' => $p['description'],
        'implemented' => !empty($p['implemented']),
    ];
}

send_json(['profiles' => $list]);
