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
send_json(['products' => $db->listProducts(100000, $includeDeleted)]);
