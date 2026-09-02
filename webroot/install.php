<?php

declare(strict_types=1);

$dataDir = __DIR__ . '/../data';
$configDir = __DIR__ . '/../config';
$markerPath = $dataDir . '/.installed';
$generatedPath = $configDir . '/installer-generated.php';

if (is_file($markerPath)) {
    header('Location: index.php');
    exit;
}

$errors = [];
$values = [
    'shop_name'    => 'Fountainbridge Bolt',
    'shop_address' => '',
    'driver'       => 'sqlite',
    'mysql_host'   => '127.0.0.1',
    'mysql_port'   => '3306',
    'mysql_database' => 'stock_manager',
    'mysql_username' => 'stock_manager',
    'mysql_password' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($_POST['skip'])) {
        // "Kihagyás" — az app már eleve működik a config.php-ba sütött
        // alapértelmezett SQLite-beállítással, szóval itt csak annyi a
        // teendő, hogy megjelöljük a telepítést késznek, mást nem kell írni.
        @mkdir($dataDir, 0775, true);
        touch($markerPath);
        header('Location: index.php');
        exit;
    }

    foreach ($values as $key => $default) {
        if (isset($_POST[$key])) {
            $values[$key] = trim((string) $_POST[$key]);
        }
    }

    if ($values['shop_name'] === '') {
        $errors[] = 'A bolt neve kötelező.';
    }

    $dbConfig = null;
    if ($values['driver'] === 'mysql') {
        $port = (int) $values['mysql_port'];
        if ($values['mysql_database'] === '' || $values['mysql_username'] === '') {
            $errors[] = 'Az adatbázis neve és a felhasználónév megadása kötelező MySQL esetén.';
        }
        if (empty($errors)) {
            try {
                $dsn = sprintf('mysql:host=%s;port=%d;charset=utf8mb4', $values['mysql_host'], $port ?: 3306);
                $testPdo = new PDO($dsn, $values['mysql_username'], $values['mysql_password'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_TIMEOUT => 5,
                ]);
                $testPdo->exec("CREATE DATABASE IF NOT EXISTS `{$values['mysql_database']}` CHARACTER SET utf8mb4");
            } catch (Throwable $e) {
                $errors[] = 'Nem sikerült csatlakozni a MySQL szerverhez: ' . $e->getMessage();
            }
        }
        if (empty($errors)) {
            $dbConfig = [
                'driver' => 'mysql',
                'sqlite' => ['path' => __DIR__ . '/../data/stock.sqlite'],
                'mysql' => [
                    'host'     => $values['mysql_host'],
                    'port'     => $port ?: 3306,
                    'database' => $values['mysql_database'],
                    'username' => $values['mysql_username'],
                    'password' => $values['mysql_password'],
                    'charset'  => 'utf8mb4',
                ],
            ];
        }
    } else {
        $dbConfig = [
            'driver' => 'sqlite',
            'sqlite' => ['path' => __DIR__ . '/../data/stock.sqlite'],
            'mysql' => [
                'host' => '127.0.0.1', 'port' => 3306, 'database' => 'stock_manager',
                'username' => 'stock_manager', 'password' => '', 'charset' => 'utf8mb4',
            ],
        ];
    }

    if (empty($errors) && $dbConfig !== null) {
        try {
            require_once __DIR__ . '/../src/Database.php';
            $db = new Database($dbConfig, __DIR__ . '/..');

            @mkdir($configDir, 0775, true);
            $exported = var_export([
                'shop' => ['name' => $values['shop_name'], 'address' => $values['shop_address']],
                'db'   => $dbConfig,
            ], true);
            file_put_contents($generatedPath, "<?php\nreturn $exported;\n");

            @mkdir($dataDir, 0775, true);
            touch($markerPath);

            header('Location: index.php');
            exit;
        } catch (Throwable $e) {
            $errors[] = 'A telepítés sikertelen: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="hu">
<head>
<script>(function(){try{var t=localStorage.getItem("sm_theme");if(t==="light")document.documentElement.setAttribute("data-theme","light");}catch(e){}})();</script>
<meta charset="UTF-8">
<link rel="icon" type="image/svg+xml" href="favicon.svg">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Stock Manager — Telepítés</title>
<link rel="stylesheet" href="style.css">
<style>
    body { padding-left: 0; display: flex; align-items: center; justify-content: center; min-height: 100vh; }
    .install-card {
        background: var(--panel); border: 1px solid var(--border); border-radius: 12px;
        width: 100%; max-width: 560px; padding: 32px; margin: 20px;
    }
    .install-logo { width: 48px; height: 48px; border-radius: 12px; margin-bottom: 16px; }
    .driver-picker { display: flex; gap: 12px; margin-bottom: 16px; }
    .driver-option {
        flex: 1; border: 2px solid var(--border); border-radius: 10px; padding: 14px;
        cursor: pointer; text-align: center;
    }
    .driver-option input { display: none; }
    .driver-option strong { display: block; margin-bottom: 4px; }
    .driver-option.selected { border-color: var(--accent); background: var(--panel-light); }
    .field-row { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    @media (max-width: 480px) {
        .field-row { grid-template-columns: 1fr; }
        .driver-picker { flex-wrap: wrap; }
    }
</style>
</head>
<body>
<div class="install-card">
    <img src="assets/logo-default.svg" alt="Logó" class="install-logo">
    <h2 style="margin-top:0;">Stock Manager telepítése</h2>
    <p class="muted" style="margin-top:-8px;">
        Az alkalmazás alapból SQLite-tal, teljesen konfiguráció nélkül is működik —
        ez a varázsló csak akkor szükséges, ha MySQL-t szeretnél használni, vagy meg
        akarod adni a bolt alapadatait indulásként. Bármikor kihagyható.
    </p>

    <?php foreach ($errors as $error): ?>
        <p class="feedback error"><?= htmlspecialchars($error) ?></p>
    <?php endforeach; ?>

    <form method="post">
        <label for="shop_name">Bolt neve</label>
        <input type="text" id="shop_name" name="shop_name" value="<?= htmlspecialchars($values['shop_name']) ?>">

        <label for="shop_address">Cím</label>
        <input type="text" id="shop_address" name="shop_address" placeholder="pl. Szeged" value="<?= htmlspecialchars($values['shop_address']) ?>">

        <label style="margin-top:16px;">Adatbázis</label>
        <div class="driver-picker">
            <label class="driver-option" id="driver-option-sqlite">
                <input type="radio" name="driver" value="sqlite" <?= $values['driver'] === 'sqlite' ? 'checked' : '' ?>>
                <strong>SQLite</strong>
                <span class="muted" style="font-size:12px;">Ajánlott, nincs beállítás</span>
            </label>
            <label class="driver-option" id="driver-option-mysql">
                <input type="radio" name="driver" value="mysql" <?= $values['driver'] === 'mysql' ? 'checked' : '' ?>>
                <strong>MySQL 8</strong>
                <span class="muted" style="font-size:12px;">Nagyobb, forgalmasabb boltoknak</span>
            </label>
        </div>

        <div id="mysql-fields" class="hidden">
            <div class="field-row">
                <div>
                    <label for="mysql_host">Host</label>
                    <input type="text" id="mysql_host" name="mysql_host" value="<?= htmlspecialchars($values['mysql_host']) ?>">
                </div>
                <div>
                    <label for="mysql_port">Port</label>
                    <input type="text" id="mysql_port" name="mysql_port" value="<?= htmlspecialchars($values['mysql_port']) ?>">
                </div>
            </div>
            <label for="mysql_database">Adatbázis neve</label>
            <input type="text" id="mysql_database" name="mysql_database" value="<?= htmlspecialchars($values['mysql_database']) ?>">
            <div class="field-row">
                <div>
                    <label for="mysql_username">Felhasználónév</label>
                    <input type="text" id="mysql_username" name="mysql_username" value="<?= htmlspecialchars($values['mysql_username']) ?>">
                </div>
                <div>
                    <label for="mysql_password">Jelszó</label>
                    <input type="password" id="mysql_password" name="mysql_password" value="<?= htmlspecialchars($values['mysql_password']) ?>">
                </div>
            </div>
            <p class="muted">
                A kapcsolat a "Telepítés befejezése" gombra kattintva kerül tesztelésre —
                ha a szerver elérhető, az adatbázis (ha még nem létezik) automatikusan létrejön.
            </p>
        </div>

        <div class="modal-actions" style="margin-top:20px;">
            <button type="submit" name="skip" value="1" class="btn btn-secondary" style="flex:1;">Kihagyás — SQLite alapértelmezett</button>
            <button type="submit" class="btn btn-primary" style="flex:1;">Telepítés befejezése</button>
        </div>
    </form>
</div>

<script>
const driverRadios = document.querySelectorAll('input[name="driver"]');
const mysqlFields = document.getElementById('mysql-fields');
const optSqlite = document.getElementById('driver-option-sqlite');
const optMysql = document.getElementById('driver-option-mysql');

function updateDriverUI() {
    const isMysql = document.querySelector('input[name="driver"]:checked').value === 'mysql';
    mysqlFields.classList.toggle('hidden', !isMysql);
    optSqlite.classList.toggle('selected', !isMysql);
    optMysql.classList.toggle('selected', isMysql);
}
driverRadios.forEach(r => r.addEventListener('change', updateDriverUI));
updateDriverUI();
</script>
</body>
</html>
