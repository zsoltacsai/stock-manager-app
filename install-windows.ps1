#requires -Version 5.1
<#
.SYNOPSIS
    FountainTrade — teljes, önálló Windows telepítő (1.4.0).

.DESCRIPTION
    Ez a szkript két üzemmódban működik, AUTOMATIKUSAN felismerve, melyikről
    van szó — nincs külön "ügyfél-mód"/"fejlesztői mód" kapcsoló, mert az
    egyetlen megbízható jel maga a fájlrendszer állapota:

      1) FRISS ÜGYFÉLTELEPÍTÉS — a szkript melletti mappában NINCS "webroot"
         (azaz a szkript önmagában, a FountainTrade-Setup.bat-tal és egy
         README-INSTALL.txt-vel együtt lett átadva, lásd 27. pont). Ilyenkor
         a szkript a legutóbbi, HIVATALOSAN PUBLIKÁLT GitHub Release-ből
         tölti le és ellenőrzi (manifest + SHA-256 + a tag Git-referenciából
         független úton feloldott commit) a FountainTrade-et, majd
         kicsomagolja a célkönyvtárba (alapértelmezetten
         C:\ProgramData\FountainTrade).
      2) MEGLÉVŐ TELEPÍTÉS / FEJLESZTŐI KÖRNYEZET — a szkript melletti
         mappában MÁR OTT VAN a "webroot" (pl. mert valaki a teljes
         forráskódot másolta ki, vagy ez egy már működő telepítés
         frissítése/újra-beüzemelése). Ilyenkor a letöltési lépés
         kimarad, és a szkript a saját mappáját ($PSScriptRoot) használja
         célkönyvtárként — ugyanaz a viselkedés, mint az 1.4.0-s eredeti
         verzióban.

    Mindkét esetben ugyanaz a PHP-ellenőrzés/telepítés, mappa-ellenőrzés,
    Feladatütemező-beállítás, parancsikon-létrehozás és egészség-ellenőrzés
    fut le — NINCS párhuzamos telepítési logika a két mód között, csak a
    "honnan jönnek a FountainTrade fájlok" kérdés dől el automatikusan.

    ÖNMAGÁT EMELI ADMIN JOGRA (self-elevation): ha a szkript nem
    Rendszergazdaként fut, automatikusan újraindítja magát UAC-on
    keresztül, ugyanazokkal a paraméterekkel. Ehhez nem kell a
    felhasználónak saját magának PowerShell-t nyitnia vagy Rendszergazdaként
    újraindítania — ez már a FountainTrade-Setup.bat-ból induló hívásra is
    érvényes.

    IDEMPOTENS: másodszori (vagy N-edik) futtatás nem hoz létre duplikált
    feladatokat/parancsikonokat, nem ír felül meglévő adatot/beállítást —
    minden lépés előbb ELLENŐRZI, létezik-e már a cél, és csak akkor
    módosít, ha szükséges.

    NEM TARTALMAZ semmilyen titkot/cron-tokent/API-kulcsot a szkript SAJÁT
    forrásában. A cron-titkos token forrása ebben a sorrendben dől el:
    a -CronToken paraméter → a MÁR LÉTEZŐ data\settings.json cron_secret
    mezője → ha egyik sincs, a szkript maga GENERÁL egy kriptográfiailag
    véletlen tokent és elmenti a settings.json-ba (lásd a 13. pont
    ellenőrzésének eredményét a szkript törzsében és a végső riportban) —
    enélkül egy vadonatúj gépen a háttérfeladatok csendben, észrevétlenül
    sose futnának le sikeresen.

.PARAMETER InstallPath
    A FountainTrade célkönyvtára. Ha nincs megadva: fejlesztői/meglévő
    módban a szkript saját mappája, friss ügyféltelepítésnél
    C:\ProgramData\FountainTrade.

.PARAMETER PhpDir
    A PHP célkönyvtára, ha telepíteni kell. Alapértelmezett: C:\tools\php83.

.PARAMETER PhpPath
    Egy MÁR TELEPÍTETT php.exe teljes útvonala — ha meg van adva, a szkript
    ezt használja, és kihagyja a PHP-keresést/telepítést.

.PARAMETER Port
    A beépített PHP szerver portja. Alapértelmezett: 8000.

.PARAMETER CronToken
    Opcionális — lásd fent a cron-token forrás-sorrendjét.

.PARAMETER Channel
    Az update-csatorna, amiről a friss ügyféltelepítés a GitHub Release-t
    letölti. Jelenleg csak "stable" létezik (lásd AppVersion::DEFAULT_CHANNEL).

.PARAMETER SkipScheduledTasks
    Kihagyja az összes Feladatütemező-bejegyzés létrehozását/frissítését.

.PARAMETER SkipPhpInstall
    Kihagyja a PHP automatikus telepítését — ha nincs található PHP, a
    szkript hibával leáll, ehelyett kézi telepítésre utasítva.

.PARAMETER SkipShortcuts
    Kihagyja az Asztal/Start Menü parancsikonok létrehozását.

.PARAMETER SkipDownload
    Kihagyja a GitHub Release letöltését még akkor is, ha a "webroot" mappa
    hiányzik — ilyenkor a szkript csak a Feladatütemező/PHP/parancsikon
    lépéseket végzi el, feltételezve, hogy a fájlok más úton már ott vannak.

.EXAMPLE
    .\install-windows.ps1
    Automatikus felismerés — friss ügyféltelepítésnél letölti a legutóbbi
    stabil GitHub Release-t, egyébként a saját mappáját használja.

.EXAMPLE
    .\install-windows.ps1 -InstallPath "D:\FountainTrade" -Port 8080
#>

[CmdletBinding()]
param(
    [string]$InstallPath,
    [string]$PhpDir = 'C:\tools\php83',
    [string]$PhpPath,
    [int]$Port = 8000,
    [string]$CronToken,
    [string]$Channel = 'stable',
    [switch]$SkipScheduledTasks,
    [switch]$SkipPhpInstall,
    [switch]$SkipShortcuts,
    [switch]$SkipDownload
)

$ErrorActionPreference = 'Stop'
$script:WarningCount = 0
$script:ErrorCount = 0
$script:Summary = [System.Collections.Generic.List[string]]::new()

# ---------------------------------------------------------------------
# Segédfüggvények — kimenet + összegzés
# ---------------------------------------------------------------------
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
    param([string]$Message, [string]$NextStep = '')
    Write-Host "    [HIBA] $Message" -ForegroundColor Red
    $script:Summary.Add("[HIBA] $Message")
    $script:ErrorCount++
    if ($NextStep) {
        Write-Host "           Következő lépés: $NextStep" -ForegroundColor Red
        $script:Summary.Add("           -> $NextStep")
    }
}

