#requires -Version 5.1
<#
.SYNOPSIS
    FountainTrade — automatizált Windows telepítő/beüzemelő szkript (1.4.0).

.DESCRIPTION
    Ez a szkript a install.txt kézi lépéseinek egy részét automatizálja egy
    Windows gépen: ellenőrzi a PHP-t és a szükséges kiterjesztéseket,
    ellenőrzi/létrehozza az írható mappákat, létrehoz egy Windows Feladat-
    ütemező bejegyzést a PHP beépített szerver indítására bejelentkezéskor,
    ÉS létrehozza/frissíti az öt automatikus háttérfeladat (WooCommerce
    szinkron, biztonsági mentés, NAV kimenő/bejövő, frissítés-ellenőrzés)
    Feladatütemező-bejegyzéseit.

    IDEMPOTENS: másodszori (vagy N-edik) futtatás nem hoz létre duplikált
    feladatokat — minden lépés előbb ELLENŐRZI, létezik-e már a cél
    (task/mappa/php.ini-beállítás), és csak akkor módosít, ha szükséges.

    NEM TARTALMAZ semmilyen titkot/cron-tokent a szkript SAJÁT forrásában —
    a cron-tokent vagy a -CronToken paraméterrel kell futáskor átadni, vagy
    a szkript automatikusan beolvassa a MÁR LÉTEZŐ data\settings.json
    fájlból (ha a telepítő varázsló/Beállítások oldal korábban már
    beállította). Ha egyik sem érhető el, a szkript figyelmeztetéssel
    KIHAGYJA a cron-feladatok létrehozását, és a végső összegzésben
    egyértelműen jelzi, mit kell a felhasználónak utólag megtennie.

.PARAMETER InstallPath
    A FountainTrade mappa útvonala. Alapértelmezetten a szkript saját
    mappája (feltételezve, hogy a szkript a projekt gyökerében fut).

.PARAMETER PhpPath
    A php.exe teljes útvonala. Ha nincs megadva, a szkript megpróbálja
    megtalálni a PATH-on, majd a szokásos C:\tools\php83\php.exe helyen.

.PARAMETER Port
    A beépített PHP szerver portja. Alapértelmezett: 8000.

.PARAMETER CronToken
    Opcionális — a Beállítások → Mentés fülön beállított cron-titkos
    token. Ha nincs megadva, a szkript megpróbálja beolvasni a MÁR
    LÉTEZŐ data\settings.json-ból.

.PARAMETER SkipScheduledTasks
    Ha meg van adva, a szkript KIHAGYJA az összes Feladatütemező-bejegyzés
    létrehozását/frissítését (pl. ha ezt már valaki kézzel beállította, és
    csak a PHP/mappa-ellenőrzést szeretnéd újra lefuttatni).

.EXAMPLE
    .\install-windows.ps1
    Alapértelmezett beüzemelés — PHP/mappa-ellenőrzés, majd a cron-
    feladatok LÉTREHOZÁSÁNAK MEGKÍSÉRLÉSE (token nélkül ez figyelmeztetéssel
    kimarad, ha még nincs elmentett cron_secret).

.EXAMPLE
    .\install-windows.ps1 -CronToken "a-mentett-cron-titok" -Port 8000
    Teljes beüzemelés, a cron-feladatok a megadott tokennel jönnek létre.
#>

[CmdletBinding()]
param(
    [string]$InstallPath = $PSScriptRoot,
    [string]$PhpPath,
    [int]$Port = 8000,
    [string]$CronToken,
    [switch]$SkipScheduledTasks
)

$ErrorActionPreference = 'Stop'
$script:WarningCount = 0
$script:Summary = [System.Collections.Generic.List[string]]::new()

function Write-Step {
    param([string]$Message)
    Write-Host "`n==> $Message" -ForegroundColor Cyan
}

function Write-Ok {
    param([string]$Message)
    Write-Host "    [OK] $Message" -ForegroundColor Green
    $script:Summary.Add("[OK] $Message")
}

