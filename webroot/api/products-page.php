<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

/**
 * PERF-09 — a Termékek oldal szerveroldali lapozása (Database::listProductsPage()).
 * A válasz az aktuális oldal teljes termékrekordjai mellett a szűrésnek
 * megfelelő ÖSSZES azonosítót is tartalmazza (rendezett sorrendben) — a
 * kijelölés, a „mind kijelölése”, az export és a tömeges műveletek ugyanúgy
 * a teljes szűrt halmazon dolgoznak, mint a korábbi, kliensoldali listán.
 */

$filters = [
    'name'            => (string) ($_GET['name'] ?? ''),
    'cikkszam'        => (string) ($_GET['cikkszam'] ?? ''),
    'barcode'         => (string) ($_GET['barcode'] ?? ''),
    'group'           => (string) ($_GET['group'] ?? ''),
    'zero_stock'      => !empty($_GET['zero_stock']),
    'webshop_only'    => !empty($_GET['webshop_only']),
    'include_deleted' => !empty($_GET['include_deleted']),
];
$sort = (string) ($_GET['sort'] ?? 'name');
$dir = (string) ($_GET['dir'] ?? 'asc');
$offset = max(0, (int) ($_GET['offset'] ?? 0));
$limit = max(1, min(500, (int) ($_GET['limit'] ?? 100)));

send_json($db->listProductsPage($filters, $sort, $dir, $offset, $limit) + ['offset' => $offset, 'limit' => $limit]);
