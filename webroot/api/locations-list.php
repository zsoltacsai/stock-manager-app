<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

// A pénztárgép/kasszaműszak-állapotot IDE fűzzük hozzá, nem egy külön
// végpontba — az app.js már ezt a választ tölti be feltétel nélkül minden
// oldalbetöltéskor (initLocationSelector()), így a POS fejléc kassza-
// állapot-jelzője NEM igényel plusz HTTP-kérést, ugyanaz a fegyelem, mint a
// Dashboard rendszerállapot-kártyájánál (lásd dashboard-summary.php
// today_status mezője).
$locations = $db->listLocations();
$registers = $db->listCashRegisters();
$registersByLocation = [];
foreach ($registers as $r) {
    $locId = (int) $r['location_id'];
    $registersByLocation[$locId] ??= [];
    $openSession = $db->getOpenCashSession((int) $r['id']);
    $registersByLocation[$locId][] = [
        'id'         => (int) $r['id'],
        'name'       => $r['name'],
        'code'       => $r['code'],
        'open'       => $openSession !== null,
        'session_id' => $openSession ? (int) $openSession['id'] : null,
    ];
}
foreach ($locations as &$loc) {
    $loc['cash_registers'] = $registersByLocation[(int) $loc['id']] ?? [];
}
unset($loc);

send_json(['locations' => $locations]);
