<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

send_json([
    'enabled'    => Auth::isEnabled($appSettings),
    'logged_in'  => Auth::isLoggedIn($appSettings),
    'csrf_token' => Auth::csrfToken(),
]);
