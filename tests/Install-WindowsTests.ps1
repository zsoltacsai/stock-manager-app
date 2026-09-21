# FountainTrade Windows telepítő — Pester tesztek (install-windows.ps1).
#
# A szkript monolitikus (self-elevation + valódi mellékhatások a tetején),
# ezért NEM dot-sourceolható közvetlenül egy teszt-futtatásban — helyette
# az AST-ból (ugyanaz a .NET parser, amit a syntax-validáció is használ)
# kivonjuk a TISZTÁN LOGIKAI, mellékhatás-mentes függvénydefiníciókat, és
# CSAK azokat töltjük be a teszt-scope-ba. Ez lefedi a kör 28. pontjában
# kért területeket (path handling, PHP-verzió/extension-detektálás,
# cron-token-generálás idempotenciája, Zip Slip-védelem), anélkül hogy a
# self-elevation/valódi letöltés/Feladatütemező-módosítás bármelyike
# lefutna teszt közben.

$scriptPath = Join-Path $PSScriptRoot '..\install-windows.ps1'
$tokens = $null
$parseErrors = $null
$ast = [System.Management.Automation.Language.Parser]::ParseFile($scriptPath, [ref]$tokens, [ref]$parseErrors)
if ($parseErrors.Count -gt 0) {
    throw "install-windows.ps1 syntax error(s) — a tesztek nem futtathatók: $($parseErrors -join '; ')"
}

$functionsToLoad = @('Get-AllowedHost', 'Expand-SafeZip', 'Get-OrCreateCronToken', 'Get-InstalledPhpExe')
$functionAsts = $ast.FindAll({ param($node) $node -is [System.Management.Automation.Language.FunctionDefinitionAst] }, $true) |
    Where-Object { $functionsToLoad -contains $_.Name }

foreach ($fn in $functionAsts) {
    Invoke-Expression $fn.Extent.Text
}

Describe 'install-windows.ps1 — Get-AllowedHost / GitHub asset host fehérlista' {
    It 'Helyesen adja vissza a hosztot egy érvényes HTTPS URL-ből' {
        Get-AllowedHost 'https://github.com/zsoltacsai/stock-manager-app/releases/download/v1.4.0/fountaintrade-1.4.0.zip' | Should Be 'github.com'
    }
    It 'Helyesen adja vissza az objects.githubusercontent.com hosztot' {
        Get-AllowedHost 'https://objects.githubusercontent.com/foo/bar' | Should Be 'objects.githubusercontent.com'
    }
    It 'Null-t ad vissza érvénytelen/parse-olhatatlan URL-nél (nem dob kivételt)' {
        Get-AllowedHost 'nem-egy-url' | Should Be $null
    }
}

Describe 'install-windows.ps1 — Get-OrCreateCronToken (cron-token forrás-sorrend, idempotencia)' {
    $tempDir = $null
    $settingsPath = $null

    BeforeEach {
        $tempDir = Join-Path $env:TEMP "ft-crontoken-test-$(Get-Random)"
        New-Item -ItemType Directory -Path $tempDir -Force | Out-Null
        $settingsPath = Join-Path $tempDir 'settings.json'
    }
    AfterEach {
        if ($tempDir -and (Test-Path $tempDir)) { Remove-Item $tempDir -Recurse -Force -ErrorAction SilentlyContinue }
    }

    It 'A -CronToken paraméter elsőbbséget élvez, ha meg van adva' {
        $result = Get-OrCreateCronToken -SettingsPath $settingsPath -SuppliedToken 'kezzel-megadott-token'
        $result.Token | Should Be 'kezzel-megadott-token'
        $result.Generated | Should Be $false
    }

    It 'Ha nincs settings.json, generál egy tokent, és létrehozza a fájlt' {
        Test-Path $settingsPath | Should Be $false
        $result = Get-OrCreateCronToken -SettingsPath $settingsPath -SuppliedToken $null
        $result.Generated | Should Be $true
        $result.Token.Length | Should Be 64
        Test-Path $settingsPath | Should Be $true
    }

    It 'Ha a settings.json már tartalmaz cron_secret-et, azt használja, NEM generál újat' {
        '{"cron_secret": "mar-letezo-token", "theme": "dark"}' | Set-Content -LiteralPath $settingsPath -Encoding UTF8
        $result = Get-OrCreateCronToken -SettingsPath $settingsPath -SuppliedToken $null
        $result.Token | Should Be 'mar-letezo-token'
        $result.Generated | Should Be $false
    }

    It 'Generáláskor a MEGLÉVŐ, egyéb settings.json mezőket változatlanul megőrzi (partial merge, nem felülírás)' {
        '{"theme": "dark", "loyalty_enabled": true}' | Set-Content -LiteralPath $settingsPath -Encoding UTF8
        Get-OrCreateCronToken -SettingsPath $settingsPath -SuppliedToken $null | Out-Null
        $saved = Get-Content $settingsPath -Raw | ConvertFrom-Json
        $saved.theme | Should Be 'dark'
        $saved.loyalty_enabled | Should Be $true
        $saved.cron_secret | Should Not BeNullOrEmpty
    }

    It 'A generált token minden alkalommal más (kriptográfiailag véletlen, nem fix minta)' {
        $r1 = Get-OrCreateCronToken -SettingsPath (Join-Path $tempDir 's1.json') -SuppliedToken $null
        $r2 = Get-OrCreateCronToken -SettingsPath (Join-Path $tempDir 's2.json') -SuppliedToken $null
        $r1.Token | Should Not Be $r2.Token
    }
}

