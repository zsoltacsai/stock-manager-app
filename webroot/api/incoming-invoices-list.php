<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$filters = [
    'date_from'      => $_GET['date_from'] ?? '',
    'date_to'        => $_GET['date_to'] ?? '',
    'supplier'       => $_GET['supplier'] ?? '',
    'invoice_number' => $_GET['invoice_number'] ?? '',
    'tax_number'     => $_GET['tax_number'] ?? '',
    'operation'      => $_GET['operation'] ?? '',
    'currency'       => $_GET['currency'] ?? '',
];

send_json(['invoices' => $db->listIncomingInvoices($filters)]);
