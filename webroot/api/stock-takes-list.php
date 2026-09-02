<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

send_json(['stock_takes' => $db->listStockTakes()]);
