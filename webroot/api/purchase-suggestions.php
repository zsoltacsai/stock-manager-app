<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$threshold = (int) ($appSettings['low_stock_default_threshold'] ?? 5);

send_json(['groups' => $db->getPurchaseSuggestions($threshold)]);
