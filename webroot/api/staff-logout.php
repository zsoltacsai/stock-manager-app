<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    send_json(['error' => 'POST only'], 405);
}

// A "dolgozó kijelentkezés" korábban tisztán kliens-oldali volt (csak a
// localStorage-ot ürítette ki) — ez itt a szerver-oldali PIN-session
// (lásd Auth::setCurrentStaff()) törléséhez kell, különben egy admin
// "kijelentkezése" után a session továbbra is az ő azonosítójával
// engedne át admin-kapukat a következő, PIN nélkül dolgozó számára.
Auth::setCurrentStaff(null);
send_json(['ok' => true]);
