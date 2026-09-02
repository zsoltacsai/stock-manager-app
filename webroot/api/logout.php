<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

Auth::logout();
send_json(['ok' => true]);