function Write-Warn2 {
    param([string]$Message)
    Write-Host "    [FIGYELEM] $Message" -ForegroundColor Yellow
    $script:Summary.Add("[FIGYELEM] $Message")
    $script:WarningCount++
}

function Write-Err2 {
    param([string]$Message)
    Write-Host "    [HIBA] $Message" -ForegroundColor Red
    $script:Summary.Add("[HIBA] $Message")
}

# -------------------------------------------------------------------
# 1. PHP megtalálása és ellenőrzése
# -------------------------------------------------------------------
Write-Step "PHP telepítés ellenőrzése"

if (-not $PhpPath) {
    $fromPath = Get-Command php.exe -ErrorAction SilentlyContinue
    if ($fromPath) {
        $PhpPath = $fromPath.Source
    } elseif (Test-Path 'C:\tools\php83\php.exe') {
        $PhpPath = 'C:\tools\php83\php.exe'
    }
}

if (-not $PhpPath -or -not (Test-Path $PhpPath)) {
    Write-Err2 "Nem található PHP (php.exe). Add meg a -PhpPath paraméterrel, vagy telepítsd az install.txt 2. pontja szerint."
    exit 1
}
Write-Ok "PHP találva: $PhpPath"

$phpVersionOutput = & $PhpPath -v 2>&1 | Select-Object -First 1
if ($phpVersionOutput -match 'PHP (\d+)\.(\d+)') {
    $major = [int]$Matches[1]
    $minor = [int]$Matches[2]
    if ($major -lt 8 -or ($major -eq 8 -and $minor -lt 1)) {
        Write-Err2 "A PHP verzió (${major}.${minor}) túl régi — legalább PHP 8.1 szükséges (lásd README 'Követelmények')."
        exit 1
    }
    Write-Ok "PHP verzió: ${major}.${minor}"
} else {
    Write-Warn2 "Nem sikerült megállapítani a PHP verziószámát a '$phpVersionOutput' kimenetből."
}

# A README/install.txt szerint kötelező kiterjesztések — hiányzó
# kiterjesztés esetén az app csendben, nehezen diagnosztizálható módon
# hibázna később (fehér oldal / "Call to undefined function"), ezért itt,
# a telepítés ELEJÉN ellenőrizzük.
$requiredExtensions = @('pdo_sqlite', 'sqlite3', 'curl', 'mbstring', 'gd', 'xmlwriter', 'zip', 'fileinfo', 'openssl')
$loadedModules = & $PhpPath -m 2>&1
$missingExtensions = @()
foreach ($ext in $requiredExtensions) {
    if ($loadedModules -notcontains $ext) {
        $missingExtensions += $ext
    }
}
if ($missingExtensions.Count -gt 0) {
    Write-Err2 "Hiányzó PHP-kiterjesztések: $($missingExtensions -join ', ') — kapcsold be őket a php.ini-ben (lásd install.txt 1-2. pontja), majd futtasd újra ezt a szkriptet."
    exit 1
}
Write-Ok "Minden szükséges PHP-kiterjesztés be van kapcsolva ($($requiredExtensions -join ', '))."

if ($loadedModules -notcontains 'Zend OPcache') {
    Write-Warn2 "Az OPcache nincs bekapcsolva — nem kötelező, de erősen ajánlott a gyorsabb oldalbetöltéshez (lásd install.txt 2. pontja)."
} else {
    Write-Ok "OPcache bekapcsolva."
}

# -------------------------------------------------------------------
# 2. Mappák — létezés + írhatóság
# -------------------------------------------------------------------
Write-Step "Mappák ellenőrzése ($InstallPath)"

$webrootPath = Join-Path $InstallPath 'webroot'
if (-not (Test-Path $webrootPath)) {
    Write-Err2 "Nem található a 'webroot' mappa itt: $InstallPath — biztosan a projekt gyökerében fut ez a szkript?"
    exit 1
}