# A szkript SOSE zárja be magát csendben/azonnal hiba esetén — lásd a
# 17. pont explicit követelményét: a felhasználó lássa, MELYIK lépés
# bukott el, MIÉRT, és mi a következő teendő, mielőtt az ablak bezárul.
function Exit-WithFailureSummary {
    param([string]$Reason, [string]$NextStep)
    Write-Err2 $Reason $NextStep
    Show-FinalSummary -Failed
    Write-Host "`nNyomj meg egy billentyűt a kilépéshez..." -ForegroundColor Red
    if (-not $env:FOUNTAINTRADE_NONINTERACTIVE) {
        [void][System.Console]::ReadKey($true)
    }
    exit 1
}

function Show-FinalSummary {
    param([switch]$Failed)
    Write-Host "`n========================================" -ForegroundColor Cyan
    Write-Host " FountainTrade Telepítő — összegzés" -ForegroundColor Cyan
    Write-Host "========================================" -ForegroundColor Cyan
    $script:Summary | ForEach-Object { Write-Host $_ }
    Write-Host ""
    if ($Failed -or $script:ErrorCount -gt 0) {
        Write-Host "A telepítés NEM fejeződött be sikeresen — lásd a fenti [HIBA] sorokat." -ForegroundColor Red
    } elseif ($script:WarningCount -gt 0) {
        Write-Host "$($script:WarningCount) figyelmeztetés — lásd fent a részleteket." -ForegroundColor Yellow
    } else {
        Write-Host "Nincs figyelmeztetés." -ForegroundColor Green
    }
    if ($script:TranscriptPath) {
        Write-Host "`nRészletes napló (hibaelhárításhoz): $script:TranscriptPath" -ForegroundColor DarkGray
        try { Stop-Transcript | Out-Null } catch { }
    }
}

