<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

send_json(['products' => $db->getTopSellingProducts(8)]);