$writableDirs = @(
    (Join-Path $InstallPath 'data'),
    (Join-Path $InstallPath 'data\backups'),
    (Join-Path $InstallPath 'data\imports'),
    (Join-Path $InstallPath 'invoices'),
    (Join-Path $webrootPath 'assets')
)
foreach ($dir in $writableDirs) {
    if (-not (Test-Path $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
        Write-Ok "Létrehozva: $dir"
    }
    # Írhatóság-teszt: egy ideiglenes fájl létrehozása/törlése — Windows
    # alatt NTFS-jogosultsági probléma ritka a saját felhasználói mappában,
    # de egy megosztott/vállalati gépen előfordulhat (lásd install.txt 3.
    # pontja "permission denied" hibaelhárítása).
    $testFile = Join-Path $dir '.install-write-test'
    try {
        [System.IO.File]::WriteAllText($testFile, 'ok')
        Remove-Item $testFile -Force
        Write-Ok "Írható: $dir"
    } catch {
        Write-Err2 "NEM írható: $dir — jobb klikk a mappán -> Tulajdonságok -> Biztonság -> adj Módosítás jogot a felhasználódnak (lásd install.txt 3. pontja)."
    }
}

# -------------------------------------------------------------------
# 3. Feladatütemező — PHP szerver indítása bejelentkezéskor (idempotens)
# -------------------------------------------------------------------
if (-not $SkipScheduledTasks) {
    Write-Step "Feladatütemező — PHP szerver automatikus indítása"

    $serverTaskName = 'FountainTrade - Szerver'
    $serverAction = New-ScheduledTaskAction -Execute $PhpPath -Argument "-S localhost:$Port -t `"$webrootPath`"" -WorkingDirectory $InstallPath
    $serverTrigger = New-ScheduledTaskTrigger -AtLogOn
    $serverSettings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -ExecutionTimeLimit ([TimeSpan]::Zero)

    $existingServerTask = Get-ScheduledTask -TaskName $serverTaskName -ErrorAction SilentlyContinue
    if ($existingServerTask) {
        # Idempotencia: FRISSÍTJÜK a meglévő feladatot (pl. ha a port vagy a
        # PHP útvonala változott), NEM hozunk létre egy második, duplikált
        # bejegyzést ugyanarra a célra.
        Set-ScheduledTask -TaskName $serverTaskName -Action $serverAction -Trigger $serverTrigger -Settings $serverSettings | Out-Null
        Write-Ok "Feladatütemező-bejegyzés frissítve: '$serverTaskName'"
    } else {
        Register-ScheduledTask -TaskName $serverTaskName -Action $serverAction -Trigger $serverTrigger -Settings $serverSettings -Description 'FountainTrade beépített PHP szerver indítása bejelentkezéskor.' | Out-Null
        Write-Ok "Feladatütemező-bejegyzés létrehozva: '$serverTaskName'"
    }
} else {
    Write-Step "Feladatütemező-lépések kihagyva (-SkipScheduledTasks)"
}

# -------------------------------------------------------------------
# 4. Cron-token beolvasása/ellenőrzése — SOSE hardcode-olva a szkriptben
# -------------------------------------------------------------------
if (-not $SkipScheduledTasks) {
    Write-Step "Cron-token ellenőrzése"

    if (-not $CronToken) {
        $settingsPath = Join-Path $InstallPath 'data\settings.json'
        if (Test-Path $settingsPath) {
            try {
                $settingsJson = Get-Content $settingsPath -Raw | ConvertFrom-Json
                if ($settingsJson.cron_secret) {
                    $CronToken = $settingsJson.cron_secret
                    Write-Ok "Cron-token beolvasva a meglévő data\settings.json fájlból."
                }
            } catch {
                Write-Warn2 "A data\settings.json beolvasása sikertelen — a fájl esetleg sérült."
            }
        }
    }

    if (-not $CronToken) {
        Write-Warn2 "Nincs elérhető cron-token — a háttérfeladatok (szinkron/mentés/NAV/frissítés-ellenőrzés) Feladatütemező-bejegyzései KIMARADNAK ebből a futásból."
        Write-Warn2 "Először állíts be egy cron-titkos tokent a Beállítások -> Mentés fülön, majd futtasd újra: .\install-windows.ps1 -CronToken `"<a beállított token>`""
    }
}