# ---------------------------------------------------------------------
# 0. Self-elevation — a felhasználónak SOSE kell kézzel admin-ként
#    újraindítania, se Execution Policy-t módosítania (lásd 3. pont).
#    Csak a JELENLEGI FOLYAMATRA vonatkozó Bypass-t használunk — a
#    Windows globális Execution Policy-je változatlan marad.
# ---------------------------------------------------------------------
$currentPrincipal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $currentPrincipal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Write-Host ""
    Write-Host "Rendszergazdai jogosultság szükséges a telepítéshez." -ForegroundColor Yellow
    Write-Host "Mindjárt megjelenik egy Windows UAC-ablak (,,Szeretné engedélyezni...'') — fogadd el." -ForegroundColor Yellow
    Write-Host "Elfogadás UTÁN egy ÚJ, rendszergazdai jogú PowerShell-ablak nyílik meg — AZ végzi a tényleges telepítést." -ForegroundColor Yellow
    Write-Host "(Ez az ablak addig nyitva marad, amíg az az új ablak be nem fejeződik.)" -ForegroundColor DarkGray
    Write-Host ""
    $scriptPath = $MyInvocation.MyCommand.Path
    $argList = @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', "`"$scriptPath`"")
    foreach ($key in $PSBoundParameters.Keys) {
        $value = $PSBoundParameters[$key]
        if ($value -is [switch]) {
            if ($value.IsPresent) { $argList += "-$key" }
        } else {
            $argList += "-$key"
            $argList += "`"$value`""
        }
    }
    try {
        # -PassThru + a gyerek folyamat TÉNYLEGES kilépési kódjának
        # továbbítása — élő teszteléssel felfedezett hiba javítása: korábban
        # ez az ág egy sima "exit"-tel zárult, ami MINDIG 0 (siker) kódot
        # adott vissza a hívónak (a FountainTrade-Setup.bat-nak), FÜGGETLENÜL
        # attól, hogy az emelt telepítés ténylegesen sikerült-e — ez
        # megtévesztő "csendben bezáródik" viselkedést okozott.
        $elevatedProcess = Start-Process -FilePath 'powershell.exe' -ArgumentList $argList -Verb RunAs -Wait -PassThru -WindowStyle Normal
        Write-Host "Az emelt jogú telepítő ablak befejeződött (kilépési kód: $($elevatedProcess.ExitCode))." -ForegroundColor $(if ($elevatedProcess.ExitCode -eq 0) { 'Green' } else { 'Red' })
        exit $elevatedProcess.ExitCode
    } catch {
        Write-Host "[HIBA] Az UAC-emelés megszakadt vagy elutasításra került: $($_.Exception.Message)" -ForegroundColor Red
        Write-Host "Rendszergazdai jog nélkül a telepítés nem folytatható (Feladatütemező-bejegyzések létrehozásához szükséges)." -ForegroundColor Red
        if (-not $env:FOUNTAINTRADE_NONINTERACTIVE) { [void][System.Console]::ReadKey($true) }
        exit 1
    }
}

# ---------------------------------------------------------------------
# Transzkript-naplózás — a telepítő teljes kimenete egy fájlba is íródik,
# a $env:TEMP-be (mindig elérhető hely, még mielőtt az InstallPath
# eldőlne) — így egy zavaros/gyorsan bezáródó ablak esetén is UTÓLAG
# visszakereshető, pontosan mi történt. Ha a Start-Transcript valamiért
# nem indul (pl. már fut egy másik transzkript ugyanabban a folyamatban),
# ez NEM állíthatja meg a tényleges telepítést.
$script:TranscriptPath = Join-Path $env:TEMP "FountainTrade-install-$(Get-Date -Format 'yyyyMMdd-HHmmss').log"
try {
    Start-Transcript -Path $script:TranscriptPath -Force | Out-Null
    Write-Host "Napló: $script:TranscriptPath" -ForegroundColor DarkGray
} catch {
    $script:TranscriptPath = $null
}

# A tisztán logikai, mellékhatás-mentes segédfüggvények (GitHub-hoszt
# fehérlista, Zip Slip-védelem, rejtett-ablakos VBS-generálás,
# cron-token-generálás, Feladatütemező-visszaolvasás-ellenőrzés) KÜLÖN
# fájlban élnek — lásd install-windows-lib.ps1 tetején a docblokkot a
# teljes indoklásért (élő tapasztalat: egy korábbi, AST-kinyerésen +
# Invoke-Expression-ön alapuló teszt-módszer víruskereső-karantént
# okozott — egy sima dot-source- os fájl-betöltés ezt elkerüli).
. (Join-Path $PSScriptRoot 'install-windows-lib.ps1')

# ---------------------------------------------------------------------
# 1. Telepítési mód felismerése — friss letöltés vagy meglévő mappa
# ---------------------------------------------------------------------
Write-Step "Telepítési mód felismerése"

$scriptDir = $PSScriptRoot
$hasLocalApp = Test-Path (Join-Path $scriptDir 'webroot\index.php')

if (-not $InstallPath) {
    if ($hasLocalApp) {
        $InstallPath = $scriptDir
    } else {
        $InstallPath = 'C:\ProgramData\FountainTrade'
    }
}

$freshDownloadNeeded = (-not $hasLocalApp) -and (-not $SkipDownload) -and (-not (Test-Path (Join-Path $InstallPath 'webroot\index.php')))

if ($freshDownloadNeeded) {
    Write-Ok "Friss ügyféltelepítés — a FountainTrade fájljai a legutóbbi GitHub Release-ből lesznek letöltve."
} else {
    Write-Ok "Meglévő/helyi FountainTrade-fájlok használva — nincs letöltés."
}
Write-Host "    Célkönyvtár: $InstallPath"

# ---------------------------------------------------------------------
# 2. FountainTrade letöltése és ellenőrzése (csak friss telepítésnél)
#    — UGYANAZT a biztonsági SZERZŐDÉST követi, mint a beépített
#    önfrissítő (src/GitHubReleaseClient.php + src/UpdateVerifier.php):
#    manifest kötelező mezői, SHA-256, és a commit-SHA FÜGGETLEN
#    kereszt-ellenőrzése a GitHub Git Data API-n keresztül (nem elég,
#    ha csak a manifest ÁLLÍTJA magáról). PowerShell nem tudja
#    közvetlenül meghívni a PHP-osztályokat, ezért ugyanazt a SZABÁLY-
#    rendszert ismételjük meg itt, saját, gyengébb "installer-only"
#    logika kitalálása helyett.
# ---------------------------------------------------------------------
$RepoOwner = 'zsoltacsai'
$RepoName  = 'stock-manager-app'
$GitHubApiBase = 'https://api.github.com'
$AllowedAssetHosts = @('github.com', 'objects.githubusercontent.com', 'release-assets.githubusercontent.com', 'api.github.com')

function Install-FountainTradeFromGitHub {
    param([string]$TargetDir, [string]$Channel)

    Write-Step "FountainTrade letöltése a legutóbbi publikált GitHub Release-ből"

    $headers = @{ 'User-Agent' = 'FountainTrade-WindowsInstaller'; 'Accept' = 'application/vnd.github+json' }

    try {
        $release = Invoke-RestMethod -Uri "$GitHubApiBase/repos/$RepoOwner/$RepoName/releases/latest" -Headers $headers -TimeoutSec 30
    } catch {
        Exit-WithFailureSummary "Nem sikerült lekérdezni a legutóbbi GitHub Release-t ($RepoOwner/$RepoName): $($_.Exception.Message)" "Ellenőrizd az internetkapcsolatot, majd futtasd újra a telepítőt. Ha van már helyi FountainTrade-mappád, használd a -SkipDownload kapcsolót."
    }

    $tag = $release.tag_name
    if (-not $tag) {
        Exit-WithFailureSummary "A GitHub Release válasza érvénytelen (hiányzó tag_name)." "Próbáld újra később, vagy jelezd a problémát."
    }

    $manifestAsset = $release.assets | Where-Object { $_.name -eq 'manifest.json' } | Select-Object -First 1
    $zipAsset = $release.assets | Where-Object { $_.name -like 'fountaintrade-*.zip' } | Select-Object -First 1
    if (-not $manifestAsset -or -not $zipAsset) {
        Exit-WithFailureSummary "A legutóbbi GitHub Release ($tag) nem tartalmazza a szükséges assetet (manifest.json / fountaintrade-*.zip)." "Ellenőrizd a repository Release oldalát: https://github.com/$RepoOwner/$RepoName/releases"
    }

    foreach ($assetUrl in @($manifestAsset.browser_download_url, $zipAsset.browser_download_url)) {
        $h = Get-AllowedHost $assetUrl
        if (-not $h -or ($AllowedAssetHosts -notcontains $h)) {
            Exit-WithFailureSummary "Az asset letöltési címe nem a várt GitHub-hoszton van ($h)." "Ez váratlan — jelezd a problémát, ne folytasd a telepítést."
        }
    }

    $tempDir = Join-Path $env:TEMP "fountaintrade-install-$(Get-Date -Format 'yyyyMMddHHmmss')"
    New-Item -ItemType Directory -Path $tempDir -Force | Out-Null
    $manifestPath = Join-Path $tempDir 'manifest.json'
    $zipPath = Join-Path $tempDir $zipAsset.name

    try {
        Invoke-WebRequest -Uri $manifestAsset.browser_download_url -OutFile $manifestPath -Headers $headers -TimeoutSec 30
        $manifest = [System.IO.File]::ReadAllText($manifestPath, [System.Text.Encoding]::UTF8) | ConvertFrom-Json
    } catch {
        Exit-WithFailureSummary "A manifest.json letöltése/beolvasása sikertelen: $($_.Exception.Message)" "Próbáld újra, vagy ellenőrizd a hálózati kapcsolatot."
    }

    $requiredFields = @('product', 'channel', 'version', 'commit', 'artifact', 'sha256', 'min_upgradable_version')
    foreach ($field in $requiredFields) {
        if (-not ($manifest.PSObject.Properties.Name -contains $field) -or [string]::IsNullOrWhiteSpace($manifest.$field)) {
            Exit-WithFailureSummary "A manifest.json hiányos vagy érvénytelen — hiányzó `"$field`" mező." "A Release valószínűleg sérült — jelezd a problémát, ne folytasd."
        }
    }
    if ($manifest.product -ne 'FountainTrade') {
        Exit-WithFailureSummary "A manifest más terméket jelöl (`"$($manifest.product)`"), nem FountainTrade-et." "Ne folytasd — ez váratlan/gyanús állapot."
    }
    if ($manifest.commit -notmatch '^[a-f0-9]{40}$') {
        Exit-WithFailureSummary "A manifest commit mezője nem érvényes teljes Git commit-SHA." "Ne folytasd — a Release valószínűleg sérült."
    }
    if ($manifest.sha256 -notmatch '^[a-f0-9]{64}$') {
        Exit-WithFailureSummary "A manifest sha256 mezője nem érvényes SHA-256 hash." "Ne folytasd — a Release valószínűleg sérült."
    }

    $normalizedTag = $tag.TrimStart('v', 'V')
    if ($normalizedTag -ne $manifest.version) {
        Exit-WithFailureSummary "A manifest verziója ($($manifest.version)) nem egyezik a GitHub Release tag-jével ($tag)." "Ne folytasd — ez váratlan/gyanús állapot."
    }

    # Commit független kereszt-ellenőrzése a GitHub Git Data API-n keresztül
    # — pontosan a GitHubReleaseClient::resolveTagCommitSha() PHP-logikáját
    # követve (lightweight VS annotált tag megkülönböztetése).
    try {
        # A Git-referencia LEKÉRDEZÉSE a tag EREDETI nevével történik (pl.
        # "v1.4.0") — a "v" előtag csak a SemVer-összehasonlításhoz kerül
        # levágva ($normalizedTag), a tényleges Git ref-név ettől független
        # (élő teszteléssel felfedezett hiba: a levágott névvel a GitHub
        # API 404-et adott, mert a valódi tag "v1.4.0", nem "1.4.0").
        $ref = Invoke-RestMethod -Uri "$GitHubApiBase/repos/$RepoOwner/$RepoName/git/ref/tags/$tag" -Headers $headers -TimeoutSec 30
        $resolvedSha = if ($ref.object.type -eq 'tag') {
            (Invoke-RestMethod -Uri "$GitHubApiBase/repos/$RepoOwner/$RepoName/git/tags/$($ref.object.sha)" -Headers $headers -TimeoutSec 30).object.sha
        } else {
            $ref.object.sha
        }
    } catch {
        Exit-WithFailureSummary "A(z) `"$tag`" tag Git-referenciájának feloldása sikertelen: $($_.Exception.Message)" "Próbáld újra később."
    }
    if ($resolvedSha.ToLower() -ne $manifest.commit.ToLower()) {
        Exit-WithFailureSummary "A manifest commit-SHA-ja nem egyezik a GitHub által a tag-hez ténylegesen feloldott commit-tal." "Ne folytasd — ez integritás-sérülésre utal."
    }
    Write-Ok "Manifest ellenőrizve: FountainTrade $($manifest.version), commit $($manifest.commit.Substring(0,12))..."

    try {
        Invoke-WebRequest -Uri $zipAsset.browser_download_url -OutFile $zipPath -Headers $headers -TimeoutSec 120
    } catch {
        Exit-WithFailureSummary "Az artifact letöltése sikertelen: $($_.Exception.Message)" "Ellenőrizd az internetkapcsolatot, majd futtasd újra a telepítőt."
    }

    $actualHash = (Get-FileHash -Path $zipPath -Algorithm SHA256).Hash.ToLower()
    if ($actualHash -ne $manifest.sha256.ToLower()) {
        Remove-Item $zipPath -Force -ErrorAction SilentlyContinue
        Exit-WithFailureSummary "Checksum-eltérés: a letöltött fájl SHA-256 hash-e ($actualHash) nem egyezik a manifestben megadottal ($($manifest.sha256)) — a fájl sérült vagy módosított lehet." "Ne folytasd — töröld az ideiglenes fájlt, próbáld újra a letöltést."
    }
    Write-Ok "SHA-256 checksum egyezik — az artifact sértetlen."

    New-Item -ItemType Directory -Path $TargetDir -Force | Out-Null
    try {
        Expand-SafeZip -ZipPath $zipPath -DestDir $TargetDir
    } catch {
        Exit-WithFailureSummary "Az archívum gyanús bejegyzést tartalmaz: $($_.Exception.Message)" "Ne folytasd — töröld a letöltött fájlt, próbáld újra."
    }

    $requiredExtracted = @('webroot\index.php', 'src\Database.php', 'schema.sql', 'schema.mysql.sql')
    foreach ($rel in $requiredExtracted) {
        if (-not (Test-Path (Join-Path $TargetDir $rel))) {
            Exit-WithFailureSummary "A kicsomagolt csomagból hiányzik egy kötelező fájl: $rel" "A letöltött artifact hibás lehet — próbáld újra a telepítést."
        }
    }
    Write-Ok "FountainTrade $($manifest.version) kicsomagolva ide: $TargetDir"

    Remove-Item -Path $tempDir -Recurse -Force -ErrorAction SilentlyContinue
}

