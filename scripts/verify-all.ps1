# FountainTrade – helyi "minden zöld?" ellenőrzés
#
# Ugyanazokat a kapukat futtatja, mint a CI (PHP szintaxis, teljes PHPUnit,
# adatvédelmi őr), Windows fejlesztői gépen. A Pester telepítő-teszteket
# csak akkor futtatja, ha a Pester modul elérhető.
#
# Használat:
#   powershell -ExecutionPolicy Bypass -File scripts/verify-all.ps1
#   powershell -ExecutionPolicy Bypass -File scripts/verify-all.ps1 -SkipTests
#
# Kilépési kód: 0 = minden zöld, 1 = hiba.

[CmdletBinding()]
param(
    [string]$PhpPath = '',
    [switch]$SkipTests,
    [switch]$SkipPester
)

$ErrorActionPreference = 'Stop'

$repoRoot = Split-Path -Parent $PSScriptRoot
Set-Location $repoRoot

$script:Failures = 0

function Write-Step {
    param([string]$Text)
    Write-Host ''
    Write-Host "=== $Text ===" -ForegroundColor Cyan
}

function Fail {
    param([string]$Text)
    Write-Host "HIBA: $Text" -ForegroundColor Red
    $script:Failures++
}

# --- PHP megkeresése ------------------------------------------------------
if (-not $PhpPath) {
    foreach ($candidate in @(
            'C:\tools\php83\php.exe',
            'C:\laragon\bin\php\php-8.3.33-Win32-vs16-x64\php.exe'
        )) {
        if (Test-Path -LiteralPath $candidate) {
            $PhpPath = $candidate
            break
        }
    }
}

if (-not $PhpPath) {
    $command = Get-Command php -ErrorAction SilentlyContinue
    if ($command) { $PhpPath = $command.Source }
}

if (-not $PhpPath) {
    Fail 'Nem található PHP. Add meg a -PhpPath paraméterrel (pl. -PhpPath C:\tools\php83\php.exe).'
    exit 1
}

Write-Host "PHP: $PhpPath"
& $PhpPath -r "echo PHP_VERSION, PHP_EOL;" | Out-Null

# A projekt tesztjeihez szükséges kiterjesztések (a minimálisan telepített
# PHP – pl. a winget-es csomag – ezek nélkül nem tudja futtatni a suite-ot).
$requiredExtensions = @('mbstring', 'pdo_sqlite', 'sqlite3', 'dom', 'xmlwriter', 'tokenizer', 'ctype', 'fileinfo', 'gd')
$loaded = & $PhpPath -m
$missing = @()
foreach ($extension in $requiredExtensions) {
    if (-not ($loaded -match ("(?im)^" + [regex]::Escape($extension) + "$"))) {
        $missing += $extension
    }
}
if ($missing.Count -gt 0) {
    Fail ("A PHP-ből hiányzik: " + ($missing -join ', ') + ". Használj teljes PHP 8.3 telepítést (pl. C:\tools\php83).")
    exit 1
}

# --- 1. PHP szintaxis -----------------------------------------------------
Write-Step 'PHP szintaxis (git által követett fájlok)'
$phpFiles = @(git ls-files '*.php')
if ($phpFiles.Count -eq 0) {
    Fail 'Nem található követett PHP fájl.'
}
else {
    $syntaxFailures = 0
    foreach ($file in $phpFiles) {
        $output = & $PhpPath -l $file 2>&1
        if ($LASTEXITCODE -ne 0) {
            $syntaxFailures++
            Write-Host $output -ForegroundColor Red
            Write-Host "  -> $file" -ForegroundColor Red
        }
    }
    if ($syntaxFailures -gt 0) {
        Fail "$syntaxFailures PHP fájlban szintaxis-hiba van."
    }
    else {
        Write-Host "OK: $($phpFiles.Count) PHP fájl."
    }
}

