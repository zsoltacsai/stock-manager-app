<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$filters = [
    'date'     => $_GET['date'] ?? '',
    'id'       => $_GET['id'] ?? '',
    'provider' => $_GET['provider'] ?? '',
    'status'   => $_GET['status'] ?? '',
    'query'    => $_GET['query'] ?? '',
];

// B-07: az elévült (a Számlázz.hu-hívás körül megszakadt) foglalások a
// lista megnyitásakor bizonytalanná válnak ('uncertain_manual'), hogy az
// admin lássa és feloldhassa őket — sose válnak magától újrapróbálhatóvá.
$db->markStaleInvoiceClaimsUncertain();

send_json(['invoices' => $db->listInvoices($filters)]);