if ($freshDownloadNeeded) {
    Install-FountainTradeFromGitHub -TargetDir $InstallPath -Channel $Channel
}

if (-not (Test-Path (Join-Path $InstallPath 'webroot\index.php'))) {
    Exit-WithFailureSummary "Nem található FountainTrade a célkönyvtárban: $InstallPath" "Futtasd a telepítőt -SkipDownload nélkül, vagy add meg helyesen a -InstallPath paramétert."
}

# Írható mappák a jövőben ACL-t is kaphatnak (lásd 4. pont) — nem-admin
# napi használó (pénztáros) is tudjon írni a data/invoices/assets alá,
# még ha a telepítés maga admin jogból is történt.
Write-Step "Telepítési könyvtár jogosultságainak beállítása"
try {
    icacls $InstallPath /grant '*S-1-5-32-545:(OI)(CI)M' /T /Q | Out-Null
    Write-Ok "A beépített 'Users' csoport Módosítás jogot kapott a célkönyvtárra (nem-admin napi használathoz)."
} catch {
    Write-Warn2 "Az ACL beállítása sikertelen (nem kritikus, ha ugyanaz a fiók telepít és használja az appot): $($_.Exception.Message)"
}

# -------------------------------------------------------------------
# 3. PHP megtalálása, és szükség esetén telepítése
# -------------------------------------------------------------------
Write-Step "PHP telepítés ellenőrzése"

function Get-InstalledPhpExe {
    if ($PhpPath -and (Test-Path $PhpPath)) { return $PhpPath }
    $local = Join-Path $PhpDir 'php.exe'
    if (Test-Path $local) { return $local }
    $fromPath = Get-Command php.exe -ErrorAction SilentlyContinue
    if ($fromPath) { return $fromPath.Source }
    return $null
}

# Hivatalos, ellenőrzött PHP-forrás: windows.php.net saját, gépileg
# olvasható releases.json-ja — VALÓDI, verziózott SHA-256-tal minden
# buildhez (élőben ellenőrizve: 2026-09-21, PHP 8.3.33, "ts-vs16-x64").
# NEM egy "legfrissebb" scrape-elt link — explicit, ellenőrizhető forrás,
# ahogy a kör 5. pontja megköveteli. Winget csak MÁSODLAGOS, ha ez a
# hivatalos forrás valamiért elérhetetlen.
function Install-PhpFromOfficialSource {
    param([string]$TargetDir)

    Write-Host "PHP telepítése a hivatalos windows.php.net forrásból..." -ForegroundColor Yellow
    try {
        $releases = Invoke-RestMethod -Uri 'https://windows.php.net/downloads/releases/releases.json' -TimeoutSec 30
        $v83 = $releases.'8.3'
        $build = $v83.'ts-vs16-x64'
        if (-not $build) { $build = $v83.'ts-vc16-x64' }
        if (-not $build -or -not $build.zip.path) {
            throw 'A releases.json nem tartalmazza a várt PHP 8.3 Thread-Safe x64 buildet.'
        }
        $fileName = $build.zip.path
        $expectedSha256 = $build.zip.sha256

        $tempZip = Join-Path $env:TEMP $fileName
        $downloadUrls = @(
            "https://windows.php.net/downloads/releases/$fileName",
            "https://windows.php.net/downloads/releases/archives/$fileName"
        )
        $downloaded = $false
        foreach ($url in $downloadUrls) {
            try {
                Invoke-WebRequest -Uri $url -OutFile $tempZip -TimeoutSec 120
                $downloaded = $true
                break
            } catch { continue }
        }
        if (-not $downloaded) { throw 'A PHP ZIP letöltése egyik hivatalos útvonalról sem sikerült.' }

        $actualHash = (Get-FileHash -Path $tempZip -Algorithm SHA256).Hash.ToLower()
        if ($actualHash -ne $expectedSha256.ToLower()) {
            Remove-Item $tempZip -Force -ErrorAction SilentlyContinue
            throw "Checksum-eltérés a letöltött PHP csomagnál ($actualHash vs $expectedSha256 várt) — a fájl sérült lehet."
        }

        New-Item -ItemType Directory -Path $TargetDir -Force | Out-Null
        Expand-Archive -Path $tempZip -DestinationPath $TargetDir -Force
        Remove-Item $tempZip -Force -ErrorAction SilentlyContinue

        Write-Ok "PHP $($v83.version) telepítve (SHA-256 ellenőrizve) ide: $TargetDir"
        return (Join-Path $TargetDir 'php.exe')
    } catch {
        Write-Warn2 "A hivatalos windows.php.net forrásból történő telepítés sikertelen: $($_.Exception.Message)"
        return $null
    }
}