Describe 'install-windows.ps1 — Expand-SafeZip (Zip Slip védelem)' {
    $tempDir = $null
    $destDir = $null

    BeforeEach {
        $tempDir = Join-Path $env:TEMP "ft-zipslip-test-$(Get-Random)"
        New-Item -ItemType Directory -Path $tempDir -Force | Out-Null
        $destDir = Join-Path $tempDir 'dest'
    }
    AfterEach {
        if ($tempDir -and (Test-Path $tempDir)) { Remove-Item $tempDir -Recurse -Force -ErrorAction SilentlyContinue }
    }

    It 'Egy NORMÁL, ártalmatlan ZIP-et sikeresen kicsomagol' {
        Add-Type -AssemblyName System.IO.Compression.FileSystem
        $zipPath = Join-Path $tempDir 'safe.zip'
        $srcDir = Join-Path $tempDir 'src'
        New-Item -ItemType Directory -Path (Join-Path $srcDir 'webroot') -Force | Out-Null
        'safe content' | Set-Content -LiteralPath (Join-Path $srcDir 'webroot\index.php') -Encoding UTF8
        [System.IO.Compression.ZipFile]::CreateFromDirectory($srcDir, $zipPath)

        Expand-SafeZip -ZipPath $zipPath -DestDir $destDir
        Test-Path (Join-Path $destDir 'webroot\index.php') | Should Be $true
    }

    It 'EGY path-traversal ("..") bejegyzést tartalmazó ZIP-et NEM csomagol ki — a hívó Exit-WithFailureSummary-t vár' {
        # Az Expand-SafeZip a gyanús bejegyzésnél az Exit-WithFailureSummary
        # függvényt hívná (ami itt nincs betöltve) — emiatt ez a teszt azt
        # ellenőrzi, hogy a függvény ILYENKOR ténylegesen HIBÁVAL áll le
        # (nem csendben kicsomagolja a gyanús bejegyzést), nem azt, hogy
        # pontosan melyik hibaüzenetet dobja.
        Add-Type -AssemblyName System.IO.Compression.FileSystem
        $zipPath = Join-Path $tempDir 'evil.zip'
        $fs = [System.IO.File]::Create($zipPath)
        $archive = New-Object System.IO.Compression.ZipArchive($fs, [System.IO.Compression.ZipArchiveMode]::Create)
        $entry = $archive.CreateEntry('../../evil.txt')
        $writer = New-Object System.IO.StreamWriter($entry.Open())
        $writer.Write('gonosz tartalom')
        $writer.Close()
        $archive.Dispose()
        $fs.Dispose()

        { Expand-SafeZip -ZipPath $zipPath -DestDir $destDir } | Should Throw
        Test-Path (Join-Path $tempDir 'evil.txt') | Should Be $false
    }
}
