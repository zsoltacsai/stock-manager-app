<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

// Regresszió (1.3.1): a tevékenységnapló (ki mit csinált, mikor —
// dolgozói PIN-bejelentkezések, számla MODIFY/STORNO, biztonsági mentés
// visszaállítás stb.) korábban BÁRMELYIK bejelentkezett dolgozónak
// (akár egy sima pénztárosnak is) elérhető volt — ugyanaz a "vezetői
// jogszint kell" szabály indokolt rá, mint a többi infrastruktúra-
// szintű/érzékeny végpontnál.
require_admin($db);

send_json(['entries' => $db->getAuditLog(200)]);