function Install-PhpViaWinget {
    param([string]$TargetDir)
    if (-not (Get-Command winget -ErrorAction SilentlyContinue)) { return $null }
    Write-Host "PHP telepítése winget-tel (másodlagos módszer)..." -ForegroundColor Yellow
    try {
        winget install --id PHP.PHP.8.3 -e --accept-package-agreements --accept-source-agreements | Out-Null
        $fromPath = Get-Command php.exe -ErrorAction SilentlyContinue
        if ($fromPath) { return $fromPath.Source }
        return $null
    } catch {
        return $null
    }
}

$phpExe = Get-InstalledPhpExe

if (-not $phpExe -and -not $SkipPhpInstall) {
    $phpExe = Install-PhpFromOfficialSource -TargetDir $PhpDir
    if (-not $phpExe) {
        $phpExe = Install-PhpViaWinget -TargetDir $PhpDir
    }
}

if (-not $phpExe -or -not (Test-Path $phpExe)) {
    Exit-WithFailureSummary "Nem található és nem telepíthető automatikusan PHP." "Telepítsd kézzel a PHP 8.3 x64 Thread Safe csomagot innen: https://windows.php.net/download/ (csomagold ki ide: $PhpDir), majd futtasd újra a telepítőt."
}
Write-Ok "PHP találva: $phpExe"

$phpVersionOutput = & $phpExe -v 2>&1 | Select-Object -First 1
if ($phpVersionOutput -match 'PHP (\d+)\.(\d+)') {
    $major = [int]$Matches[1]
    $minor = [int]$Matches[2]
    if ($major -lt 8 -or ($major -eq 8 -and $minor -lt 1)) {
        Exit-WithFailureSummary "A PHP verzió (${major}.${minor}) túl régi — legalább PHP 8.1 szükséges." "Telepíts PHP 8.1+ verziót, vagy add meg a -PhpPath paraméterrel egy megfelelő php.exe-t."
    }
    Write-Ok "PHP verzió: ${major}.${minor}"
} else {
    Write-Warn2 "Nem sikerült megállapítani a PHP verziószámát a '$phpVersionOutput' kimenetből."
}

# php.ini biztosítása, ha még nincs (friss PHP-telepítésnél normál eset).
$phpHome = Split-Path -Parent $phpExe
$phpIniPath = Join-Path $phpHome 'php.ini'
if (-not (Test-Path $phpIniPath)) {
    $devIni = Join-Path $phpHome 'php.ini-development'
    if (Test-Path $devIni) {
        Copy-Item -LiteralPath $devIni -Destination $phpIniPath -Force
        $iniContent = [System.IO.File]::ReadAllText($phpIniPath, [System.Text.Encoding]::UTF8)
        $iniContent = $iniContent -replace ';extension_dir = "ext"', 'extension_dir = "ext"'
        foreach ($ext in @('curl', 'fileinfo', 'gd', 'mbstring', 'openssl', 'pdo_sqlite', 'sqlite3', 'xmlwriter', 'zip')) {
            $iniContent = $iniContent -replace ";extension=$ext", "extension=$ext"
        }
        $iniContent = $iniContent -replace ';zend_extension=opcache', 'zend_extension=opcache'
        $iniContent += "`n[opcache]`nopcache.enable=1`nopcache.enable_cli=0`nopcache.memory_consumption=64`nopcache.max_accelerated_files=4000`nopcache.revalidate_freq=1`nopcache.validate_timestamps=1`n"
        Set-Content -LiteralPath $phpIniPath -Value $iniContent -Encoding ASCII
        Write-Ok "php.ini létrehozva és a szükséges kiterjesztések bekapcsolva: $phpIniPath"
    } else {
        Write-Warn2 "Nem található sem php.ini, sem php.ini-development itt: $phpHome — a szükséges kiterjesztéseket kézzel kell bekapcsolni."
    }
}

# A README/install.txt szerint kötelező kiterjesztések — a TÉNYLEGES
# `php.exe -m` kimenetet ellenőrizzük, nem csak azt, hogy a php.ini-ben
# szerepel-e valami (lásd a kör 6. pontjának explicit követelménye).
$requiredExtensions = @('pdo_sqlite', 'sqlite3', 'curl', 'mbstring', 'gd', 'xmlwriter', 'zip', 'fileinfo', 'openssl')
$loadedModules = & $phpExe -m 2>&1
$missingExtensions = @($requiredExtensions | Where-Object { $loadedModules -notcontains $_ })
if ($missingExtensions.Count -gt 0) {
    Exit-WithFailureSummary "Hiányzó PHP-kiterjesztések: $($missingExtensions -join ', ')" "Kapcsold be őket a php.ini-ben ($phpIniPath), majd futtasd újra ezt a szkriptet."
}
Write-Ok "Minden szükséges PHP-kiterjesztés be van kapcsolva ($($requiredExtensions -join ', '))."

if ($loadedModules -notcontains 'Zend OPcache') {
    Write-Warn2 "Az OPcache nincs bekapcsolva — nem kötelező, de erősen ajánlott a gyorsabb oldalbetöltéshez."
} else {
    Write-Ok "OPcache bekapcsolva."
}

# -------------------------------------------------------------------
# 4. Mappák — létezés + írhatóság (valódi írás-teszttel)
# -------------------------------------------------------------------
Write-Step "Mappák ellenőrzése ($InstallPath)"

$webrootPath = Join-Path $InstallPath 'webroot'
$toolsDir = Join-Path $InstallPath 'tools'
$writableDirs = @(
    (Join-Path $InstallPath 'data'),
    (Join-Path $InstallPath 'data\backups'),
    (Join-Path $InstallPath 'data\imports'),
    (Join-Path $InstallPath 'invoices'),
    (Join-Path $webrootPath 'assets'),
    $toolsDir
)
foreach ($dir in $writableDirs) {
    if (-not (Test-Path $dir)) {
        New-Item -ItemType Directory -Path $dir -Force | Out-Null
        Write-Ok "Létrehozva: $dir"
    }
    $testFile = Join-Path $dir '.install-write-test'
    try {
        [System.IO.File]::WriteAllText($testFile, 'ok')
        Remove-Item $testFile -Force
        Write-Ok "Írható: $dir"
    } catch {
        Exit-WithFailureSummary "NEM írható: $dir" "Jobb klikk a mappán -> Tulajdonságok -> Biztonság -> adj Módosítás jogot a felhasználódnak, majd futtasd újra a telepítőt."
    }
}

