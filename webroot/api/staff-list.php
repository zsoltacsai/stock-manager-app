<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$includeInactive = !empty($_GET['include_inactive']);

send_json(['staff' => $db->listStaff($includeInactive)]);
