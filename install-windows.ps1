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
    [switch]$SkipDownload,
    # Fázis 2, Checkpoint 3 — node-szerepkör (Önálló gép/Szerver/Kliens).
    # Ha nincs megadva: nem-interaktív módban ($env:FOUNTAINTRADE_NONINTERACTIVE)
    # az alapértelmezett 'standalone' (VAGY a már meglévő installer-generated.php
    # aktuális szerepköre, ha a gép már be van állítva — lásd a szkript törzse),
    # interaktív módban a szkript egy konzol-menüt jelenít meg.
    [ValidateSet('', 'standalone', 'server', 'client')]
    [string]$NodeRole = '',
    # Kliens módhoz — a távoli FountainTrade Szerver címe/hitelesítő adatai.
    [string]$ServerUrl,
    [string]$ClientId,
    [string]$ClientSecret,
    # Explicit megerősítés egy "veszélyes" szerepkör-váltáshoz (bármilyen
    # módból Kliensre — lásd a kör 16. pontja: a helyi adatbázis ilyenkor a
    # lemezen marad, de az alkalmazás többé nem ezt használja).
    [switch]$ConfirmModeSwitch,
    # Fázis 6, Rész B — Ollama (helyi AI) telepítés a Windows telepítőből.
    # Kizárólag Önálló gép/Szerver szerepkörnél kínálható fel/futtatható —
    # Kliens node-on ez a lépés SOSE fut le, még akkor sem, ha valaki
    # tévedésből megadná ezt a kapcsolót (lásd Resolve-OllamaProvisionDecision).
    # $InstallOllama/$SkipOllama hiányában a szkript interaktívan kérdez;
    # nem-interaktív módban ($env:FOUNTAINTRADE_NONINTERACTIVE) az
    # alapértelmezés "nem települ" (nincs csendes, meglepetésszerű telepítés).
    [switch]$InstallOllama,
    [switch]$SkipOllama,
    # Ha jelen van, egy sikertelen Ollama-telepítés a TELJES FountainTrade
    # telepítést megszakítja — alapból NEM ez történik (a kör 23. pontja:
    # "FountainTrade installation must fail only if the user explicitly
    # selected 'required' semantics... POS should still be installable").
    [switch]$OllamaRequired,
    [string]$OllamaModel = 'qwen3:8b'
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