# -------------------------------------------------------------------
# 5. Cron-token — automatikus generálás, ha még sehol sincs (lásd 13. pont
#    ellenőrzésének eredménye: a Database/Settings.php DEFAULTS-ban a
#    cron_secret alapértéke '' — SOHA nincs automatikusan generálva az
#    alkalmazás saját kódjában, csak a Beállítások -> Mentés kézi mentésekor.
#    Enélkül egy vadonatúj gépen a háttérfeladatok csendben sose futnának.)
# -------------------------------------------------------------------
$settingsPath = Join-Path $InstallPath 'data\settings.json'

if (-not $SkipScheduledTasks) {
    Write-Step "Cron-token ellenőrzése/generálása"
    $cronResult = Get-OrCreateCronToken -SettingsPath $settingsPath -SuppliedToken $CronToken
    $CronToken = $cronResult.Token
    if ($cronResult.Generated) {
        Write-Ok "Nem volt elérhető cron-token — a telepítő újat generált és elmentette a data\settings.json-ba (a Beállítások -> Mentés oldalon később bármikor lecserélhető)."
    } else {
        Write-Ok "Cron-token elérhető (paraméterből vagy a meglévő beállításokból)."
    }
}

# -------------------------------------------------------------------
# 6. Feladatütemező — PHP szerver bejelentkezéskor, crash esetén újraindul
# -------------------------------------------------------------------
function Test-PortInUseByOurServer {
    param([int]$Port)
    try {
        $resp = Invoke-WebRequest -Uri "http://localhost:$Port/api/install-status.php" -UseBasicParsing -TimeoutSec 3 -ErrorAction Stop
        return $resp.StatusCode -eq 200
    } catch {
        return $false
    }
}

if (-not $SkipScheduledTasks) {
    Write-Step "Feladatütemező — PHP szerver automatikus indítása"

    $listener = Get-NetTCPConnection -LocalPort $Port -State Listen -ErrorAction SilentlyContinue
    if ($listener) {
        if (Test-PortInUseByOurServer -Port $Port) {
            Write-Ok "A(z) $Port port már egy futó FountainTrade-példány alatt van — ezt használjuk tovább, nem indítunk másikat."
        } else {
            Exit-WithFailureSummary "A(z) $Port port már foglalt, de nem egy FountainTrade-példány válaszol rajta." "Válassz másik portot a -Port paraméterrel, vagy zárd be a portot jelenleg használó alkalmazást."
        }
    }

    $serverTaskName = 'FountainTrade - Szerver'
    # Csak localhost/loopback — SOSE minden hálózati interfészre (lásd
    # 11. és 22. pont: "ne nyisson szükségtelen hálózati portot").
    #
    # REJTETT ABLAK (élő teszteléssel felfedezett, release-blocking UX-hiba
    # javítása): a php.exe konzol-alkalmazás — közvetlenül Feladatütemező-
    # akcióként indítva egy TARTÓSAN NYITVA MARADÓ, látható fekete
    # konzolablakot eredményezne a bejelentkezett felhasználó képernyőjén,
    # amíg a szerver fut (azaz gyakorlatilag folyamatosan). A
    # wscript.exe-n keresztüli rejtett indítás (lásd fent
    # New-HiddenLauncherVbs) ezt teljesen megszünteti — a php.exe
    # folyamat maga változatlanul, teljes értékűen fut a háttérben, csak
    # az ablaka nem jelenik meg.
    $serverVbsPath = Join-Path $toolsDir 'run-server-hidden.vbs'
    New-HiddenLauncherVbs -VbsPath $serverVbsPath -ExePath $phpExe -Arguments "-S localhost:$Port -t `"$webrootPath`""
    $serverAction = New-ScheduledTaskAction -Execute 'wscript.exe' -Argument "//B //NoLogo `"$serverVbsPath`"" -WorkingDirectory $InstallPath
    # Élő teszteléssel elkülönítve igazolva (izolált Register-ScheduledTask
    # próbákkal, 2026-09-21): pontosan az "-AtLogOn" trigger-TÍPUS igényel
    # valódi rendszergazdai jogot a regisztráláshoz — egy "-Once" ismétlődő
    # trigger (lásd a cron-feladatok lentebb) NEM. Ez az oka annak, hogy egy
    # nem emelt jogú futtatásnál PONT ez a bejegyzés bukik el "Access is
    # denied"-del, miközben a cron-feladatok sikeresen létrejönnek — ez NEM
    # hiba, hanem a Windows Feladatütemező saját, dokumentálatlan, de
    # reprodukálható viselkedése, és pontosan ez az oka annak, hogy a
    # szkript elején a self-elevation (0. lépés) MINDIG lefut.
    $serverTrigger = New-ScheduledTaskTrigger -AtLogOn
    $serverSettings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 5 -RestartInterval (New-TimeSpan -Minutes 1)

    # FONTOS (élő teszteléssel felfedezett hiba): a Register-ScheduledTask/
    # Set-ScheduledTask CIM-alapú hibája NEM terminating error alapértelmezetten
    # — a globális $ErrorActionPreference='Stop' MAGÁBAN NEM állítja meg a
    # végrehajtást, és a hívás UTÁNI kód (pl. a "[OK]" üzenet) TÉVESEN
    # sikeresként futott volna tovább egy ténylegesen elutasított (pl.
    # "Access is denied" jogosultsági hiba miatt meghiúsult) hívás után is.
    # Ezért itt MINDEN Feladatütemező-hívás explicit -ErrorAction Stop-pal
    # + try/catch-csel fut, hogy egy valódi hiba SOHA ne tűnjön "[OK]"-nak.
    $serverVbsArgs = "//B //NoLogo `"$serverVbsPath`""
    $serverTaskCreated = $false
    try {
        $existingServerTask = Get-ScheduledTask -TaskName $serverTaskName -ErrorAction SilentlyContinue
        if ($existingServerTask) {
            Set-ScheduledTask -TaskName $serverTaskName -Action $serverAction -Trigger $serverTrigger -Settings $serverSettings -ErrorAction Stop | Out-Null
        } else {
            Register-ScheduledTask -TaskName $serverTaskName -Action $serverAction -Trigger $serverTrigger -Settings $serverSettings -Description 'FountainTrade beépített PHP szerver indítása bejelentkezéskor (csak localhost).' -ErrorAction Stop | Out-Null
        }
        # Visszaolvasás + tényleges ellenőrzés (lásd a kör 8. pontja) — a
        # hívás sikeres visszatérése MAGÁBAN nem elég egy "[OK]"-hoz.
        $verify = Test-ScheduledTaskRegistration -TaskName $serverTaskName -ExpectedExecute 'wscript.exe' -ExpectedArguments $serverVbsArgs -ExpectedWorkingDirectory $InstallPath
        if ($verify.Ok) {
            $serverTaskCreated = $true
            Write-Ok "Feladatütemező-bejegyzés létrehozva/frissítve és visszaolvasással ellenőrizve: '$serverTaskName' (rejtett ablakkal, wscript.exe-n keresztül)"
        } else {
            Write-Err2 "A(z) '$serverTaskName' bejegyzés a regisztráció UTÁN, visszaolvasáskor NEM felel meg az elvártnak: $($verify.Reason)" "Ellenőrizd kézzel a Feladatütemezőben ('taskschd.msc'), vagy futtasd újra a telepítőt."
        }
    } catch {
        Write-Err2 "A(z) '$serverTaskName' Feladatütemező-bejegyzés létrehozása/frissítése sikertelen: $($_.Exception.Message)" "Ellenőrizd, hogy rendszergazdai jogban fut-e a telepítő, és hogy a Feladatütemező szolgáltatás (Task Scheduler) elérhető-e. Kézi indítás: `"$phpExe`" -S localhost:$Port -t `"$webrootPath`""
    }

    if ($serverTaskCreated -and -not $listener) {
        try {
            Start-ScheduledTask -TaskName $serverTaskName -ErrorAction Stop
            Start-Sleep -Seconds 2
        } catch {
            Write-Warn2 "A szerver Task létrejött, de nem indult el azonnal (a következő bejelentkezéskor automatikusan elindul): $($_.Exception.Message)"
        }
    }
} else {
    Write-Step "Feladatütemező-lépések kihagyva (-SkipScheduledTasks)"
}

