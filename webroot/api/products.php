<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

$includeDeleted = !empty($_GET['include_deleted']);
// A korábbi 500-as korlát egy 500 terméknél nagyobb katalógusnál csendben
// levágta a listát: a "X / Y árucikk" számláló hibás lett, a keresés a
// levágáson túli termékekre üres (hamis) találatot adott, és a "mind
// kijelölése" export sem látta a levágáson túli sorokat — mindezt anélkül,
// hogy bárhol jelezte volna a felület. Ugyanazt a nagyvonalú korlátot
// használjuk, mint az export-products.php már eddig is (100000) — egyetlen,
// join nélküli lekérdezésként ez SQLite-on nem jelent érdemi terhelést.
//
// PERF-04: a válasz streamelve íródik ki (bájtra ugyanaz, mint a korábbi
// send_json(['products' => listProducts(...)]) kimenete). Korábban a teljes
// lista a PHP-memóriában épült fel: D3-on 100 000 termékkel 263 MB, a 128 MB-os
// korláton ~35 000 termék felett HTTP 500. A kassza és a Termékek oldal már
// nem ezt használja (product-search.php, products-page.php).
http_response_code(200);
echo '{"products":[';
$first = true;
$db->eachProduct(100000, $includeDeleted, static function (array $row) use (&$first): void {
    echo ($first ? '' : ',') . json_encode($row, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    $first = false;
});
echo ']}';