# -------------------------------------------------------------------
# 5. Cron-feladatok (idempotens létrehozás/frissítés)
# -------------------------------------------------------------------
if (-not $SkipScheduledTasks -and $CronToken) {
    Write-Step "Automatikus háttérfeladatok (cron) beállítása"

    # Minden bejegyzés: (Feladatnév, endpoint, javasolt gyakoriság) — lásd
    # install.txt "8. AUTOMATIKUS FELADATOK" szakasza a pontos indoklásért.
    # A token KIZÁRÓLAG az X-Cron-Token fejlécben megy — ugyanaz a
    # curl-parancs, amit install.txt is dokumentál, csak Feladatütemező alá
    # szervezve, hogy ne kelljen 5x kézzel bepötyögni a Feladatütemező GUI-ban.
    $cronJobs = @(
        @{ Name = 'FountainTrade - WooCommerce szinkron'; Endpoint = 'auto-sync-run.php'; Trigger = { New-ScheduledTaskTrigger -Once (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration ([TimeSpan]::MaxValue) } },
        @{ Name = 'FountainTrade - Biztonsagi mentes'; Endpoint = 'auto-backup-run.php'; Trigger = { New-ScheduledTaskTrigger -Once (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 15) -RepetitionDuration ([TimeSpan]::MaxValue) } },
        @{ Name = 'FountainTrade - NAV kimeno queue'; Endpoint = 'nav-queue-run.php'; Trigger = { New-ScheduledTaskTrigger -Once (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration ([TimeSpan]::MaxValue) } },
        @{ Name = 'FountainTrade - NAV bejovo szinkron'; Endpoint = 'nav-incoming-sync-run.php'; Trigger = { New-ScheduledTaskTrigger -Once (Get-Date) -RepetitionInterval (New-TimeSpan -Hours 1) -RepetitionDuration ([TimeSpan]::MaxValue) } },
        @{ Name = 'FountainTrade - Frissites ellenorzes'; Endpoint = 'update-check-run.php'; Trigger = { New-ScheduledTaskTrigger -Once (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 30) -RepetitionDuration ([TimeSpan]::MaxValue) } }
    )

    # curl.exe (Windows 10 1803+ óta beépített) a natív választás — nincs
    # szükség külön PowerShell Invoke-WebRequest-parancsfájlra, ugyanaz a
    # parancs, amit install.txt kézi Feladatütemező-beállításhoz is javasol.
    $curlPath = (Get-Command curl.exe -ErrorAction SilentlyContinue).Source
    if (-not $curlPath) {
        Write-Err2 "A curl.exe nem található (Windows 10 1803+ alapból tartalmazza) — a cron-feladatok létrehozása kimarad."
    } else {
        foreach ($job in $cronJobs) {
            $url = "http://localhost:$Port/api/$($job.Endpoint)"
            $curlArgs = "-s -H `"X-Cron-Token: $CronToken`" `"$url`""
            $action = New-ScheduledTaskAction -Execute $curlPath -Argument $curlArgs
            $trigger = & $job.Trigger
            $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew

            $existing = Get-ScheduledTask -TaskName $job.Name -ErrorAction SilentlyContinue
            if ($existing) {
                Set-ScheduledTask -TaskName $job.Name -Action $action -Trigger $trigger -Settings $settings | Out-Null
                Write-Ok "Frissítve: '$($job.Name)' -> $($job.Endpoint)"
            } else {
                Register-ScheduledTask -TaskName $job.Name -Action $action -Trigger $trigger -Settings $settings -Description "FountainTrade automatikus feladat: $($job.Endpoint)" | Out-Null
                Write-Ok "Létrehozva: '$($job.Name)' -> $($job.Endpoint)"
            }
        }
    }
} elseif (-not $SkipScheduledTasks) {
    Write-Warn2 "Cron-feladatok KIMARADTAK (nincs token) — lásd fenti figyelmeztetés."
}

# -------------------------------------------------------------------
# 6. Asztali parancsikon (opcionális kényelmi lépés)
# -------------------------------------------------------------------
Write-Step "Asztali parancsikon"
try {
    $desktopPath = [Environment]::GetFolderPath('Desktop')
    $shortcutPath = Join-Path $desktopPath 'FountainTrade.url'
    if (-not (Test-Path $shortcutPath)) {
        "[InternetShortcut]`nURL=http://localhost:$Port/" | Out-File -FilePath $shortcutPath -Encoding ascii
        Write-Ok "Asztali parancsikon létrehozva: $shortcutPath"
    } else {
        Write-Ok "Asztali parancsikon már létezik — nem módosítva."
    }
} catch {
    Write-Warn2 "Az asztali parancsikon létrehozása sikertelen (nem kritikus): $($_.Exception.Message)"
}

# -------------------------------------------------------------------
# 7. Health-ellenőrzés — TÉNYLEGESEN elérhető-e az app a telepítés után
# -------------------------------------------------------------------
Write-Step "Egészség-ellenőrzés"

$serverProcess = $null
$alreadyRunning = $false
try {
    $testConnection = Test-NetConnection -ComputerName 'localhost' -Port $Port -WarningAction SilentlyContinue -ErrorAction SilentlyContinue
    $alreadyRunning = $testConnection -and $testConnection.TcpTestSucceeded
} catch {
    $alreadyRunning = $false
}

if (-not $alreadyRunning) {
    Write-Ok "A szerver jelenleg nem fut — ideiglenesen elindítjuk az ellenőrzéshez."
    $serverProcess = Start-Process -FilePath $PhpPath -ArgumentList "-S localhost:$Port -t `"$webrootPath`"" -WorkingDirectory $InstallPath -WindowStyle Hidden -PassThru
    Start-Sleep -Seconds 2
}

try {
    $response = Invoke-WebRequest -Uri "http://localhost:$Port/" -UseBasicParsing -TimeoutSec 10 -ErrorAction Stop
    if ($response.StatusCode -eq 200) {
        Write-Ok "A szerver válaszol (HTTP $($response.StatusCode)) — http://localhost:$Port/"
    } else {
        Write-Warn2 "A szerver váratlan státuszkóddal válaszolt: HTTP $($response.StatusCode)"
    }
} catch {
    Write-Err2 "A szerver nem válaszol: $($_.Exception.Message) — indítsd el kézzel: `"$PhpPath`" -S localhost:$Port -t `"$webrootPath`""
} finally {
    if ($serverProcess) {
        Stop-Process -Id $serverProcess.Id -Force -ErrorAction SilentlyContinue
    }
}

# -------------------------------------------------------------------
# 8. Végső összegzés
# -------------------------------------------------------------------
Write-Host "`n========================================" -ForegroundColor Cyan
Write-Host " FountainTrade telepítés — összegzés" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
$script:Summary | ForEach-Object { Write-Host $_ }
Write-Host ""
if ($script:WarningCount -gt 0) {
    Write-Host "$($script:WarningCount) figyelmeztetés — lásd fent a részleteket." -ForegroundColor Yellow
} else {
    Write-Host "Nincs figyelmeztetés." -ForegroundColor Green
}
Write-Host "`nKövetkező lépés: nyisd meg http://localhost:$Port/ a böngészőben az első indítási varázslóhoz (ha még nem futott le)." -ForegroundColor Cyan
