<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

/** PERF-04 — a nem törölt termékek csoportnevei (a riport-szűrőhöz a teljes katalógus letöltése helyett). */
send_json(['groups' => $db->listProductGroupNames(!empty($_GET['include_deleted']))]);