# Legkisebb jogosultság (lásd Get-FountainTradeAclPlan): a PHP szerver és a
# cron-feladatok a telepítő fiókjával futnak — csak ez a fiók (+ SYSTEM/
# Administrators) írhat a telepítésbe; a többi helyi felhasználó (pl.
# pénztáros Windows-fiók) a kódot csak olvashatja, a config/data/invoices
# mappákhoz pedig nem fér hozzá.
Write-Step "Telepítési könyvtár jogosultságainak beállítása"
try {
    $installerOwnerSid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
    Invoke-FountainTradeAclPlan -Plan (Get-FountainTradeAclPlan -InstallPath $InstallPath -OwnerSid $installerOwnerSid)
    Write-Ok "Jogosultságok beállítva: írás csak a telepítő fióknak/rendszergazdáknak; config/data/invoices más helyi felhasználók számára nem elérhető."
} catch {
    Exit-WithFailureSummary "A telepítési könyvtár jogosultságainak beállítása sikertelen: $($_.Exception.Message)" "Futtasd a telepítőt rendszergazdaként; egy hiányos ACL mellett a telepítés nem biztonságos."
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
# Node-szerepkör (Fázis 2, Checkpoint 3) — Önálló gép / Szerver / Kliens.
# A tényleges olvasás/írás a config/installer-generated.php-ba a
# tools/installer-set-topology.php PHP CLI-eszközön keresztül történik
# (lásd annak docblokkja) — SOSE egy PowerShell-oldali, saját
# PHP-array-szerializálással. A 'tools' mappa MINDIG része egy valódi
# telepítésnek (a self-frissítés is erre épül, lásd README "Önfrissítés"
# szakasza), tehát friss GitHub Release-telepítésnél is garantáltan jelen
# van, nem kell külön tartalék-forrást keresni.
# -------------------------------------------------------------------
Write-Step "Node-szerepkör (Önálló gép / Szerver / Kliens)"

$topologyToolPath = Join-Path $InstallPath 'tools\installer-set-topology.php'
if (-not (Test-Path $topologyToolPath)) {
    Exit-WithFailureSummary "Hiányzik a tools\installer-set-topology.php a telepített FountainTrade-ből." "A letöltött/másolt csomag hiányos lehet — próbáld újra a telepítést."
}

$existingTopology = Invoke-FountainTradeTopologyTool -PhpExe $phpExe -ToolPath $topologyToolPath -Action 'get'
if ($existingTopology.ExitCode -ne 0 -or -not $existingTopology.Json -or -not $existingTopology.Json.ok) {
    Exit-WithFailureSummary "Nem sikerült beolvasni a jelenlegi node-szerepkört: $($existingTopology.Raw)" "Ellenőrizd a PHP telepítést, majd futtasd újra a telepítőt."
}
$existingNodeRole = $existingTopology.Json.node_role
$existingClient = $existingTopology.Json.client

$resolvedNodeRole = $null
if ($NodeRole) {
    $resolvedNodeRole = $NodeRole
} elseif ($env:FOUNTAINTRADE_NONINTERACTIVE) {
    # Nem-interaktív rerun: a MÁR beállított szerepkört tartjuk meg — lásd a
    # kör 15. pontja (idempotencia) és 12. pontja (node_role hiányában
    # 'standalone', amit Invoke-FountainTradeTopologyTool 'get'-je már
    # magától visszaad).
    $resolvedNodeRole = $existingNodeRole
} else {
    $currentLabel = switch ($existingNodeRole) { 'server' { 'Szerver' } 'client' { 'Kliens' } default { 'Önálló gép' } }
    Write-Host ""
    Write-Host "Milyen szerepet kap ez a számítógép?" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "  1) Önálló gép" -ForegroundColor White
    Write-Host "  2) Szerver" -ForegroundColor White
    Write-Host "  3) Kliens" -ForegroundColor White
    Write-Host ""
    Write-Host "Jelenlegi beállítás: $currentLabel — Enter = ezt tartja meg." -ForegroundColor DarkGray
    $rawChoice = Read-Host "Választás (1/2/3, vagy Enter)"
    $resolvedNodeRole = Resolve-NodeRoleChoice -RawInput $rawChoice -DefaultRole $existingNodeRole
    if (-not $resolvedNodeRole) {
        Exit-WithFailureSummary "Érvénytelen szerepkör-választás: '$rawChoice'" "Futtasd újra a telepítőt, és válassz 1/2/3 közül (vagy hagyd üresen a jelenlegi megtartásához)."
    }
}

$isModeSwitch = $resolvedNodeRole -ne $existingNodeRole
if ($isModeSwitch) {
    Write-Warn2 "Szerepkör-váltás: '$existingNodeRole' -> '$resolvedNodeRole'."
    # A kör 16. pontjának explicit követelménye: a Kliens irányba váltás a
    # "veszélyes" eset — a gép saját, HELYI adatbázisa (ha eddig Önálló vagy
    # Szerver volt, és van benne valódi adat) a lemezen MARAD, de az
    # alkalmazás onnantól nem ezt használja, mert minden adat a távoli
    # Szerveren jelenik meg. Ez könnyen "eltűnt adatnak" tűnhet egy nem
    # figyelmeztetett üzemeltetőnek.
    $localDbPath = Join-Path $InstallPath 'data\stock.sqlite'
    $hasLocalData = ($existingNodeRole -ne 'client') -and (Test-Path $localDbPath) -and ((Get-Item $localDbPath).Length -gt 0)
    if ($resolvedNodeRole -eq 'client' -and $hasLocalData) {
        Write-Host ""
        Write-Host "FIGYELEM: ez a gép jelenleg '$existingNodeRole' módban fut, SAJÁT (nem üres) adatbázissal." -ForegroundColor Yellow
        Write-Host "Kliens módra váltás után a HELYI adatbázis a lemezen marad, de az alkalmazás" -ForegroundColor Yellow
        Write-Host "mostantól NEM ezt használja — minden adat a távoli Szerveren jelenik meg." -ForegroundColor Yellow
        Write-Host ""
        if ($ConfirmModeSwitch) {
            Write-Ok "A -ConfirmModeSwitch kapcsoló jelen van — a váltás megerősítve."
        } elseif ($env:FOUNTAINTRADE_NONINTERACTIVE) {
            Exit-WithFailureSummary "Veszélyes szerepkör-váltás ('$existingNodeRole' -> 'client') nem-interaktív módban, megerősítés nélkül." "Add meg a -ConfirmModeSwitch kapcsolót, ha ez tényleg szándékos, vagy futtasd a telepítőt interaktívan."
        } else {
            $confirm = Read-Host "Biztosan folytatod? (írd be: igen)"
            if ($confirm.Trim().ToLowerInvariant() -ne 'igen') {
                Exit-WithFailureSummary "A szerepkör-váltás megszakítva (nincs explicit megerősítés)." "Futtasd újra a telepítőt, és erősítsd meg 'igen'-nel, ha valóban Kliens módra szeretnél váltani."
            }
        }
    }
}

$clientServerUrl = $ServerUrl
$clientId = $ClientId
$clientSecret = $ClientSecret
if ($resolvedNodeRole -eq 'client') {
    $needPrompt = (-not $clientServerUrl) -or (-not $clientId) -or (-not $clientSecret)
    if ($needPrompt -and -not $isModeSwitch -and $existingNodeRole -eq 'client' -and $existingClient.client_id) {
        # Idempotens rerun ugyanabban a szerepkörben — a MÁR eltárolt
        # hitelesítő adatokat használjuk újra, nem kérdezünk (és NEM
        # töröljük/üresítjük egy hiányos paraméterezésű rerunnal — lásd a
        # kör 15. pontja: "elvesző client secret" tilos).
        if (-not $clientServerUrl) { $clientServerUrl = $existingClient.server_url }
        if (-not $clientId) { $clientId = $existingClient.client_id }
        if (-not $clientSecret) { $clientSecret = $existingClient.client_secret }
        $needPrompt = (-not $clientServerUrl) -or (-not $clientId) -or (-not $clientSecret)
    }
    if ($needPrompt) {
        if ($env:FOUNTAINTRADE_NONINTERACTIVE) {
            Exit-WithFailureSummary "Kliens módhoz -ServerUrl/-ClientId/-ClientSecret paraméterek szükségesek nem-interaktív módban." "Add meg mindhárom paramétert, vagy futtasd a telepítőt interaktívan."
        }
        Write-Host ""
        Write-Host "Ez a gép Kliens terminálként fog futni — egy MÁR REGISZTRÁLT FountainTrade" -ForegroundColor Cyan
        Write-Host "Szerver címére és hitelesítő adataira van szükség (lásd a Szerveren a Kliensek oldalt)." -ForegroundColor Cyan
        Write-Host ""
        if (-not $clientServerUrl) {
            $clientServerUrl = Read-Host "FountainTrade szerver címe (pl. http://192.168.1.10:8000)"
        }
        if (-not $clientId) {
            $clientId = Read-Host "Client ID (cl_...)"
        }
        if (-not $clientSecret) {
            # A titok NE jelenjen meg visszhangozva a képernyőn/naplóban.
            $secureSecret = Read-Host "Client Secret" -AsSecureString
            $bstr = [System.Runtime.InteropServices.Marshal]::SecureStringToBSTR($secureSecret)
            try {
                $clientSecret = [System.Runtime.InteropServices.Marshal]::PtrToStringAuto($bstr)
            } finally {
                [System.Runtime.InteropServices.Marshal]::ZeroFreeBSTR($bstr)
            }
        }
    }

    # A kör 5. pontja — nem blokkoló figyelmeztetés, HA a Kliens
    # konfigurált Szerver-címe sima HTTP. A HMAC-aláírás hitelesíti a
    # kérést (bizonyítja, hogy a KLIENS küldte), de NEM titkosítja — ez
    # NEM TLS-helyettesítő. LAN-on belüli telepítésnél ez 1.5.0-ban
    # SZÁNDÉKOSAN megengedett marad (nincs automatikus letiltás), csak
    # egyértelműen jelezzük.
    if ($clientServerUrl -and $clientServerUrl -match '^(?i)http://') {
        Write-Warn2 "A kapcsolat nem titkosított. Internetes használathoz HTTPS szükséges. LAN deployment ettől még működhet."
    }
}

$topologySetArgs = @{ PhpExe = $phpExe; ToolPath = $topologyToolPath; Action = 'set'; NodeRole = $resolvedNodeRole }
if ($resolvedNodeRole -eq 'client') {
    $topologySetArgs.ServerUrl = $clientServerUrl
    $topologySetArgs.ClientId = $clientId
    $topologySetArgs.ClientSecret = $clientSecret
}
$topologySetResult = Invoke-FountainTradeTopologyTool @topologySetArgs
if ($topologySetResult.ExitCode -ne 0 -or -not $topologySetResult.Json -or -not $topologySetResult.Json.ok) {
    $topologyError = if ($topologySetResult.Json) { $topologySetResult.Json.error } else { $topologySetResult.Raw }
    Exit-WithFailureSummary "A node-szerepkör beállítása sikertelen: $topologyError" "Ellenőrizd a megadott szerver-címet/hitelesítő adatokat, majd futtasd újra a telepítőt."
}
$NodeRole = $resolvedNodeRole
# A titok a SAJÁT forrásunkba/naplóba SOSE kerül — a fenti Invoke hívás is
# csak a config/installer-generated.php-ba írja (lásd tools/installer-set-
# topology.php docblokkja), a PowerShell-transzkript-napló pedig a Read-Host
# -AsSecureString miatt sose látja a nyers értéket visszhangozva.
$roleLabel = switch ($NodeRole) { 'server' { 'Szerver' } 'client' { 'Kliens' } default { 'Önálló gép' } }
Write-Ok "Node-szerepkör: $roleLabel"

# Szerver szerepkörben a LAN felől érkező közvetlen API-forgalom MINDIG
# hitelesítést igényel (Auth::isEnabled()) — jelszó nélkül a Szerver
# felülete zárva maradna, ezért itt állítjuk be az alkalmazás-jelszót.
# Nincs olyan paraméter, ami ezt kikapcsolná.
if ($NodeRole -eq 'server') {
    $appPasswordToolPath = Join-Path $InstallPath 'tools\installer-set-app-password.php'
    if (-not (Test-Path $appPasswordToolPath)) {
        Exit-WithFailureSummary "Hiányzik a tools\installer-set-app-password.php a telepített FountainTrade-ből." "A letöltött/másolt csomag hiányos vagy régebbi — próbáld újra a telepítést."
    }
    $appPasswordStatus = Invoke-FountainTradeAppPasswordTool -PhpExe $phpExe -ToolPath $appPasswordToolPath -Action 'status'
    if ($appPasswordStatus.ExitCode -ne 0 -or -not $appPasswordStatus.Json -or -not $appPasswordStatus.Json.ok) {
        Exit-WithFailureSummary "Az alkalmazás-jelszó állapota nem olvasható: $($appPasswordStatus.Raw)" "Ellenőrizd a data\settings.json fájlt, majd futtasd újra a telepítőt."
    }
    $appPasswordAction = Resolve-ServerAppPasswordAction -NodeRole 'server' `
        -HasPassword ([bool]$appPasswordStatus.Json.has_password) `
        -EnvPasswordProvided (-not [string]::IsNullOrEmpty($env:FOUNTAINTRADE_APP_PASSWORD)) `
        -NonInteractive ([bool]$env:FOUNTAINTRADE_NONINTERACTIVE)
    $newAppPassword = $null
    switch ($appPasswordAction) {
        'keep' { Write-Ok "Az alkalmazás-jelszó már be van állítva (Szerver mód) — változatlan." }
        'fail' {
            Exit-WithFailureSummary "Szerver módhoz alkalmazás-jelszó szükséges, de nem-interaktív módban nincs megadva." "Add meg a FOUNTAINTRADE_APP_PASSWORD környezeti változóban (legalább 8 karakter), vagy futtasd a telepítőt interaktívan."
        }
        'use_env' { $newAppPassword = $env:FOUNTAINTRADE_APP_PASSWORD }
        'prompt' {
            Write-Host ""
            Write-Host "Szerver módban a helyi hálózatról érkező böngésző-hozzáféréshez alkalmazás-jelszó szükséges (legalább 8 karakter)." -ForegroundColor Yellow
            for ($attempt = 1; $attempt -le 3 -and -not $newAppPassword; $attempt++) {
                $first = Read-Host "Alkalmazás-jelszó" -AsSecureString
                $second = Read-Host "Alkalmazás-jelszó újra" -AsSecureString
                $firstPlain = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($first))
                $secondPlain = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($second))
                if ($firstPlain -ne $secondPlain) {
                    Write-Warn2 "A két jelszó nem egyezik."
                } elseif ($firstPlain.Length -lt 8) {
                    Write-Warn2 "A jelszó legalább 8 karakter legyen."
                } else {
                    $newAppPassword = $firstPlain
                }
            }
            if (-not $newAppPassword) {
                Exit-WithFailureSummary "Nem sikerült érvényes alkalmazás-jelszót megadni." "Futtasd újra a telepítőt."
            }
        }
    }
    if ($newAppPassword) {
        $appPasswordSet = Invoke-FountainTradeAppPasswordTool -PhpExe $phpExe -ToolPath $appPasswordToolPath -Action 'set' -Password $newAppPassword
        $newAppPassword = $null
        if ($appPasswordSet.ExitCode -ne 0 -or -not $appPasswordSet.Json -or -not $appPasswordSet.Json.ok) {
            $appPasswordError = if ($appPasswordSet.Json) { $appPasswordSet.Json.error } else { $appPasswordSet.Raw }
            Exit-WithFailureSummary "Az alkalmazás-jelszó beállítása sikertelen: $appPasswordError" "Ellenőrizd a jelszó hosszát (legalább 8 karakter), majd futtasd újra a telepítőt."
        }
        Write-Ok "Alkalmazás-jelszó beállítva (Szerver mód — a böngészős bejelentkezéshez szükséges)."
    }
}

# -------------------------------------------------------------------
# Ollama (helyi AI) telepítés — Fázis 6, Rész B. KIZÁRÓLAG Önálló gép/
# Szerver szerepkörnél kínálható fel (lásd Resolve-OllamaProvisionDecision
# — Kliens node-on ez STRUKTURÁLISAN ki van zárva, nem csak egy feltétel
# elrejti a menüt). A tényleges letöltés+SHA-256-ellenőrzés+csendes
# futtatás a MEGLÉVŐ OllamaProvisioner.php-ban történik (lásd
# tools/ollama-provision-cli.php), itt csak meghívjuk.
# -------------------------------------------------------------------
$ollamaDecision = Resolve-OllamaProvisionDecision -NodeRole $NodeRole -InstallRequested:$InstallOllama.IsPresent -SkipRequested:$SkipOllama.IsPresent
if ($ollamaDecision.ShouldOffer) {
    Write-Step "Helyi AI (Ollama) — opcionális"
    $ollamaProvisionToolPath = Join-Path $InstallPath 'tools\ollama-provision-cli.php'
    $shouldInstallOllama = $ollamaDecision.ShouldInstall
    if (-not $InstallOllama.IsPresent -and -not $SkipOllama.IsPresent) {
        if ($env:FOUNTAINTRADE_NONINTERACTIVE) {
            Write-Ok "Nem-interaktív mód, -InstallOllama/-SkipOllama nélkül — Ollama NEM települ (add meg valamelyik kapcsolót, ha szükséges)."
            $shouldInstallOllama = $false
        } else {
            Write-Host ""
            Write-Host "Az AI-asszisztens (opcionális) egy helyi Ollama-példányt is használhat" -ForegroundColor Cyan
            Write-Host "(nincs adat internetre küldve). Ez nem kötelező — később, a Beállítások" -ForegroundColor Cyan
            Write-Host "AI fülén is telepíthető/kezelhető." -ForegroundColor Cyan
            $ollamaChoice = Read-Host "Szeretnéd most telepíteni az Ollamát? (i/N)"
            $shouldInstallOllama = $ollamaChoice.Trim().ToLowerInvariant() -in @('i', 'igen', 'y', 'yes')
        }
    }

    if (-not $shouldInstallOllama) {
        Write-Ok "Ollama telepítése kihagyva — a POS/FountainTrade ettől függetlenül teljes körűen használható marad."
    } elseif (-not (Test-Path $ollamaProvisionToolPath)) {
        Write-Warn2 "Hiányzik a tools\ollama-provision-cli.php — az Ollama-telepítés kihagyva."
    } else {
        $ollamaStatus = Invoke-FountainTradeOllamaProvisionTool -PhpExe $phpExe -ToolPath $ollamaProvisionToolPath -Action 'status' -Model $OllamaModel
        if ($ollamaStatus.ExitCode -eq 0 -and (Test-ShouldSkipOllamaInstall -StatusJson $ollamaStatus.Json)) {
            Write-Ok "Az Ollama már telepítve van (verzió: $($ollamaStatus.Json.version)) — telepítés kihagyva."
        } else {
            Write-Host "Ollama telepítése folyamatban (ez eltarthat egy percig — letöltés + ellenőrzés)…" -ForegroundColor DarkGray
            $ollamaInstallResult = Invoke-FountainTradeOllamaProvisionTool -PhpExe $phpExe -ToolPath $ollamaProvisionToolPath -Action 'install'
            if ($ollamaInstallResult.ExitCode -eq 0 -and $ollamaInstallResult.Json.ok) {
                Write-Ok "Ollama sikeresen települt (verzió: $($ollamaInstallResult.Json.version))."
            } else {
                $ollamaError = if ($ollamaInstallResult.Json) { $ollamaInstallResult.Json.error } else { $ollamaInstallResult.Raw }
                if ($OllamaRequired) {
                    Exit-WithFailureSummary "Az Ollama telepítése sikertelen (kötelezőként megjelölve): $ollamaError" "Telepítsd manuálisan az Ollamát (https://ollama.com/download/windows), vagy futtasd újra -OllamaRequired nélkül."
                }
                # A kör 23. pontja explicit követelménye: egy sikertelen Ollama-
                # telepítés NEM buktatja meg a teljes FountainTrade-telepítést —
                # csak világosan jelezzük, a POS ettől függetlenül telepíthető
                # marad.
                Write-Warn2 "Az Ollama telepítése sikertelen: $ollamaError — az AI-asszisztens Ollama nélkül nem lesz elérhető, de a POS teljes körűen működik. Később a Beállítások AI fülén újrapróbálható."
            }
        }
    }
} else {
    Write-Step "Helyi AI (Ollama)"
    Write-Ok "Kliens node — az Ollama itt nem elérhető/nem kínálható fel; az AI-kérések (ha vannak) a Szerveren futnak le."
}

$bindHost = Get-FountainTradeBindHost -NodeRole $NodeRole

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
    # A kötési cím a node-szerepkörtől függ (lásd Get-FountainTradeBindHost,
    # Fázis 2 Checkpoint 3): Önálló/Kliens node ESETÉN localhost/loopback —
    # SOSE minden hálózati interfészre (lásd 11. és 22. pont: "ne nyisson
    # szükségtelen hálózati portot"). Szerver node esetén SZÁNDÉKOSAN
    # 0.0.0.0 — a LAN-on lévő Kliens gépeknek el kell érniük, és egy
    # konkrét, installkor lekért LAN-IP-re kötés a DHCP miatt később
    # érvénytelenné válhatna (lásd a kör 3. pontja). A tényleges hozzáférés-
    # korlátozás LAN-ra a Windows Tűzfal szabálya (lásd lentebb), NEM a
    # bind-cím — a bind-cím önmagában sose helyettesíti a tűzfalat.
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
    New-HiddenLauncherVbs -VbsPath $serverVbsPath -ExePath $phpExe -Arguments "-S ${bindHost}:$Port -t `"$webrootPath`""
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
            $serverTaskDescription = if ($NodeRole -eq 'server') { 'FountainTrade beépített PHP szerver indítása bejelentkezéskor (0.0.0.0 — LAN-ról is elérhető, lásd a Windows Tűzfal szabályát).' } else { 'FountainTrade beépített PHP szerver indítása bejelentkezéskor (csak localhost).' }
            Register-ScheduledTask -TaskName $serverTaskName -Action $serverAction -Trigger $serverTrigger -Settings $serverSettings -Description $serverTaskDescription -ErrorAction Stop | Out-Null
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
        Write-Err2 "A(z) '$serverTaskName' Feladatütemező-bejegyzés létrehozása/frissítése sikertelen: $($_.Exception.Message)" "Ellenőrizd, hogy rendszergazdai jogban fut-e a telepítő, és hogy a Feladatütemező szolgáltatás (Task Scheduler) elérhető-e. Kézi indítás: `"$phpExe`" -S ${bindHost}:$Port -t `"$webrootPath`""
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
# Windows Tűzfal (Fázis 2, Checkpoint 3) — Szerver módban egy bejövő
# szabály engedélyezi a LAN-ról érkező kapcsolódást a $Port-ra; MINDEN más
# módban ez a szabály (ha egy korábbi Szerver-módú telepítésből maradt)
# eltávolításra kerül. A döntési logika (Test-FirewallRuleMatchesExpected)
# admin-jog nélkül is tesztelhető — lásd install-windows-lib.ps1.
# -------------------------------------------------------------------
Write-Step "Windows Tűzfal"

$fwRuleName = 'FountainTrade - Szerver bejövő (LAN)'
if ($NodeRole -eq 'server') {
    try {
        $existingRule = Get-NetFirewallRule -DisplayName $fwRuleName -ErrorAction SilentlyContinue
        $needsCreate = $true
        if ($existingRule) {
            $portFilter = $existingRule | Get-NetFirewallPortFilter
            $addrFilter = $existingRule | Get-NetFirewallAddressFilter
            $ruleCheck = Test-FirewallRuleMatchesExpected -LocalPort $portFilter.LocalPort -Protocol $portFilter.Protocol -RemoteAddress $addrFilter.RemoteAddress -Enabled $existingRule.Enabled -ExpectedPort $Port
            if ($ruleCheck.Ok) {
                Write-Ok "A tűzfalszabály már létezik és helyes — nem módosítva: '$fwRuleName' (TCP $Port, csak LocalSubnet)."
                $needsCreate = $false
            } else {
                Remove-NetFirewallRule -DisplayName $fwRuleName -ErrorAction Stop
                Write-Warn2 "A meglévő tűzfalszabály eltért az elvárttól ($($ruleCheck.Reason)) — újra létrehozva: '$fwRuleName'."
            }
        }
        if ($needsCreate) {
            New-NetFirewallRule -DisplayName $fwRuleName -Direction Inbound -Protocol TCP -LocalPort $Port -RemoteAddress LocalSubnet -Action Allow -Profile Any -ErrorAction Stop | Out-Null
        }
        # Visszaolvasás + tényleges ellenőrzés — egy sikeres New-/nem-
        # dobott hívás MAGÁBAN itt sem elég egy "[OK]"-hoz (lásd a kör 4.
        # pontja: "ha a tűzfal módosítása sikertelen, ne írjon [OK]-t").
        $verifyRule = Get-NetFirewallRule -DisplayName $fwRuleName -ErrorAction Stop
        $verifyPort = $verifyRule | Get-NetFirewallPortFilter
        $verifyAddr = $verifyRule | Get-NetFirewallAddressFilter
        $verifyCheck = Test-FirewallRuleMatchesExpected -LocalPort $verifyPort.LocalPort -Protocol $verifyPort.Protocol -RemoteAddress $verifyAddr.RemoteAddress -Enabled $verifyRule.Enabled -ExpectedPort $Port
        if ($verifyCheck.Ok) {
            Write-Ok "Tűzfalszabály létrehozva/ellenőrizve: '$fwRuleName' (TCP $Port, kizárólag a helyi alhálózatról — internet felől NEM elérhető)."
        } else {
            Write-Err2 "A tűzfalszabály a létrehozás UTÁN, visszaolvasáskor NEM felel meg az elvártnak: $($verifyCheck.Reason)" "Ellenőrizd kézzel a Windows Defender Tűzfalban ('wf.msc'), vagy futtasd újra a telepítőt rendszergazdai jogban."
        }
    } catch {
        Write-Err2 "A tűzfalszabály létrehozása/ellenőrzése sikertelen: $($_.Exception.Message)" "Hozd létre kézzel: Windows Defender Tűzfal -> Bejövő szabályok -> Új szabály -> Port -> TCP $Port -> Csak a helyi alhálózatról (LocalSubnet) -> Engedélyezés."
    }
} else {
    try {
        $staleRule = Get-NetFirewallRule -DisplayName $fwRuleName -ErrorAction SilentlyContinue
        if ($staleRule) {
            Remove-NetFirewallRule -DisplayName $fwRuleName -ErrorAction Stop
            Write-Warn2 "Egy korábbi Szerver-módú tűzfalszabály eltávolítva (a node mostantól '$NodeRole'): '$fwRuleName'."
        } else {
            Write-Ok "Nincs szükség tűzfalszabályra ('$NodeRole' node csak localhost-on hallgat)."
        }
    } catch {
        Write-Warn2 "Egy korábbi tűzfalszabály eltávolítása sikertelen: $($_.Exception.Message) — ellenőrizd/töröld kézzel a Windows Defender Tűzfalban, ha már nincs rá szükség."
    }
}

# A kör 14. pontja: a bind-cím és a tűzfal-konfiguráció EGYMÁSSAL, a
# node-szerepkörrel konzisztensnek kell lennie — mivel mindkettő UGYANABBÓL
# az egyetlen $NodeRole értékből származik (lásd fent Get-FountainTradeBindHost
# és a közvetlenül felette lévő if/else ág), ez szerkezetileg garantált, nem
# csak véletlenül igaz. Az alábbi sor ezt EXPLICIT, ellenőrizhető formában is
# rögzíti a telepítő kimenetében/összegzésében.
$fwStateLabel = if ($NodeRole -eq 'server') { 'létrehozva/ellenőrizve (TCP ' + $Port + ', LocalSubnet)' } else { 'nincs (korábbi eltávolítva, ha volt)' }
Write-Ok "Bind/tűzfal összhang: node='$NodeRole' -> kötés='$bindHost' + tűzfalszabály=$fwStateLabel."

# -------------------------------------------------------------------
# 7. Cron-feladatok (idempotens létrehozás/frissítés)
# -------------------------------------------------------------------
# A hét FountainTrade worker/cron-feladat listája (Fázis 7 óta — korábban
# hat volt, lásd az AI napi intelligencia bejegyzés lentebb) — Szerver/Önálló módban
# EZ mind regisztrálva lesz, Kliens módban EGYIK SEM (lásd lentebb). A
# 'FountainTrade - WooCommerce push queue' bejegyzés a kör 9. pontjának
# javítása: korábban hiányzott ebből a listából, tehát wc-queue-run.php
# SOSE futott le automatikusan egyetlen telepítésen sem, csak kézi
# meghívással — a README saját ajánlása szerinti "percenként vagy néhány
# percenként" gyakorisággal, ugyanúgy, mint a testvér nav-queue-run.php.
$cronJobs = @(
    @{ Name = 'FountainTrade - WooCommerce szinkron'; Endpoint = 'auto-sync-run.php'; Trigger = { New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650) } },
    @{ Name = 'FountainTrade - Biztonsagi mentes'; Endpoint = 'auto-backup-run.php'; Trigger = { New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 15) -RepetitionDuration (New-TimeSpan -Days 3650) } },
    @{ Name = 'FountainTrade - NAV kimeno queue'; Endpoint = 'nav-queue-run.php'; Trigger = { New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650) } },
    @{ Name = 'FountainTrade - NAV bejovo szinkron'; Endpoint = 'nav-incoming-sync-run.php'; Trigger = { New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Hours 1) -RepetitionDuration (New-TimeSpan -Days 3650) } },
    @{ Name = 'FountainTrade - Frissites ellenorzes'; Endpoint = 'update-check-run.php'; Trigger = { New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 30) -RepetitionDuration (New-TimeSpan -Days 3650) } },
    @{ Name = 'FountainTrade - WooCommerce push queue'; Endpoint = 'wc-queue-run.php'; Trigger = { New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 1) -RepetitionDuration (New-TimeSpan -Days 3650) } },
    # Fázis 7 — AI Daily Intelligence. UGYANAZ a "gyakori poll, a végpont
    # saját maga dönti el, esedékes-e" minta, mint update-check-run.php
    # (lásd webroot/api/ai-daily-intelligence-run.php) — nem egy külön,
    # naponta egyszer futó Feladatütemező-trigger, hogy egy kihagyott/
    # elbukott ablak ne jelentsen egy egész napos csúszást.
    @{ Name = 'FountainTrade - AI napi intelligencia'; Endpoint = 'ai-daily-intelligence-run.php'; Trigger = { New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 30) -RepetitionDuration (New-TimeSpan -Days 3650) } }
)

