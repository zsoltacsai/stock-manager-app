<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

// 1.4.0 — a teljes rendszeresemény-napló listázása (a Rendszerállapot
// oldal "Eseménynapló" táblájához), kategória/severity szűréssel. Lásd
// system-health.php a kompakt, Dashboard-ra szánt összesítőért — ez itt
// a részletes, lapozható nézet.

$filters = [];
if (!empty($_GET['category'])) {
    $filters['category'] = (string) $_GET['category'];
}
if (!empty($_GET['severity'])) {
    $filters['severity'] = (string) $_GET['severity'];
}
$limit = max(1, min(500, (int) ($_GET['limit'] ?? 100)));

$events = $db->getSystemEvents($filters, $limit);

// A technikai diagnosztika (technical_detail) csak "effektíve admin"
// sessionnek látszik — lásd system-health.php ugyanezen indoklását.
$isEffectiveAdmin = !$db->listStaff(true) || $db->isStaffAdmin(Auth::currentStaffId());
if (!$isEffectiveAdmin) {
    foreach ($events as &$event) {
        unset($event['technical_detail']);
    }
    unset($event);
}

send_json(['events' => $events, 'is_admin' => $isEffectiveAdmin]);