<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

send_json(['registers' => $db->listCashRegisters(!empty($_GET['include_inactive']))]);
