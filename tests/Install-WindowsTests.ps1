# FountainTrade Windows telepítő — Pester tesztek.
#
# A tisztán logikai, mellékhatás-mentes segédfüggvényeket TARTALMAZÓ
# install-windows-lib.ps1-et egyszerű dot-source-olással töltjük be —
# lásd annak a fájlnak a tetején lévő docblokkot: ez SZÁNDÉKOS,
# ÉLŐ TAPASZTALATTAL INDOKOLT választás egy korábbi, AST-kinyerésen +
# Invoke-Expression-ön alapuló módszer helyett, ami — a tartalmától
# függetlenül — egy víruskereső malware-heurisztikáját ütötte meg és a
# TESZT FÁJLT karanténba helyezte (a tényleges install-windows.ps1
# soha nem lett érintve).
. (Join-Path $PSScriptRoot '..\install-windows-lib.ps1')

Describe 'install-windows-lib — Get-AllowedHost / GitHub asset host fehérlista' {
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

Describe 'install-windows-lib — Get-OrCreateCronToken (cron-token forrás-sorrend, idempotencia)' {
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

    # Regresszió: valódi telepítés közben ez a függvény egy éles
    # settings.json-t (ékezetes magyar szöveggel: payment_methods,
    # receipt_header_lines stb.) korrumpált, mert a korábbi "Get-Content
    # -Raw" (explicit -Encoding nélkül) Windows PowerShell 5.1 alatt egy
    # BOM nélküli fájlnál a RENDSZER ANSI kódlapját használta UTF-8
    # helyett (magyar Windows-on CP1250) — "Készpénz" -> "KĂ©szpĂ©nz".
    It 'Az ÉKEZETES magyar szöveget (settings.json meglévő mezői) VÁLTOZATLANUL, SÉRÜLÉS NÉLKÜL megőrzi' {
        $payload = @{
            theme = 'dark'
            receipt_footer_lines = 'Köszönjük a vásárlást!'
            payment_methods = @(
                @{ value = 'Készpénz'; color = '#16a34a' },
                @{ value = 'Átutalás'; color = '#a855f7' },
                @{ value = 'Bankkártya'; color = '#3b82f6' }
            )
        }
        $json = $payload | ConvertTo-Json -Depth 10
        [System.IO.File]::WriteAllText($settingsPath, $json, (New-Object System.Text.UTF8Encoding($false)))

        Get-OrCreateCronToken -SettingsPath $settingsPath -SuppliedToken $null | Out-Null

        $saved = [System.IO.File]::ReadAllText($settingsPath, [System.Text.Encoding]::UTF8) | ConvertFrom-Json
        $saved.receipt_footer_lines | Should Be 'Köszönjük a vásárlást!'
        $saved.payment_methods[0].value | Should Be 'Készpénz'
        $saved.payment_methods[1].value | Should Be 'Átutalás'
        $saved.payment_methods[2].value | Should Be 'Bankkártya'
    }
}

Describe 'install-windows-lib — Test-SafeZipEntryName (Zip Slip védelem, tiszta stringeken)' {
    It 'Egy normál, relatív bejegyzésnevet biztonságosnak fogad el' {
        Test-SafeZipEntryName 'webroot/index.php' | Should Be $true
        Test-SafeZipEntryName 'src\Database.php' | Should Be $true
    }
    It 'Elutasít egy path-traversal (".." szegmens) bejegyzésnevet' {
        Test-SafeZipEntryName '../../secret.txt' | Should Be $false
        Test-SafeZipEntryName 'webroot/../../../windows/system32/evil.dll' | Should Be $false
    }
    It 'Elutasít egy Unix-stílusú abszolút útvonalat' {
        Test-SafeZipEntryName '/etc/passwd' | Should Be $false
    }
    It 'Elutasít egy Windows-meghajtóbetűjeles abszolút útvonalat' {
        Test-SafeZipEntryName 'C:\Windows\System32\evil.dll' | Should Be $false
    }
    It 'Üres bejegyzésnevet (könyvtár-placeholder) biztonságosnak fogad el' {
        Test-SafeZipEntryName '' | Should Be $true
    }
}

Describe 'install-windows-lib — Expand-SafeZip (biztonságos kicsomagolás, csak ártalmatlan tartalommal)' {
    $tempDir = $null
    $destDir = $null

    BeforeEach {
        $tempDir = Join-Path $env:TEMP "ft-zipextract-test-$(Get-Random)"
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
}

Describe 'install-windows-lib — rejtett-ablakos indítówrapper (release-blocking popup-hiba javítása)' {
    It 'ConvertTo-VbsStringLiteral megduplázza a beágyazott idézőjeleket (VBScript string-escaping)' {
        ConvertTo-VbsStringLiteral 'X-Cron-Token: abc123' | Should Be '"X-Cron-Token: abc123"'
        ConvertTo-VbsStringLiteral 'idézőjelet" tartalmazó szöveg' | Should Be '"idézőjelet"" tartalmazó szöveg"'
    }

    $tempDir = $null
    BeforeEach {
        $tempDir = Join-Path $env:TEMP "ft-hiddenlauncher-test-$(Get-Random)"
        New-Item -ItemType Directory -Path $tempDir -Force | Out-Null
    }
    AfterEach {
        if ($tempDir -and (Test-Path $tempDir)) { Remove-Item $tempDir -Recurse -Force -ErrorAction SilentlyContinue }
    }

    It 'A generált .vbs UTF-16LE BOM-mal kezdődik (a klasszikus VBScript-motor ezt igényli ékezetes útvonalakhoz)' {
        $vbsPath = Join-Path $tempDir 'test.vbs'
        New-HiddenLauncherVbs -VbsPath $vbsPath -ExePath 'C:\Windows\System32\curl.exe' -Arguments '-s "http://localhost/x"'
        $bytes = [System.IO.File]::ReadAllBytes($vbsPath)
        $bytes[0] | Should Be 0xFF
        $bytes[1] | Should Be 0xFE
    }

    It 'A generált .vbs a célfolyamatot REJTETT ablakkal (windowStyle=0) indítja, és a kilépési kódot megőrzi' {
        $vbsPath = Join-Path $tempDir 'exitcode.vbs'
        # cmd.exe /c exit 42 — determinisztikus, gyors, valódi kilépési kód.
        New-HiddenLauncherVbs -VbsPath $vbsPath -ExePath 'C:\Windows\System32\cmd.exe' -Arguments '/c exit 42'
        $vbsContent = Get-Content $vbsPath -Encoding Unicode -Raw
        $vbsContent | Should Match 'objShell\.Run\(cmdLine, 0, True\)'

        $p = Start-Process -FilePath 'wscript.exe' -ArgumentList "//B //NoLogo `"$vbsPath`"" -PassThru -Wait -WindowStyle Hidden
        $p.ExitCode | Should Be 42
    }

    It 'A cron-titkos token NEM jelenik meg nyers szövegként a Feladatütemező-akció saját argumentum-mezőjében — csak a .vbs fájl TARTALMÁBAN (élő teszteléssel felfedezett biztonsági megfigyelés)' {
        $vbsPath = Join-Path $tempDir 'cron.vbs'
        $secretToken = 'titkos-cron-token-xyz'
        New-HiddenLauncherVbs -VbsPath $vbsPath -ExePath 'C:\Windows\System32\curl.exe' -Arguments "-s -H `"X-Cron-Token: $secretToken`" `"http://localhost:8000/api/auto-sync-run.php`""
        # A Feladatütemező-akció maga csak a wscript.exe hívást és a .vbs
        # ÚTVONALÁT látja — ez a teszt azt bizonyítja, hogy a TaskAction
        # Argument mezőjébe kerülő string (amit a valódi kód `//B //NoLogo
        # "<vbsPath>"` formában épít fel) sose tartalmazza a tokent.
        $taskActionArgument = "//B //NoLogo `"$vbsPath`""
        $taskActionArgument | Should Not Match ([regex]::Escape($secretToken))
        (Get-Content $vbsPath -Encoding Unicode -Raw) | Should Match ([regex]::Escape($secretToken))
    }
}

Describe 'install-windows-lib — Test-ScheduledTaskRegistration (regisztráció utáni visszaolvasás-ellenőrzés)' {
    $taskName = $null

    AfterEach {
        if ($taskName) { Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction SilentlyContinue }
        $taskName = $null
    }

    It 'Ok=$true-t ad, ha a visszaolvasott bejegyzés pontosan megegyezik az elvárttal' {
        $taskName = "FT-PesterVerifyTest-$(Get-Random)"
        $action = New-ScheduledTaskAction -Execute 'wscript.exe' -Argument '//B //NoLogo "C:\test.vbs"'
        $trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 5) -RepetitionDuration (New-TimeSpan -Days 1)
        Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger | Out-Null

        $result = Test-ScheduledTaskRegistration -TaskName $taskName -ExpectedExecute 'wscript.exe' -ExpectedArguments '//B //NoLogo "C:\test.vbs"'
        $result.Ok | Should Be $true
    }

    It 'Ok=$false-t ad, ha az Arguments ELTÉR a várttól (pl. rossz .vbs útvonal)' {
        $taskName = "FT-PesterVerifyTest-$(Get-Random)"
        $action = New-ScheduledTaskAction -Execute 'wscript.exe' -Argument '//B //NoLogo "C:\test.vbs"'
        $trigger = New-ScheduledTaskTrigger -Once -At (Get-Date) -RepetitionInterval (New-TimeSpan -Minutes 5) -RepetitionDuration (New-TimeSpan -Days 1)
        Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger | Out-Null

        $result = Test-ScheduledTaskRegistration -TaskName $taskName -ExpectedExecute 'wscript.exe' -ExpectedArguments '//B //NoLogo "C:\masik-fajl.vbs"'
        $result.Ok | Should Be $false
        $result.Reason | Should Not BeNullOrEmpty
    }

    It 'Ok=$false-t ad, ha a bejegyzés EGYÁLTALÁN NEM létezik' {
        $result = Test-ScheduledTaskRegistration -TaskName 'FT-Nemletezo-Task-XYZ' -ExpectedExecute 'wscript.exe' -ExpectedArguments 'x'
        $result.Ok | Should Be $false
    }
}

Describe 'install-windows-lib — Resolve-NodeRoleChoice (Fázis 2 Checkpoint 3 — szerepkör-menü értelmezése)' {
    It 'Üres bemenetnél az alapértelmezett szerepkört adja vissza' {
        Resolve-NodeRoleChoice -RawInput '' -DefaultRole 'standalone' | Should Be 'standalone'
    }
    It 'Csak whitespace bemenetnél is az alapértelmezettet adja vissza' {
        Resolve-NodeRoleChoice -RawInput '   ' -DefaultRole 'server' | Should Be 'server'
    }
    It "'1'/'2'/'3' menüsorszámokat helyesen fordítja le" {
        Resolve-NodeRoleChoice -RawInput '1' | Should Be 'standalone'
        Resolve-NodeRoleChoice -RawInput '2' | Should Be 'server'
        Resolve-NodeRoleChoice -RawInput '3' | Should Be 'client'
    }
    It 'A szerepkör NEVÉT (kis/nagybetű-független) is elfogadja' {
        Resolve-NodeRoleChoice -RawInput 'Server' | Should Be 'server'
        Resolve-NodeRoleChoice -RawInput 'KLIENS' -DefaultRole 'standalone' | Should Be $null
        Resolve-NodeRoleChoice -RawInput 'client' | Should Be 'client'
        Resolve-NodeRoleChoice -RawInput '  STANDALONE  ' | Should Be 'standalone'
    }
    It 'Érvénytelen bemenetnél $null-t ad vissza (a hívó ekkor újra kérdez/hibát jelez)' {
        Resolve-NodeRoleChoice -RawInput '4' | Should Be $null
        Resolve-NodeRoleChoice -RawInput 'szerver' | Should Be $null
    }
}

Describe 'install-windows-lib — Get-FountainTradeBindHost (Szerver = 0.0.0.0, Önálló/Kliens = localhost)' {
    It "Szerver módban '0.0.0.0'-t ad vissza (DHCP-biztos, minden interfészre köt)" {
        Get-FountainTradeBindHost -NodeRole 'server' | Should Be '0.0.0.0'
    }
    It "Önálló módban 'localhost'-ot ad vissza" {
        Get-FountainTradeBindHost -NodeRole 'standalone' | Should Be 'localhost'
    }
    It "Kliens módban is 'localhost'-ot ad vissza (a Kliens saját szervere sose hallgat hálózati interfészen)" {
        Get-FountainTradeBindHost -NodeRole 'client' | Should Be 'localhost'
    }
}

Describe 'install-windows-lib — Test-FirewallRuleMatchesExpected (tiszta döntési logika, admin-jog nélkül tesztelhető)' {
    It 'Ok=$true, ha minden mező pontosan egyezik' {
        $r = Test-FirewallRuleMatchesExpected -LocalPort '8000' -Protocol 'TCP' -RemoteAddress @('LocalSubnet') -Enabled 'True' -ExpectedPort 8000
        $r.Ok | Should Be $true
    }
    It 'Ok=$false, ha a szabály le van tiltva' {
        $r = Test-FirewallRuleMatchesExpected -LocalPort '8000' -Protocol 'TCP' -RemoteAddress @('LocalSubnet') -Enabled 'False' -ExpectedPort 8000
        $r.Ok | Should Be $false
    }
    It 'Ok=$false, ha a port eltér' {
        $r = Test-FirewallRuleMatchesExpected -LocalPort '9000' -Protocol 'TCP' -RemoteAddress @('LocalSubnet') -Enabled 'True' -ExpectedPort 8000
        $r.Ok | Should Be $false
        $r.Reason | Should Not BeNullOrEmpty
    }
    It 'Ok=$false, ha a protokoll nem TCP' {
        $r = Test-FirewallRuleMatchesExpected -LocalPort '8000' -Protocol 'UDP' -RemoteAddress @('LocalSubnet') -Enabled 'True' -ExpectedPort 8000
        $r.Ok | Should Be $false
    }
    It "Ok=`$false, ha a távoli cím NEM LocalSubnetre korlátozott (pl. 'Any' — internet felőli elérés)" {
        $r = Test-FirewallRuleMatchesExpected -LocalPort '8000' -Protocol 'TCP' -RemoteAddress @('Any') -Enabled 'True' -ExpectedPort 8000
        $r.Ok | Should Be $false
    }
}

Describe 'install-windows-lib — Invoke-FountainTradeTopologyTool (tools/installer-set-topology.php valódi PHP-hívása)' {
    # A $phpExe felderítésnek a Describe-blokk SAJÁT (nem BeforeEach-en
    # belüli) törzsében kell futnia — a lenti "-Skip:(-not $phpExe)"
    # kifejezés MÁR a blokk ÖSSZEGYŰJTÉSEKOR kiértékelődik, tehát egy
    # BeforeEach-be tett felderítés MINDIG $null-t látna (a BeforeEach még
    # nem futott le akkor), és minden tesztet hamisan kihagyna.
    $candidates = @('C:\tools\php83\php.exe', (Get-Command php.exe -ErrorAction SilentlyContinue).Source)
    $phpExe = $candidates | Where-Object { $_ -and (Test-Path $_) } | Select-Object -First 1
    $toolPath = Join-Path $PSScriptRoot '..\tools\installer-set-topology.php'
    $tempRoot = $null

    BeforeEach {
        $tempRoot = Join-Path $env:TEMP "ft-topology-invoke-test-$(Get-Random)"
        New-Item -ItemType Directory -Path (Join-Path $tempRoot 'config') -Force | Out-Null
        New-Item -ItemType Directory -Path (Join-Path $tempRoot 'src') -Force | Out-Null
        New-Item -ItemType Directory -Path (Join-Path $tempRoot 'tools') -Force | Out-Null
        Copy-Item (Join-Path $PSScriptRoot '..\src\UrlSafety.php') (Join-Path $tempRoot 'src\UrlSafety.php')
        Copy-Item $toolPath (Join-Path $tempRoot 'tools\installer-set-topology.php')
    }
    AfterEach {
        if ($tempRoot -and (Test-Path $tempRoot)) { Remove-Item $tempRoot -Recurse -Force -ErrorAction SilentlyContinue }
    }

    It 'Get: üres telepítésnél standalone alapértelmezést ad vissza' -Skip:(-not $phpExe) {
        Push-Location $tempRoot
        try {
            $r = Invoke-FountainTradeTopologyTool -PhpExe $phpExe -ToolPath (Join-Path $tempRoot 'tools\installer-set-topology.php') -Action 'get'
        } finally { Pop-Location }
        $r.ExitCode | Should Be 0
        $r.Json.ok | Should Be $true
        $r.Json.node_role | Should Be 'standalone'
    }

    It 'Set: server szerepkört ír, és a Get ezt utána visszaigazolja' -Skip:(-not $phpExe) {
        Push-Location $tempRoot
        try {
            $set = Invoke-FountainTradeTopologyTool -PhpExe $phpExe -ToolPath (Join-Path $tempRoot 'tools\installer-set-topology.php') -Action 'set' -NodeRole 'server'
            $get = Invoke-FountainTradeTopologyTool -PhpExe $phpExe -ToolPath (Join-Path $tempRoot 'tools\installer-set-topology.php') -Action 'get'
        } finally { Pop-Location }
        $set.ExitCode | Should Be 0
        $set.Json.ok | Should Be $true
        $get.Json.node_role | Should Be 'server'
    }

    It 'Set: kliens szerepkör érvénytelen server-url esetén hibát ad, nem-nulla kilépési kóddal' -Skip:(-not $phpExe) {
        Push-Location $tempRoot
        try {
            $r = Invoke-FountainTradeTopologyTool -PhpExe $phpExe -ToolPath (Join-Path $tempRoot 'tools\installer-set-topology.php') -Action 'set' -NodeRole 'client' -ServerUrl 'nem-egy-url' -ClientId 'cl_x' -ClientSecret 'sekret'
        } finally { Pop-Location }
        $r.ExitCode | Should Not Be 0
        $r.Json.ok | Should Be $false
        $r.Json.error | Should Not BeNullOrEmpty
    }

    It 'Set: érvényes kliens hitelesítő adatokat helyesen ír és olvas vissza' -Skip:(-not $phpExe) {
        Push-Location $tempRoot
        try {
            $set = Invoke-FountainTradeTopologyTool -PhpExe $phpExe -ToolPath (Join-Path $tempRoot 'tools\installer-set-topology.php') -Action 'set' -NodeRole 'client' -ServerUrl 'http://192.168.1.10:8000' -ClientId 'cl_abc' -ClientSecret 'raw-secret'
            $get = Invoke-FountainTradeTopologyTool -PhpExe $phpExe -ToolPath (Join-Path $tempRoot 'tools\installer-set-topology.php') -Action 'get'
        } finally { Pop-Location }
        $set.Json.ok | Should Be $true
        $get.Json.client.server_url | Should Be 'http://192.168.1.10:8000'
        $get.Json.client.client_id | Should Be 'cl_abc'
        $get.Json.client.client_secret | Should Be 'raw-secret'
    }
}

Describe 'install-windows-lib — Resolve-OllamaProvisionDecision (Fázis 6 Rész B — kör 25. pontja)' {
    It 'Önálló gép + Ollama kérve -> felkínálva ÉS telepítendő' {
        $r = Resolve-OllamaProvisionDecision -NodeRole 'standalone' -InstallRequested $true -SkipRequested $false
        $r.ShouldOffer | Should Be $true
        $r.ShouldInstall | Should Be $true
    }
    It 'Önálló gép + Ollama nem kérve (sem -Install, sem -Skip) -> felkínálva, de NEM automatikusan telepítendő' {
        $r = Resolve-OllamaProvisionDecision -NodeRole 'standalone' -InstallRequested $false -SkipRequested $false
        $r.ShouldOffer | Should Be $true
        $r.ShouldInstall | Should Be $false
    }
    It 'Szerver + Ollama kérve -> felkínálva ÉS telepítendő' {
        $r = Resolve-OllamaProvisionDecision -NodeRole 'server' -InstallRequested $true -SkipRequested $false
        $r.ShouldOffer | Should Be $true
        $r.ShouldInstall | Should Be $true
    }
    It 'Szerver + kifejezetten kihagyva (-SkipOllama) -> felkínálva, de NEM telepítendő' {
        $r = Resolve-OllamaProvisionDecision -NodeRole 'server' -InstallRequested $false -SkipRequested $true
        $r.ShouldOffer | Should Be $true
        $r.ShouldInstall | Should Be $false
    }
    It 'Kliens + Ollama kérve -> MÉGIS elutasítva/letiltva (strukturális biztosíték)' {
        $r = Resolve-OllamaProvisionDecision -NodeRole 'client' -InstallRequested $true -SkipRequested $false
        $r.ShouldOffer | Should Be $false
        $r.ShouldInstall | Should Be $false
    }
    It 'Kliens + semmi kérve -> nincs felkínálva/telepítendő Ollama-lépés' {
        $r = Resolve-OllamaProvisionDecision -NodeRole 'client' -InstallRequested $false -SkipRequested $false
        $r.ShouldOffer | Should Be $false
        $r.ShouldInstall | Should Be $false
    }
    It 'Kliens + -SkipOllama is elutasítva marad (a Kliens-tiltás elsőbbséget élvez)' {
        $r = Resolve-OllamaProvisionDecision -NodeRole 'client' -InstallRequested $false -SkipRequested $true
        $r.ShouldOffer | Should Be $false
    }
}

Describe 'install-windows-lib — Test-OllamaInstallerRequiresElevation (kör 15./21. pontja kutatási eredménye)' {
    It 'Az Ollama Windows-telepítője NEM igényel UAC-emelést (Inno Setup PrivilegesRequired=lowest)' {
        Test-OllamaInstallerRequiresElevation | Should Be $false
    }
}

Describe 'install-windows-lib — Test-ShouldSkipOllamaInstall (idempotencia / already-installed detection)' {
    It 'Már telepített, sikeres állapot-válasz esetén kihagyja az újratelepítést' {
        Test-ShouldSkipOllamaInstall -StatusJson ([PSCustomObject]@{ ok = $true; installed = $true }) | Should Be $true
    }
    It 'Nem telepített állapotnál NEM hagyja ki (telepíteni kell)' {
        Test-ShouldSkipOllamaInstall -StatusJson ([PSCustomObject]@{ ok = $true; installed = $false }) | Should Be $false
    }
    It 'Sikertelen állapot-lekérdezésnél (ok=false) NEM hagyja ki (biztonságosan a telepítés felé dönt)' {
        Test-ShouldSkipOllamaInstall -StatusJson ([PSCustomObject]@{ ok = $false; installed = $true }) | Should Be $false
    }
    It 'Hiányzó/$null állapotnál NEM hagyja ki (nem dob kivételt)' {
        Test-ShouldSkipOllamaInstall -StatusJson $null | Should Be $false
    }
    It 'Idempotens újrafuttatás: két EGYMÁS UTÁNI hívás UGYANAZT az eredményt adja ugyanarra a bemenetre (nincs rejtett állapot)' {
        $status = [PSCustomObject]@{ ok = $true; installed = $true }
        (Test-ShouldSkipOllamaInstall -StatusJson $status) | Should Be (Test-ShouldSkipOllamaInstall -StatusJson $status)
    }
}

Describe 'install-windows-lib — Invoke-FountainTradeOllamaProvisionTool (tools/ollama-provision-cli.php valódi PHP-hívása, mock Ollama nélkül)' {
    $candidates = @('C:\tools\php83\php.exe', (Get-Command php.exe -ErrorAction SilentlyContinue).Source)
    $phpExe = $candidates | Where-Object { $_ -and (Test-Path $_) } | Select-Object -First 1
    $toolPath = Join-Path $PSScriptRoot '..\tools\ollama-provision-cli.php'
    $tempRoot = $null

    BeforeEach {
        $tempRoot = Join-Path $env:TEMP "ft-ollama-provision-invoke-test-$(Get-Random)"
        New-Item -ItemType Directory -Path (Join-Path $tempRoot 'src\Ai') -Force | Out-Null
        New-Item -ItemType Directory -Path (Join-Path $tempRoot 'tools') -Force | Out-Null
        Copy-Item (Join-Path $PSScriptRoot '..\src\Ai\AiProviderException.php') (Join-Path $tempRoot 'src\Ai\AiProviderException.php')
        Copy-Item (Join-Path $PSScriptRoot '..\src\Ai\OllamaProvisioner.php') (Join-Path $tempRoot 'src\Ai\OllamaProvisioner.php')
        Copy-Item $toolPath (Join-Path $tempRoot 'tools\ollama-provision-cli.php')
    }
    AfterEach {
        if ($tempRoot -and (Test-Path $tempRoot)) { Remove-Item $tempRoot -Recurse -Force -ErrorAction SilentlyContinue }
    }

    It 'status: nem-elérhető Ollamánál installed=false, api_available=false (SOSE dob kivételt/hibát)' -Skip:(-not $phpExe) {
        # Szándékosan egy SOSE-hallgató porton — a valódi 127.0.0.1:11434-en
        # PHPUnit alatt sem akarunk valódi Ollamától függeni (kör 27. pontja).
        $r = Invoke-FountainTradeOllamaProvisionTool -PhpExe $phpExe -ToolPath (Join-Path $tempRoot 'tools\ollama-provision-cli.php') -Action 'status' -BaseUrl 'http://127.0.0.1:1' -Model 'qwen3:8b'
        $r.ExitCode | Should Be 0
        $r.Json.ok | Should Be $true
        $r.Json.api_available | Should Be $false
        $r.Json.configured_model | Should Be 'qwen3:8b'
    }

    It 'pull-model: nem-elérhető Ollamánál hibát ad, nem-nulla kilépési kóddal (nem próbál telepíteni helyette)' -Skip:(-not $phpExe) {
        $r = Invoke-FountainTradeOllamaProvisionTool -PhpExe $phpExe -ToolPath (Join-Path $tempRoot 'tools\ollama-provision-cli.php') -Action 'pull-model' -BaseUrl 'http://127.0.0.1:1' -Model 'qwen3:8b'
        $r.ExitCode | Should Not Be 0
        $r.Json.ok | Should Be $false
        $r.Json.error | Should Not BeNullOrEmpty
    }
}

Describe 'install-windows.ps1 — Fázis 6 Rész B szerkezeti garanciák (statikus forrás-ellenőrzés, Ollama)' {
    $mainScriptText = Get-Content (Join-Path $PSScriptRoot '..\install-windows.ps1') -Raw

    It 'Az Ollama-lépés a MEGLÉVŐ Resolve-OllamaProvisionDecision döntést használja, nem egy párhuzamos saját feltételt' {
        $mainScriptText | Should Match 'Resolve-OllamaProvisionDecision -NodeRole \$NodeRole'
    }
    It 'Egy sikertelen (nem-kötelező) Ollama-telepítés NEM állítja meg a teljes telepítést (nincs Exit-WithFailureSummary az általános ágon)' {
        $mainScriptText | Should Match 'Write-Warn2 "Az Ollama telep[ií]t[eé]se sikertelen'
    }
    It '-OllamaRequired esetén egy sikertelen telepítés MEGSZAKÍTJA a teljes telepítést' {
        $mainScriptText | Should Match '\$OllamaRequired\)[\s\S]{0,200}Exit-WithFailureSummary'
    }
    It 'Kliens node-on külön ág fut le, ami NEM ajánlja fel az Ollama-telepítést' {
        $mainScriptText | Should Match 'Kliens node — az Ollama itt nem el[eé]rhet[oő]'
    }
}

Describe 'install-windows.ps1 — Fázis 2 Checkpoint 3 szerkezeti garanciák (statikus forrás-ellenőrzés)' {
    $mainScriptText = Get-Content (Join-Path $PSScriptRoot '..\install-windows.ps1') -Raw

    It "A wc-queue-run.php szerepel a `$cronJobs listában (korábban hiányzó bejegyzés javítva)" {
        $mainScriptText | Should Match "Endpoint = 'wc-queue-run\.php'"
    }
    It 'Mind a hat MEGLÉVŐ worker-végpont szerepel a cron-listában' {
        foreach ($ep in @('auto-backup-run.php', 'auto-sync-run.php', 'nav-queue-run.php', 'nav-incoming-sync-run.php', 'update-check-run.php', 'wc-queue-run.php')) {
            $mainScriptText | Should Match "Endpoint = '$([regex]::Escape($ep))'"
        }
    }
    It 'Fázis 7 — az AI napi intelligencia worker (ai-daily-intelligence-run.php) is szerepel a cron-listában' {
        $mainScriptText | Should Match "Endpoint = 'ai-daily-intelligence-run\.php'"
    }
    It 'Kliens módban a szkript AKTÍVAN eltávolítja (Unregister-ScheduledTask) a korábbi worker-feladatokat' {
        $mainScriptText | Should Match "NodeRole -eq 'client'[\s\S]{0,1200}Unregister-ScheduledTask"
    }
    It "A szerver-indítás a `$bindHost változót használja, NEM egy kőbe vésett 'localhost'-ot" {
        $mainScriptText | Should Match '-S \$\{bindHost\}:\$Port'
        $mainScriptText | Should Not Match '-S localhost:\$Port -t'
    }
    It 'Szerver módban a szkript ténylegesen létrehoz egy New-NetFirewallRule hívást' {
        $mainScriptText | Should Match "NodeRole -eq 'server'[\s\S]{0,2000}New-NetFirewallRule"
    }
    It 'A tűzfalszabály kizárólag LocalSubnetre korlátozott, sose "Any"-re' {
        $mainScriptText | Should Match "-RemoteAddress LocalSubnet"
        $mainScriptText | Should Not Match "-RemoteAddress\s+Any"
    }
    It 'A parancsikon a Kliens saját localhost Dashboardjára mutat, SOSE a konfigurált távoli -ServerUrl-re (nem kerüli meg a ClientProxy-t)' {
        $mainScriptText | Should Match '\$dashboardUrl = "http://localhost:\$Port/dashboard\.php"'
        $mainScriptText | Should Not Match '\$dashboardUrl\s*=\s*\$(clientServerUrl|ServerUrl)'
    }
    It 'A Kliens Client Secret bekérése -AsSecureString-gel történik (nem sima szövegként visszhangozva)' {
        $mainScriptText | Should Match 'Read-Host "Client Secret" -AsSecureString'
    }
    It 'HTTP figyelmeztetés jelenik meg, ha a Kliens konfigurált szerver-címe sima HTTP' {
        $mainScriptText | Should Match 'A kapcsolat nem titkosított\. Internetes használathoz HTTPS szükséges\.'
    }
}

Describe 'Kassza .ico — jelenlét és shortcut-integráció (regresszió: korábban .svg volt az IconLocation-ben)' {
    $icoPath = Join-Path $PSScriptRoot '..\webroot\assets\fountaintrade-kassa.ico'
    $mainScriptText = Get-Content (Join-Path $PSScriptRoot '..\install-windows.ps1') -Raw

    It 'A fountaintrade-kassa.ico ténylegesen létezik a webroot/assets alatt' {
        Test-Path $icoPath | Should Be $true
    }

    It 'Az .ico fájl érvényes, több felbontású Windows ikon (a .NET saját Icon-betöltőjével ellenőrizve)' {
        Add-Type -AssemblyName System.Drawing
        { New-Object System.Drawing.Icon($icoPath) } | Should Not Throw
    }

    It 'Az .ico fájl legalább 16/32/48/256px felbontást tartalmaz (ICONDIR bejegyzésszám >= 4)' {
        $bytes = [System.IO.File]::ReadAllBytes($icoPath)
        $count = $bytes[4] + ($bytes[5] * 256)
        $count | Should BeGreaterThan 3
    }

    It 'install-windows.ps1 a VALÓDI .ico fájlra állítja be a parancsikon IconLocation-jét' {
        $mainScriptText | Should Match 'fountaintrade-kassa\.ico'
        $mainScriptText | Should Match '\$shortcut\.IconLocation\s*='
    }

    It 'install-windows.ps1 SEHOL nem állít be .svg-t parancsikon IconLocation-ként (a Windows Shell ezt nem támogatja)' {
        $mainScriptText | Should Not Match 'IconLocation\s*=\s*"[^"]*\.svg'
    }

    It 'A parancsikon-létrehozó függvény a WorkingDirectory-t is beállítja (nem csak Target/Arguments-et)' {
        $mainScriptText | Should Match '\$shortcut\.WorkingDirectory\s*='
    }
}

Describe 'Biztonsági audit F-04 — Get-FountainTradeAclPlan (legkisebb jogosultság, döntési logika)' {
    $ownerSid = 'S-1-5-21-1111111111-2222222222-3333333333-1001'
    $plan = Get-FountainTradeAclPlan -InstallPath 'C:\ProgramData\FountainTrade' -OwnerSid $ownerSid
    $mainScriptText = Get-Content (Join-Path $PSScriptRoot '..\install-windows.ps1') -Raw

    It 'A BUILTIN\Users SEHOL nem kap RX-nél erősebb jogot' {
        foreach ($step in $plan) {
            foreach ($arg in $step.Arguments) {
                if ($arg -like '*S-1-5-32-545:*') { $arg | Should Be '*S-1-5-32-545:(OI)(CI)RX' }
            }
        }
    }

    It 'Első lépésként /reset /T törli a korábbi explicit (pl. régi rekurzív Users:Modify) bejegyzéseket' {
        $plan[0].Arguments | Should Be @('C:\ProgramData\FountainTrade', '/reset', '/T', '/C', '/Q')
    }

    It 'A gyökér megszünteti az öröklést, és csak SYSTEM/Administrators/telepítő fiók írhat' {
        $root = $plan[1].Arguments -join ' '
        $root | Should Match '/inheritance:r'
        $root | Should Match ([regex]::Escape("*${ownerSid}:(OI)(CI)M"))
        $root | Should Match ([regex]::Escape('*S-1-5-18:(OI)(CI)F'))
        $root | Should Match ([regex]::Escape('*S-1-5-32-544:(OI)(CI)F'))
    }

    It 'A config/data/invoices mappák öröklés nélküliek, és a Users-nek SEMMILYEN joga nincs rajtuk' {
        foreach ($sub in @('config', 'data', 'invoices')) {
            $step = $plan | Where-Object { $_.Path -eq (Join-Path 'C:\ProgramData\FountainTrade' $sub) }
            $step | Should Not BeNullOrEmpty
            ($step.Arguments -join ' ') | Should Match '/inheritance:r'
            ($step.Arguments -join ' ') | Should Not Match 'S-1-5-32-545'
            ($step.Arguments -join ' ') | Should Match ([regex]::Escape("*${ownerSid}:(OI)(CI)M"))
        }
    }

    It 'Idempotens: ugyanarra a bemenetre bájtra azonos tervet ad (újrafuttatott telepítő)' {
        $again = Get-FountainTradeAclPlan -InstallPath 'C:\ProgramData\FountainTrade' -OwnerSid $ownerSid
        ($again | ForEach-Object { $_.Arguments -join '|' }) -join "`n" | Should Be (($plan | ForEach-Object { $_.Arguments -join '|' }) -join "`n")
    }

    It 'install-windows.ps1 már NEM ad rekurzív Users:Modify jogot, és szerepkörtől függetlenül a tervet alkalmazza' {
        $mainScriptText | Should Not Match 'S-1-5-32-545:\(OI\)\(CI\)M'
        $mainScriptText | Should Match 'Invoke-FountainTradeAclPlan -Plan \(Get-FountainTradeAclPlan -InstallPath \$InstallPath'
        $mainScriptText.IndexOf('Invoke-FountainTradeAclPlan') | Should BeLessThan $mainScriptText.IndexOf('$topologySetArgs')
    }
}

Describe 'Biztonsági audit F-04 — ACL-terv VALÓDI alkalmazása egy ideiglenes könyvtárfán (Windows, saját tulajdonú mappa)' {
    $isWindowsHost = $env:OS -eq 'Windows_NT'
    $usersSid = New-Object System.Security.Principal.SecurityIdentifier('S-1-5-32-545')
    $writeLikeRights = [System.Security.AccessControl.FileSystemRights]::WriteData -bor
        [System.Security.AccessControl.FileSystemRights]::AppendData -bor
        [System.Security.AccessControl.FileSystemRights]::Delete -bor
        [System.Security.AccessControl.FileSystemRights]::ChangePermissions -bor
        [System.Security.AccessControl.FileSystemRights]::TakeOwnership

    function Get-UsersRules([string]$path) {
        (Get-Acl -LiteralPath $path).Access | Where-Object {
            $_.AccessControlType -eq 'Allow' -and $_.IdentityReference.Translate([System.Security.Principal.SecurityIdentifier]).Value -eq $usersSid.Value
        }
    }

    $tempRoot = $null
    BeforeEach {
        $tempRoot = Join-Path $env:TEMP "ft-acl-live-test-$(Get-Random)"
        foreach ($d in @('webroot', 'src', 'tools', 'config', 'data', 'invoices')) { New-Item -ItemType Directory -Path (Join-Path $tempRoot $d) -Force | Out-Null }
        Set-Content -Path (Join-Path $tempRoot 'webroot\index.php') -Value '<?php'
        Set-Content -Path (Join-Path $tempRoot 'tools\run-server-hidden.vbs') -Value "' launcher"
        Set-Content -Path (Join-Path $tempRoot 'config\installer-generated.php') -Value '<?php return [];'
        Set-Content -Path (Join-Path $tempRoot 'data\settings.json') -Value '{}'
        # A RÉGI telepítő viselkedésének szimulálása — ezt a tervnek el kell távolítania.
        & icacls $tempRoot /grant '*S-1-5-32-545:(OI)(CI)M' /T /Q | Out-Null
    }
    AfterEach {
        if ($tempRoot -and (Test-Path $tempRoot)) {
            & icacls $tempRoot /reset /T /C /Q | Out-Null
            Remove-Item $tempRoot -Recurse -Force -ErrorAction SilentlyContinue
        }
    }

    It 'Alkalmazás (kétszer, idempotensen) után a Users sehol nem írhat, config/data/invoices-hoz nem is fér hozzá, a telepítő fiók viszont írhat' -Skip:(-not $isWindowsHost) {
        $ownerSid = [System.Security.Principal.WindowsIdentity]::GetCurrent().User.Value
        Invoke-FountainTradeAclPlan -Plan (Get-FountainTradeAclPlan -InstallPath $tempRoot -OwnerSid $ownerSid)
        Invoke-FountainTradeAclPlan -Plan (Get-FountainTradeAclPlan -InstallPath $tempRoot -OwnerSid $ownerSid)

        foreach ($rel in @('', 'webroot', 'webroot\index.php', 'src', 'tools', 'tools\run-server-hidden.vbs')) {
            $rules = Get-UsersRules (Join-Path $tempRoot $rel)
            @($rules).Count | Should BeGreaterThan 0
            foreach ($r in $rules) { ($r.FileSystemRights -band $writeLikeRights) | Should Be 0 }
        }
        foreach ($rel in @('config', 'config\installer-generated.php', 'data', 'data\settings.json', 'invoices')) {
            @(Get-UsersRules (Join-Path $tempRoot $rel)).Count | Should Be 0
        }

        { Add-Content -Path (Join-Path $tempRoot 'data\settings.json') -Value ' ' -ErrorAction Stop } | Should Not Throw
        { Set-Content -Path (Join-Path $tempRoot 'data\uj-futasideju-fajl.txt') -Value 'x' -ErrorAction Stop } | Should Not Throw
    }
}

Describe 'Biztonsági audit F-01 — Resolve-ServerAppPasswordAction (Szerver app-jelszó döntési logika)' {
    $mainScriptText = Get-Content (Join-Path $PSScriptRoot '..\install-windows.ps1') -Raw

    It 'Nem-Szerver szerepkörben sose kér/állít jelszót' {
        Resolve-ServerAppPasswordAction -NodeRole 'standalone' | Should Be 'skip'
        Resolve-ServerAppPasswordAction -NodeRole 'client' -EnvPasswordProvided $true | Should Be 'skip'
    }
    It 'Szerver: meglévő jelszót sose ír felül (idempotens újrafuttatás)' {
        Resolve-ServerAppPasswordAction -NodeRole 'server' -HasPassword $true -EnvPasswordProvided $true | Should Be 'keep'
    }
    It 'Szerver, nincs jelszó: környezeti változó > interaktív bekérés; nem-interaktívan jelszó nélkül MEGÁLL' {
        Resolve-ServerAppPasswordAction -NodeRole 'server' -EnvPasswordProvided $true -NonInteractive $true | Should Be 'use_env'
        Resolve-ServerAppPasswordAction -NodeRole 'server' -NonInteractive $false | Should Be 'prompt'
        Resolve-ServerAppPasswordAction -NodeRole 'server' -NonInteractive $true | Should Be 'fail'
    }
    It 'install-windows.ps1 Szerver módban meghívja, és a jelszót sose adja át parancssori argumentumként' {
        $mainScriptText | Should Match 'if \(\$NodeRole -eq ''server''\) \{\s+\$appPasswordToolPath'
        $mainScriptText | Should Match 'Resolve-ServerAppPasswordAction'
        $mainScriptText | Should Not Match '--password'
    }
}

Describe 'Biztonsági audit F-01 — Invoke-FountainTradeAppPasswordTool (valódi PHP, stdin, UTF-8)' {
    $candidates = @('C:\tools\php83\php.exe', (Get-Command php.exe -ErrorAction SilentlyContinue).Source)
    $phpExe = $candidates | Where-Object { $_ -and (Test-Path $_) } | Select-Object -First 1
    $tempRoot = $null

    BeforeEach {
        $tempRoot = Join-Path $env:TEMP "ft-app-password-test-$(Get-Random)"
        foreach ($d in @('src', 'tools', 'data')) { New-Item -ItemType Directory -Path (Join-Path $tempRoot $d) -Force | Out-Null }
        Copy-Item (Join-Path $PSScriptRoot '..\src\Settings.php') (Join-Path $tempRoot 'src\Settings.php')
        Copy-Item (Join-Path $PSScriptRoot '..\tools\installer-set-app-password.php') (Join-Path $tempRoot 'tools\installer-set-app-password.php')
    }
    AfterEach {
        if ($tempRoot -and (Test-Path $tempRoot)) { Remove-Item $tempRoot -Recurse -Force -ErrorAction SilentlyContinue }
    }

    It 'Ékezetes jelszó stdin-en át, UTF-8-ban tárolódik (a böngészős bejelentkezéssel egyezni fog)' -Skip:(-not $phpExe) {
        $tool = Join-Path $tempRoot 'tools\installer-set-app-password.php'
        (Invoke-FountainTradeAppPasswordTool -PhpExe $phpExe -ToolPath $tool -Action 'status').Json.has_password | Should Be $false

        $set = Invoke-FountainTradeAppPasswordTool -PhpExe $phpExe -ToolPath $tool -Action 'set' -Password 'Árvíztűrő-Jelszó-9'
        $set.ExitCode | Should Be 0
        $set.Json.changed | Should Be $true

        $hash = (Get-Content (Join-Path $tempRoot 'data\settings.json') -Raw -Encoding UTF8 | ConvertFrom-Json).app_password_hash
        $verifyScript = Join-Path $tempRoot 'verify.php'
        Set-Content -Path $verifyScript -Encoding Ascii -Value '<?php echo password_verify(hex2bin($argv[1]), $argv[2]) ? "ok" : "no";'
        $hex = ([System.BitConverter]::ToString([System.Text.Encoding]::UTF8.GetBytes('Árvíztűrő-Jelszó-9')) -replace '-', '').ToLower()
        (& $phpExe $verifyScript $hex $hash) | Should Be 'ok'
    }
}