# --- 2. PHPUnit -----------------------------------------------------------
if (-not $SkipTests) {
    Write-Step 'PHPUnit (teljes teszt-készlet)'

    $phpunitVersion = '10.5.66'
    $phpunitSha256 = '42bcac97bbf9fb1aecf5a7d6a1b37123a11e7e295458978f0e268999f6d9e50f'
    $phpunitPath = Join-Path $repoRoot 'tools\phpunit.phar'

    $needDownload = $true
    if (Test-Path -LiteralPath $phpunitPath) {
        $actual = (Get-FileHash -LiteralPath $phpunitPath -Algorithm SHA256).Hash.ToLowerInvariant()
        $needDownload = ($actual -ne $phpunitSha256)
    }

    if ($needDownload) {
        Write-Host "PHPUnit $phpunitVersion letöltése..."
        New-Item -ItemType Directory -Force -Path (Split-Path -Parent $phpunitPath) | Out-Null
        $tmp = "$phpunitPath.tmp"
        try {
            [Net.ServicePointManager]::SecurityProtocol = [Net.SecurityProtocolType]::Tls12
            Invoke-WebRequest -UseBasicParsing -Uri "https://phar.phpunit.de/phpunit-$phpunitVersion.phar" -OutFile $tmp
            $actual = (Get-FileHash -LiteralPath $tmp -Algorithm SHA256).Hash.ToLowerInvariant()
            if ($actual -ne $phpunitSha256) {
                Remove-Item -LiteralPath $tmp -Force -ErrorAction SilentlyContinue
                Fail 'A letöltött PHPUnit ellenőrzőösszege nem egyezik.'
            }
            else {
                Move-Item -LiteralPath $tmp -Destination $phpunitPath -Force
            }
        }
        catch {
            Remove-Item -LiteralPath $tmp -Force -ErrorAction SilentlyContinue
            Fail "PHPUnit letöltés sikertelen: $($_.Exception.Message)"
        }
    }

    if (Test-Path -LiteralPath $phpunitPath) {
        Write-Host "PHPUnit futtatása (ez több percig is eltarthat)..."
        & $PhpPath $phpunitPath '--colors=never'
        if ($LASTEXITCODE -ne 0) {
            Fail "A PHPUnit nem zöld (kilépési kód: $LASTEXITCODE)."
        }
    }
}

# --- 3. Adatvédelmi őr ----------------------------------------------------
Write-Step 'Adatvédelmi őr (data/ és invoices/)'
$dataStatus = @(git status --porcelain=v1 --ignored=matching --untracked-files=all -- data invoices)
if ($dataStatus.Count -gt 0) {
    Write-Host ($dataStatus -join [Environment]::NewLine) -ForegroundColor Red
    Fail 'A teszt-ellenőrzés fájlt hozott létre vagy módosított a data/ vagy invoices/ mappában.'
}
else {
    Write-Host 'OK: a data/ és invoices/ mappa érintetlen.'
}

# --- 4. Pester (opcionális) ----------------------------------------------
if (-not $SkipPester) {
    Write-Step 'Pester (Windows telepítő tesztek)'
    $pester = Get-Command Invoke-Pester -ErrorAction SilentlyContinue
    if (-not $pester) {
        Write-Host 'Pester nem érhető el – kihagyva (a CI külön, Windows runneren futtatja).' -ForegroundColor Yellow
    }
    else {
        $testFile = Join-Path $repoRoot 'tests\Install-WindowsTests.ps1'
        $result = Invoke-Pester -Script $testFile -PassThru
        if ($result.FailedCount -gt 0) {
            Fail "$($result.FailedCount) Pester teszt elbukott."
        }
        else {
            Write-Host "OK: $($result.PassedCount) Pester teszt."
        }
    }
}

# --- Összegzés ------------------------------------------------------------
Write-Step 'Összegzés'
if ($script:Failures -gt 0) {
    Write-Host "$($script:Failures) ellenőrzés elbukott." -ForegroundColor Red
    exit 1
}

Write-Host 'Minden helyi ellenőrzés zöld.' -ForegroundColor Green
exit 0
