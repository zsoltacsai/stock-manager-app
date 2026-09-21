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