# -------------------------------------------------------------------
# 7. Cron-feladatok (idempotens létrehozás/frissítés)
# -------------------------------------------------------------------
if (-not $SkipScheduledTasks -and $CronToken) {
    Write-Step "Automatikus háttérfeladatok (cron) beállítása"

    $cronJobs = @(
        @{ Name = 'FountainTrade - WooCommerce szinkron'; Endpoint = 'auto-sync-run.php'; Trigger = { New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650) } },
        @{ Name = 'FountainTrade - Biztonsagi mentes'; Endpoint = 'auto-backup-run.php'; Trigger = { New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 15) -RepetitionDuration (New-TimeSpan -Days 3650) } },
        @{ Name = 'FountainTrade - NAV kimeno queue'; Endpoint = 'nav-queue-run.php'; Trigger = { New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650) } },
        @{ Name = 'FountainTrade - NAV bejovo szinkron'; Endpoint = 'nav-incoming-sync-run.php'; Trigger = { New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Hours 1) -RepetitionDuration (New-TimeSpan -Days 3650) } },
        @{ Name = 'FountainTrade - Frissites ellenorzes'; Endpoint = 'update-check-run.php'; Trigger = { New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 30) -RepetitionDuration (New-TimeSpan -Days 3650) } }
    )

    $curlPath = (Get-Command curl.exe -ErrorAction SilentlyContinue).Source
    if (-not $curlPath) {
        Write-Err2 "A curl.exe nem található (Windows 10 1803+ alapból tartalmazza) — a cron-feladatok létrehozása kimarad." "Telepítsd a curl-t, vagy futtasd újra a telepítőt egy újabb Windows-verzión."
    } else {
        foreach ($job in $cronJobs) {
            $url = "http://localhost:$Port/api/$($job.Endpoint)"
            $curlArgs = "-s -H `"X-Cron-Token: $CronToken`" `"$url`""
            # Rejtett ablak (ugyanaz az indoklás, mint a szerver-tasknál) —
            # a curl.exe konzol-alkalmazás, közvetlen Feladatütemező-
            # akcióként percenként/óránként felvillanó konzolablakot adna.
            # MELLÉKESEN ez a cron-titkos tokent is KIVESZI a Feladatütemező
            # saját, könnyen böngészhető Action/Arguments mezőjéből — élő
            # teszteléssel felfedezett, valódi biztonsági megfigyelés (lásd
            # a New-HiddenLauncherVbs docblokkja) —, a token ehelyett a
            # generált .vbs fájl TARTALMÁBAN van, ugyanolyan bizalmi
            # szinten, mint a data\settings.json.
            $cronVbsPath = Join-Path $toolsDir ("run-cron-" + ($job.Endpoint -replace '\.php$', '') + ".vbs")
            New-HiddenLauncherVbs -VbsPath $cronVbsPath -ExePath $curlPath -Arguments $curlArgs
            $cronVbsArgs = "//B //NoLogo `"$cronVbsPath`""
            $action = New-ScheduledTaskAction -Execute 'wscript.exe' -Argument $cronVbsArgs
            $trigger = & $job.Trigger
            $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -StartWhenAvailable -MultipleInstances IgnoreNew

            try {
                $existing = Get-ScheduledTask -TaskName $job.Name -ErrorAction SilentlyContinue
                if ($existing) {
                    Set-ScheduledTask -TaskName $job.Name -Action $action -Trigger $trigger -Settings $settings -ErrorAction Stop | Out-Null
                } else {
                    Register-ScheduledTask -TaskName $job.Name -Action $action -Trigger $trigger -Settings $settings -Description "FountainTrade automatikus feladat: $($job.Endpoint)" -ErrorAction Stop | Out-Null
                }
                # Visszaolvasás + tényleges ellenőrzés (lásd a kör 8-9. pontja).
                $verify = Test-ScheduledTaskRegistration -TaskName $job.Name -ExpectedExecute 'wscript.exe' -ExpectedArguments $cronVbsArgs
                if ($verify.Ok) {
                    Write-Ok "'$($job.Name)' -> $($job.Endpoint) — létrehozva/frissítve, visszaolvasással ellenőrizve (rejtett ablakkal)"
                } else {
                    Write-Err2 "A(z) '$($job.Name)' bejegyzés a regisztráció UTÁN, visszaolvasáskor NEM felel meg az elvártnak: $($verify.Reason)" "Ellenőrizd kézzel a Feladatütemezőben ('taskschd.msc'), vagy futtasd újra a telepítőt."
                }
            } catch {
                Write-Err2 "A(z) '$($job.Name)' Feladatütemező-bejegyzés létrehozása/frissítése sikertelen: $($_.Exception.Message)" "Hozd létre kézzel a Feladatütemezőben, vagy futtasd újra a telepítőt rendszergazdai jogban."
            }
        }
    }
} elseif (-not $SkipScheduledTasks) {
    Write-Warn2 "Cron-feladatok KIMARADTAK (nincs token) — lásd fenti figyelmeztetés."
}

# -------------------------------------------------------------------
# 8. Parancsikonok — Asztal + Start Menü, Dashboard-ra mutatva
#    (NEM a Kasszára/index.php-ra — lásd 15. pont: Dashboard = kezdőoldal)
# -------------------------------------------------------------------
if (-not $SkipShortcuts) {
    Write-Step "Parancsikonok létrehozása"

    $dashboardUrl = "http://localhost:$Port/dashboard.php"
    $edgePath = @(
        "$env:ProgramFiles\Microsoft\Edge\Application\msedge.exe",
        "${env:ProgramFiles(x86)}\Microsoft\Edge\Application\msedge.exe"
    ) | Where-Object { $_ -and (Test-Path $_) } | Select-Object -First 1

    $expectedTarget = if ($edgePath) { $edgePath } else { $dashboardUrl }
    $expectedArguments = if ($edgePath) { "--app=$dashboardUrl" } else { '' }

    function New-FountainTradeShortcut {
        param([string]$LinkPath, [string]$ExpectedTarget, [string]$ExpectedArguments)

        $shell = New-Object -ComObject WScript.Shell

        if (Test-Path $LinkPath) {
            # Élő teszteléssel felfedezett hiányosság javítása: korábban egy
            # MÁR LÉTEZŐ parancsikon sose lett újraellenőrizve/frissítve —
            # egy port-váltással újrafuttatott telepítő emiatt egy ELAVULT
            # (régi portra mutató) parancsikont hagyott volna hátra. Most
            # visszaolvassuk és ÖSSZEHASONLÍTJUK a ténylegesen elvárt
            # célponttal — csak akkor írunk, ha valóban eltér.
            $existing = $shell.CreateShortcut($LinkPath)
            if ($existing.TargetPath -eq $ExpectedTarget -and $existing.Arguments -eq $ExpectedArguments) {
                Write-Ok "Parancsikon már létezik és helyes — nem módosítva: $LinkPath"
                return
            }
            Write-Warn2 "A meglévő parancsikon elavult célpontra mutatott — frissítve: $LinkPath"
        }

        $shortcut = $shell.CreateShortcut($LinkPath)
        $shortcut.TargetPath = $ExpectedTarget
        $shortcut.Arguments = $ExpectedArguments
        $shortcut.IconLocation = "$webrootPath\favicon.svg"
        $shortcut.Save()

        # Visszaolvasás + tényleges ellenőrzés (lásd a kör 15. pontja: "ne
        # csak fájllétezést" — Target/Arguments/WorkingDirectory).
        $verify = $shell.CreateShortcut($LinkPath)
        if ($verify.TargetPath -eq $ExpectedTarget -and $verify.Arguments -eq $ExpectedArguments) {
            Write-Ok "Parancsikon létrehozva/frissítve és visszaolvasással ellenőrizve: $LinkPath"
        } else {
            Write-Err2 "A parancsikon mentés után, visszaolvasáskor NEM a várt célpontra mutat: $LinkPath" "Hozd létre kézzel: cél = $ExpectedTarget"
        }
    }

    $desktopPath = Join-Path ([Environment]::GetFolderPath('Desktop')) 'FountainTrade.lnk'
    New-FountainTradeShortcut -LinkPath $desktopPath -ExpectedTarget $expectedTarget -ExpectedArguments $expectedArguments

    $startMenuDir = [Environment]::GetFolderPath('StartMenu') + '\Programs'
    if (Test-Path $startMenuDir) {
        $startMenuPath = Join-Path $startMenuDir 'FountainTrade.lnk'
        New-FountainTradeShortcut -LinkPath $startMenuPath -ExpectedTarget $expectedTarget -ExpectedArguments $expectedArguments
    }
}

# -------------------------------------------------------------------
# 9. Egészség-ellenőrzés + böngésző-megnyitás
#    A meglévő install.php varázslót nyitjuk meg, ha a app MÉG NINCS
#    inicializálva — a varázsló saját maga TOKENT követel (lásd
#    webroot/install.php — ez a kör 1. pontjának ellenőrzése során
#    kiderült, valódi kód-vizsgálattal, nem feltételezésből), amit a
#    topbar.js kliens-oldali átirányítása NEM ad át, emiatt e nélkül a
#    lépés nélkül a felhasználó egy 403-as, Unix-parancsra ("cat
#    data/.install-token") hivatkozó hibaoldalt látna Windows alatt —
#    ez a szkript a token-fájlt közvetlenül, helyi fájlrendszer-
#    hozzáféréssel olvassa ki (ugyanaz a bizalmi szint, mint amivel a
#    telepítő amúgy is módosítja a settings.json-t), és a böngészőt
#    egyből a helyes, token-nel ellátott címen nyitja meg. Ez NEM
#    gyengíti a token védelmét — egy távoli internetes látogató
#    továbbra sem fér hozzá a helyi fájlrendszerhez.
# -------------------------------------------------------------------
Write-Step "Egészség-ellenőrzés"

$serverReady = $false
for ($i = 0; $i -lt 10; $i++) {
    try {
        $response = Invoke-WebRequest -Uri "http://localhost:$Port/" -UseBasicParsing -TimeoutSec 5 -ErrorAction Stop
        if ($response.StatusCode -eq 200) { $serverReady = $true; break }
    } catch { }
    Start-Sleep -Seconds 1
}

if (-not $serverReady) {
    Exit-WithFailureSummary "A szerver nem válaszol 10 másodperc után sem (http://localhost:$Port/)." "Indítsd el kézzel: `"$phpExe`" -S localhost:$Port -t `"$webrootPath`", és ellenőrizd a hibaüzenetet."
}
Write-Ok "A szerver válaszol — http://localhost:$Port/"

$launchUrl = "http://localhost:$Port/dashboard.php"
try {
    $installStatus = Invoke-RestMethod -Uri "http://localhost:$Port/api/install-status.php" -TimeoutSec 5
    if (-not $installStatus.installed) {
        # Az install.php GET kérése magától generálja a token-fájlt, ha még
        # nincs — utána közvetlenül a helyi lemezről olvassuk ki.
        try { Invoke-WebRequest -Uri "http://localhost:$Port/install.php" -UseBasicParsing -TimeoutSec 5 -ErrorAction SilentlyContinue | Out-Null } catch { }
        $tokenPath = Join-Path $InstallPath 'data\.install-token'
        if (Test-Path $tokenPath) {
            $installToken = ([System.IO.File]::ReadAllText($tokenPath, [System.Text.Encoding]::UTF8)).Trim()
            $launchUrl = "http://localhost:$Port/install.php?token=$installToken"
            Write-Ok "Az app még nincs inicializálva — a telepítő varázsló nyílik meg."
        } else {
            $launchUrl = "http://localhost:$Port/install.php"
            Write-Warn2 "Nem sikerült kiolvasni a telepítő-token fájlt — a telepítő varázsló token nélkül nyílik meg (403 hibaoldalt fog mutatni)."
        }
    } else {
        Write-Ok "Az app már inicializálva van — a Dashboard nyílik meg."
    }
} catch {
    Write-Warn2 "A telepítési állapot lekérdezése sikertelen — a Dashboard címét nyitjuk meg alapértelmezésként."
}

try {
    Start-Process $launchUrl
    Write-Ok "Böngésző megnyitva: $launchUrl"
} catch {
    Write-Warn2 "A böngésző automatikus megnyitása sikertelen — nyisd meg kézzel: $launchUrl"
}

# -------------------------------------------------------------------
# 10. Végső összegzés
# -------------------------------------------------------------------
Show-FinalSummary
Write-Host "`nMegnyitás: $launchUrl" -ForegroundColor Cyan
if (-not $env:FOUNTAINTRADE_NONINTERACTIVE) {
    Write-Host "`nNyomj meg egy billentyűt a bezáráshoz..." -ForegroundColor DarkGray
    [void][System.Console]::ReadKey($true)
}
