<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
$invoice = $id ? $db->getInvoiceById($id) : null;

if (!$invoice) {
    send_json(['error' => 'A számla nem található.'], 404);
}

$sale = $db->getSaleWithItems((int) $invoice['sale_id']);
if ($sale) {
    unset($sale['receipt_token']); // titkos token — sose menjen ki, még bejelentkezett dolgozónak sem (lásd sale-detail.php)
}

// 1.1.0: számla-kapcsolat navigáció (lásd a kör 20. pontja) — a TELJES
// eredeti→módosítás/sztornó láncot adja vissza, függetlenül attól, hogy
// éppen az eredetit vagy egy származtatott sort nyitottak meg. A
// MODIFY/STORNO gombok megjelenítését (can_modify/can_storno) a BACKEND
// dönti el (lásd InvoiceService::validateOperationRequest() ugyanezen
// szabályait) — a frontend sose dönt pénzügyi jogosultságról saját maga.
$rootId = $invoice['original_invoice_id'] !== null ? (int) $invoice['original_invoice_id'] : (int) $invoice['id'];
$root = $rootId === (int) $invoice['id'] ? $invoice : $db->getInvoiceById($rootId);
$operations = $db->getInvoiceOperationsForOriginal($rootId);

// A MODIFY/STORNO gombok KIZÁRÓLAG a gyökér EREDETI számla saját
// részletnézetén jelenhetnek meg — egy már létrehozott módosítás/sztornó
// sor MEGNYITÁSAKOR (invoice_type!=='normal') SOSE, még akkor sem, ha a
// gyökér egyébként jogosult lenne további műveletre (a felhasználó a
// kapcsolódó-számlák láncon keresztül úgyis egy kattintással eljut az
// eredetihez, ahol a gombok helyesen megjelennek).
$isAdmin = !$db->listStaff(true) || $db->isStaffAdmin(Auth::currentStaffId());
$canOperate = $isAdmin
    && (int) $invoice['id'] === $rootId
    && $root !== null
    && (string) $root['invoice_type'] === 'normal'
    && (string) $root['status'] === 'done'
    && !$db->invoiceHasBlockingStorno($rootId);

send_json([
    'invoice' => $invoice,
    'sale' => $sale,
    'original' => $root,
    'operations' => $operations,
    'can_modify' => $canOperate,
    'can_storno' => $canOperate,
]);