if ($NodeRole -eq 'client') {
    if (-not $SkipScheduledTasks) {
        Write-Step "Automatikus háttérfeladatok (cron) — Kliens módban nincs egyik sem regisztrálva"
        # A kör 8. pontjának explicit követelménye: ez NEM egy futásidejű
        # figyelmeztetés, hanem SZERKEZETI biztosíték — egy Kliens node-nak
        # nincs saját adatbázisa, amin bármelyik worker dolgozhatna. Ha ez a
        # gép KORÁBBAN Szerver módban volt, az akkor létrejött worker-
        # feladatokat itt AKTÍVAN eltávolítjuk (nem csak kihagyjuk az
        # újralétrehozásukat) — lásd a kör 16. pontja: "ne maradjanak régi
        # worker taskok" egy szerepkör-váltás után.
        $anyRemoved = $false
        foreach ($job in $cronJobs) {
            try {
                $existing = Get-ScheduledTask -TaskName $job.Name -ErrorAction SilentlyContinue
                if ($existing) {
                    Unregister-ScheduledTask -TaskName $job.Name -Confirm:$false -ErrorAction Stop
                    Write-Warn2 "Korábbi worker-feladat eltávolítva (Kliens node nem futtathat workert): '$($job.Name)'."
                    $anyRemoved = $true
                }
            } catch {
                Write-Err2 "A(z) '$($job.Name)' korábbi worker-feladat eltávolítása sikertelen: $($_.Exception.Message)" "Töröld kézzel a Feladatütemezőben ('taskschd.msc'), vagy futtasd újra a telepítőt rendszergazdai jogban."
            }
        }
        if (-not $anyRemoved) {
            Write-Ok "Nincs eltávolítandó régi worker-feladat — a node eddig is Kliens (vagy nem volt még beállítva)."
        }
        Write-Ok "Kliens node — egyik worker/cron-feladat sincs (és nem is lesz) regisztrálva; ez szerkezeti biztosíték, nem futásidejű döntés."
    }
} elseif (-not $SkipScheduledTasks -and $CronToken) {
    Write-Step "Automatikus háttérfeladatok (cron) beállítása"

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

    # A parancsikon ikonja — VALÓDI .ico fájl, NEM .svg (élő hiba javítva:
    # a Windows Shell/.lnk-formátum SOSE támogatott SVG-t IconLocation-ként
    # — ez korábban üres/alapértelmezett ikont eredményezett volna). Ugyanaz
    # a "Kassza" motívum (Feather "shopping-cart"), amit a FountainTrade UI
    # saját oldalsávja is használ — lásd webroot/sidebarmenu.php és
    # tools/generate-kassza-icon.ps1.
    #
    # ELSŐDLEGES FORRÁS: a webroot/assets alatt — ez MINDEN telepítéssel
    # (GitHub Release-ből VAGY meglévő mappából) automatikusan megérkezik,
    # mert a repó/release RÉSZE.
    #
    # TARTALÉK FORRÁS: a telepítő-csomag SAJÁT, mellékelt példánya (a
    # FountainTrade-Installer\fountaintrade-kassa.ico, közvetlenül a
    # szkript mellett) — arra az esetre, ha a letöltött GitHub Release
    # RÉGEBBI, mint az ikon bevezetése (tehát a kicsomagolt appban még
    # nincs .ico). Ilyenkor a telepítő-csomag saját példányát BEMÁSOLJUK a
    # frissen telepített app-ba — attól kezdve az már az alkalmazás
    # normál, önálló része, nem függ többé a telepítő-csomagtól.
    $kasszaIconPath = Join-Path $webrootPath 'assets\fountaintrade-kassa.ico'
    if (-not (Test-Path $kasszaIconPath)) {
        $bundledIconPath = Join-Path $PSScriptRoot 'fountaintrade-kassa.ico'
        if (Test-Path $bundledIconPath) {
            try {
                Copy-Item -LiteralPath $bundledIconPath -Destination $kasszaIconPath -Force
                Write-Ok "A kassza-ikon a telepítő-csomag saját példányából pótolva (a letöltött verzió még nem tartalmazta)."
            } catch {
                Write-Warn2 "A kassza-ikon pótlása sikertelen: $($_.Exception.Message)"
            }
        }
    }
    $expectedIconLocation = if (Test-Path $kasszaIconPath) { "$kasszaIconPath,0" } else { $null }
    if (-not $expectedIconLocation) {
        Write-Warn2 "A fountaintrade-kassa.ico nem található ($kasszaIconPath) — a parancsikon Windows alapértelmezett ikonnal jön létre."
    }

    function New-FountainTradeShortcut {
        param([string]$LinkPath, [string]$ExpectedTarget, [string]$ExpectedArguments, [string]$ExpectedIconLocation, [string]$ExpectedWorkingDirectory)

        $shell = New-Object -ComObject WScript.Shell

        if (Test-Path $LinkPath) {
            # Élő teszteléssel felfedezett hiányosság javítása: korábban egy
            # MÁR LÉTEZŐ parancsikon sose lett újraellenőrizve/frissítve —
            # egy port-váltással újrafuttatott telepítő emiatt egy ELAVULT
            # (régi portra mutató) parancsikont hagyott volna hátra. Most
            # visszaolvassuk és ÖSSZEHASONLÍTJUK a ténylegesen elvárt
            # célponttal ÉS ikonnal — csak akkor írunk, ha valóban eltér.
            $existing = $shell.CreateShortcut($LinkPath)
            $iconMatches = (-not $ExpectedIconLocation) -or ($existing.IconLocation -eq $ExpectedIconLocation)
            if ($existing.TargetPath -eq $ExpectedTarget -and $existing.Arguments -eq $ExpectedArguments -and $iconMatches) {
                Write-Ok "Parancsikon már létezik és helyes — nem módosítva: $LinkPath"
                return
            }
            Write-Warn2 "A meglévő parancsikon elavult célpontra/ikonra mutatott — frissítve: $LinkPath"
        }

        $shortcut = $shell.CreateShortcut($LinkPath)
        $shortcut.TargetPath = $ExpectedTarget
        $shortcut.Arguments = $ExpectedArguments
        $shortcut.WorkingDirectory = $ExpectedWorkingDirectory
        if ($ExpectedIconLocation) {
            $shortcut.IconLocation = $ExpectedIconLocation
        }
        $shortcut.Save()

        # Visszaolvasás + tényleges ellenőrzés (lásd a kör 5. pontja: Target/
        # Arguments/WorkingDirectory/IconLocation mind ellenőrizve, nem csak
        # fájllétezés).
        $verify = $shell.CreateShortcut($LinkPath)
        $verifyIconOk = (-not $ExpectedIconLocation) -or ($verify.IconLocation -eq $ExpectedIconLocation)
        if ($verify.TargetPath -eq $ExpectedTarget -and $verify.Arguments -eq $ExpectedArguments -and $verifyIconOk) {
            Write-Ok "Parancsikon létrehozva/frissítve és visszaolvasással ellenőrizve (Target+Arguments+Icon): $LinkPath"
        } else {
            Write-Err2 "A parancsikon mentés után, visszaolvasáskor NEM a várt célpontra/ikonra mutat: $LinkPath" "Hozd létre kézzel: cél = $ExpectedTarget, ikon = $ExpectedIconLocation"
        }
    }

    $desktopPath = Join-Path ([Environment]::GetFolderPath('Desktop')) 'FountainTrade.lnk'
    New-FountainTradeShortcut -LinkPath $desktopPath -ExpectedTarget $expectedTarget -ExpectedArguments $expectedArguments -ExpectedIconLocation $expectedIconLocation -ExpectedWorkingDirectory $InstallPath

    $startMenuDir = [Environment]::GetFolderPath('StartMenu') + '\Programs'
    if (Test-Path $startMenuDir) {
        $startMenuPath = Join-Path $startMenuDir 'FountainTrade.lnk'
        New-FountainTradeShortcut -LinkPath $startMenuPath -ExpectedTarget $expectedTarget -ExpectedArguments $expectedArguments -ExpectedIconLocation $expectedIconLocation -ExpectedWorkingDirectory $InstallPath
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
    Exit-WithFailureSummary "A szerver nem válaszol 10 másodperc után sem (http://localhost:$Port/)." "Indítsd el kézzel: `"$phpExe`" -S ${bindHost}:$Port -t `"$webrootPath`", és ellenőrizd a hibaüzenetet."
}
Write-Ok "A szerver válaszol — http://localhost:$Port/"

# -------------------------------------------------------------------
# Kliens kapcsolat-ellenőrzés (Fázis 2, Checkpoint 3, 7. pont) — a fenti
# ellenőrzés csak azt bizonyítja, hogy a Kliens SAJÁT, helyi PHP szervere
# fut (a "/" egy statikus oldalsablon, NEM megy át a ClientProxy-n). Ez itt
# KÉT TOVÁBBI, Kliens-specifikus réteget bizonyít: (1) a konfigurált
# távoli Szerver ténylegesen elérhető-e a hálózaton, ÉS (2) a Kliens saját
# HMAC-hitelesítő adatai ténylegesen elfogadhatók-e a Szerveren keresztül
# — ez utóbbi a valódi ClientProxy-láncon FUT ÁT (localhost -> _bootstrap.php
# -> ClientProxy -> HMAC -> távoli Szerver -> vissza), tehát a teljes
# kódútvonalat bizonyítja, nem csak a nyers hálózati elérhetőséget. Ha
# BÁRMELYIK réteg hibázik, a telepítés NEM tekinthető sikeresnek (lásd a
# kör 7. pontja) — Write-Err2-vel jelezve (a végső összegzés emiatt
# "NEM fejeződött be sikeresen"-t fog mutatni), de a szkript hátralévő
# lépései (parancsikonok stb.) még lefutnak, hogy a felhasználónak legyen
# mit javítania/újrapróbálnia.
if ($NodeRole -eq 'client') {
    Write-Step "Kliens kapcsolat-ellenőrzés (távoli Szerver)"

    $remoteReachable = $false
    try {
        $remoteInstallStatus = Invoke-RestMethod -Uri ("$clientServerUrl".TrimEnd('/') + '/api/install-status.php') -TimeoutSec 8 -ErrorAction Stop
        $remoteReachable = $true
        Write-Ok "A konfigurált Szerver elérhető a hálózaton: $clientServerUrl"
    } catch {
        Write-Err2 "A konfigurált Szerver NEM érhető el: $clientServerUrl ($($_.Exception.Message))" "Ellenőrizd, hogy a Szerver gép fut-e, a -ServerUrl helyes-e, és hogy a Szerver Windows Tűzfala engedélyezi-e ezt a LAN-alhálózatot."
    }

    if ($remoteReachable) {
        $authProxyOk = $false
        try {
            $proxied = Invoke-RestMethod -Uri "http://localhost:$Port/api/auth-status.php" -TimeoutSec 8 -ErrorAction Stop
            # Egy sikeres HTTP 200 (kivétel nélkül) MÁR önmagában bizonyítja,
            # hogy a HMAC-aláírás elfogadásra került a Szerveren — egy
            # érvénytelen client_id/secret esetén a Szerver 401-et adna,
            # amit Invoke-RestMethod kivételként dobna.
            $authProxyOk = $true
            Write-Ok "A Kliens saját HMAC-hitelesítő adatai elfogadva a Szerveren (a ClientProxy-láncon keresztül ellenőrizve)."
        } catch {
            Write-Err2 "A Kliens HMAC-hitelesítése sikertelen a Szerveren keresztül: $($_.Exception.Message)" "Ellenőrizd, hogy a -ClientId/-ClientSecret helyes és a Szerveren MÉG AKTÍV (nem letiltott/visszavont) — lásd a Szerveren a Kliensek oldalt."
        }
        if (-not $authProxyOk) {
            Write-Err2 "A Kliens telepítése emiatt NEM tekinthető sikeresnek — a helyi szerver fut, de a Szerverhez való hitelesített kapcsolat nem működik." ''
        }
    } else {
        Write-Err2 "A Kliens telepítése emiatt NEM tekinthető sikeresnek — a konfigurált Szerver nem érhető el." ''
    }
}

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
