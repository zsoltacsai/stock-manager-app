<?php

declare(strict_types=1);
require __DIR__ . '/_bootstrap.php';

// Kliens-lista — sose tartalmazza a secret_hash-t (Database::listRegisteredClients() már eleve kihagyja).
require_admin($db);

send_json(['clients' => $db->listRegisteredClients()]);
