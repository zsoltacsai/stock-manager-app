# FountainTrade Windows telepítő — tisztán logikai, mellékhatás-mentes
# segédfüggvények, KÜLÖN fájlban.
#
# Ezt a fájlt az install-windows.ps1 dot-source-olja be (". ... " —
# lásd ott), ÉS a Pester-tesztek (tests\Install-WindowsTests.ps1) is
# UGYANÍGY, egyszerű dot-source-olással töltik be — SOHA NEM AST-
# kinyeréssel/Invoke-Expression-nel egy futó szkriptből.
#
# EZ SZÁNDÉKOS, ÉLŐ TAPASZTALATTAL INDOKOLT DÖNTÉS: egy korábbi
# teszt-módszer az install-windows.ps1 AST-jából (.NET parserrel)
# nyerte ki és Invoke-Expression-nel futtatta a függvénydefiníciókat —
# ez a "dinamikusan kinyert kód futtatása Invoke-Expression-nel" minta
# ÖNMAGÁBAN (a tartalomtól függetlenül) egy víruskereső malware-
# heurisztikáját ütötte meg, és a TESZT FÁJLT karanténba
# helyezte/törölte — a tényleges install-windows.ps1 és az általa
# generált .vbs fájlok NEM lettek érintve. Egy sima, közönséges
# dot-source ("." operátor egy fájlnévvel) NEM mutatja ezt a mintát —
# ez az iparágban általánosan elterjedt, nem gyanús PowerShell-
# könyvtár-betöltési technika.
#
# EBBEN A FÁJLBAN KIZÁRÓLAG OLYAN FÜGGVÉNYEK VANNAK, AMIK:
#   - nem indítanak self-elevation-t,
#   - nem töltenek le semmit a hálózatról,
#   - nem módosítanak Feladatütemező-bejegyzést (csak OLVASSÁK
#     vissza, lásd Test-ScheduledTaskRegistration),
#   - egyedi hívásokkal, elszigetelten tesztelhetők.

function Get-AllowedHost {
    param([string]$Url)
    try { return ([Uri]$Url).Host } catch { return $null }
}

# Zip Slip elleni védelem PowerShell-ben — a src/UpdateVerifier.php
# assertSafeZipEntryName()-jének megfelelője: path traversal ("..",),
# abszolút útvonal, Windows-meghajtóbetűjel egyik bejegyzésnél sem
# engedélyezett. Minden bejegyzést ELLENŐRZÜNK, mielőtt BÁRMIT kiírnánk.
# KÜLÖN, ÖNÁLLÓ FÜGGVÉNY (nem az Expand-SafeZip törzsébe ágyazva) —
# szándékosan, hogy a tesztek TISZTA STRINGEKKEL ellenőrizhessék ugyanezt
# a logikát, anélkül hogy egy ténylegesen path-traversal-bejegyzést
# tartalmazó ZIP-fájlt kellene létrehozniuk a lemezen (lásd a fájl
# tetején lévő docblokk — ugyanaz az elv, ami miatt ez a fájl is
# külön lett választva).
function Test-SafeZipEntryName {
    param([string]$Name)
    if ([string]::IsNullOrEmpty($Name)) { return $true }
    $normalized = $Name -replace '\\', '/'
    if ($normalized.StartsWith('/') -or $normalized -match '^[A-Za-z]:' -or ($normalized -split '/') -contains '..') {
        return $false
    }
    return $true
}

function Expand-SafeZip {
    param([string]$ZipPath, [string]$DestDir, [scriptblock]$OnUnsafeEntry)

    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $zip = [System.IO.Compression.ZipFile]::OpenRead($ZipPath)
    try {
        foreach ($entry in $zip.Entries) {
            $name = $entry.FullName
            if (-not (Test-SafeZipEntryName $name)) {
                $zip.Dispose()
                if ($OnUnsafeEntry) { & $OnUnsafeEntry $name }
                throw "Az archívum gyanús bejegyzést tartalmaz (`"$name`")."
            }
        }
        foreach ($entry in $zip.Entries) {
            if ([string]::IsNullOrEmpty($entry.Name) -and $entry.FullName.EndsWith('/')) {
                New-Item -ItemType Directory -Path (Join-Path $DestDir $entry.FullName) -Force | Out-Null
                continue
            }
            $targetPath = Join-Path $DestDir ($entry.FullName -replace '/', '\')
            $targetParent = Split-Path -Parent $targetPath
            if (-not (Test-Path $targetParent)) { New-Item -ItemType Directory -Path $targetParent -Force | Out-Null }
            [System.IO.Compression.ZipFileExtensions]::ExtractToFile($entry, $targetPath, $true)
        }
    } finally {
        $zip.Dispose()
    }
}

# Rejtett-ablakos indítówrapper (VBScript + wscript.exe) — élő teszteléssel
# igazolt gyökér-ok: a curl.exe/php.exe KONZOL-alkalmazás, ezért a
# Feladatütemező minden egyes triggerkor egy látható (curl.exe esetén
# felvillanó, php.exe esetén TARTÓSAN nyitva maradó) konzolablakot nyit
# nekik. A wscript.exe (GUI-alrendszerű, saját ablak nélküli) egy
# WScript.Shell.Run(cmd, 0, True) hívással el tudja indítani a célfolyamatot
# TELJESEN REJTETT ablakkal (windowStyle=0), a valódi kilépési kódot
# megőrizve — ez a Windows évtizedek óta bevált, natív (nem harmadik féltől
# származó runtime-ot igénylő) megoldása erre a problémára. Minden szükséges
# parancssor a .vbs SAJÁT FORRÁSÁBA van sütve (nem wscript.exe argvon
# keresztül átadva) — ez elkerüli a többrétegű (Feladatütemező XML → wscript
# argv → VBScript string → CreateProcess) idézőjel-escapelés törékenységét,
# és azt is, hogy a cron-titkos token megjelenjen a Feladatütemező saját
# Action/Arguments mezőjében (élő teszteléssel felfedezett, valódi
# biztonsági megfigyelés — korábban a token nyers szövegként volt látható a
# `Get-ScheduledTask`/Feladatütemező GUI Action-oszlopában).
function ConvertTo-VbsStringLiteral {
    param([string]$Value)
    return '"' + ($Value -replace '"', '""') + '"'
}

function New-HiddenLauncherVbs {
    param([string]$VbsPath, [string]$ExePath, [string]$Arguments)
    $exeLit = ConvertTo-VbsStringLiteral $ExePath
    $argsLit = ConvertTo-VbsStringLiteral $Arguments
    $vbsLines = @(
        "' FountainTrade - automatikusan generalt, rejtett ablakos inditowrapper.",
        "' NE szerkeszd kezzel - minden install-windows.ps1 futtataskor ujragheneralodik.",
        'Set objShell = CreateObject("WScript.Shell")',
        "cmdLine = $exeLit & `" `" & $argsLit",
        'exitCode = objShell.Run(cmdLine, 0, True)',
        'WScript.Quit(exitCode)'
    )
    $vbsContent = ($vbsLines -join "`r`n") + "`r`n"
    # UTF-16LE + BOM — a klasszikus VBScript-motor (wscript.exe/cscript.exe)
    # UTF-8-at NEM ismeri fel megbízhatóan (csak ANSI-t vagy UTF-16LE+BOM
    # "Unicode szöveget"), ez pedig ékezetes karaktereket tartalmazó
    # telepítési útvonalaknál (pl. "C:\Users\Felhasználó\...") valódi,
    # reprodukálható hibát okozna UTF-8-cal.
    [System.IO.File]::WriteAllText($VbsPath, $vbsContent, [System.Text.Encoding]::Unicode)
}

function Get-OrCreateCronToken {
    param([string]$SettingsPath, [string]$SuppliedToken)

    if ($SuppliedToken) { return @{ Token = $SuppliedToken; Generated = $false } }

    $existing = $null
    if (Test-Path $SettingsPath) {
        try {
            # ÉLŐ ADATVESZTÉST OKOZÓ HIBA JAVÍTVA: a "Get-Content -Raw" (explicit
            # -Encoding NÉLKÜL) Windows PowerShell 5.1 alatt egy BOM NÉLKÜLI
            # fájlnál (a PHP json_encode() SOSE ír BOM-ot) a RENDSZER ANSI
            # kódlapját használja UTF-8 helyett (pl. magyar Windows-on CP1250) —
            # ez minden ékezetes karaktert (payment_methods, receipt_header_lines
            # stb.) hangtalanul összetört a settings.json egy korábbi
            # olvasás-módosítás-írás körénél. [System.IO.File]::ReadAllText()
            # explicit UTF-8 encoding-gal MINDIG helyesen olvas, függetlenül a
            # rendszer nyelvi beállításaitól.
            $json = [System.IO.File]::ReadAllText($SettingsPath, [System.Text.Encoding]::UTF8) | ConvertFrom-Json
            if ($json.PSObject.Properties.Name -contains 'cron_secret' -and $json.cron_secret) {
                $existing = $json.cron_secret
            }
        } catch { }
    }
    if ($existing) { return @{ Token = $existing; Generated = $false } }

    # Kriptográfiailag véletlen, 32 bájtos (64 hex karakteres) token —
    # ugyanolyan erősségű, mint amit egy felhasználó a Beállítások oldalon
    # kézzel generálna. A szkript SAJÁT FORRÁSÁBA soha nem kerül be —
    # csak futásidőben, a CÉLGÉPEN generálódik.
    $bytes = New-Object byte[] 32
    [System.Security.Cryptography.RandomNumberGenerator]::Create().GetBytes($bytes)
    $generated = ($bytes | ForEach-Object { $_.ToString('x2') }) -join ''

    $obj = if (Test-Path $SettingsPath) {
        # Lásd fent a docblokkot — ugyanaz a magyar-ékezet-tördelő hiba
        # javítva itt is, explicit UTF-8 olvasással.
        try { [System.IO.File]::ReadAllText($SettingsPath, [System.Text.Encoding]::UTF8) | ConvertFrom-Json } catch { [PSCustomObject]@{} }
    } else {
        [PSCustomObject]@{}
    }
    if ($obj.PSObject.Properties.Name -contains 'cron_secret') {
        $obj.cron_secret = $generated
    } else {
        $obj | Add-Member -NotePropertyName 'cron_secret' -NotePropertyValue $generated -Force
    }
    New-Item -ItemType Directory -Path (Split-Path -Parent $SettingsPath) -Force | Out-Null
    $json = $obj | ConvertTo-Json -Depth 20
    # UTF-8, BOM NÉLKÜL — a PHP json_decode() nem tolerálja a BOM-ot, a
    # Settings::save() saját írása is BOM nélküli UTF-8-at termel.
    [System.IO.File]::WriteAllText($SettingsPath, $json, (New-Object System.Text.UTF8Encoding($false)))

    return @{ Token = $generated; Generated = $true }
}

# A kör 8. pontjának explicit követelménye: egy Register-ScheduledTask
# hívás sikeres visszatérése (kivétel nélkül) ÖNMAGÁBAN NEM elég ahhoz,
# hogy "[OK]"-t írjunk — a bejegyzést VISSZA KELL OLVASNI, és ténylegesen
# ellenőrizni, hogy létezik, engedélyezett, és az Action pontosan azt
# tartalmazza, amit vártunk (végrehajtható, argumentumok, munkakönyvtár).
function Test-ScheduledTaskRegistration {
    param(
        [string]$TaskName,
        [string]$ExpectedExecute,
        [string]$ExpectedArguments,
        [string]$ExpectedWorkingDirectory = $null
    )
    $task = Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue
    if (-not $task) {
        return @{ Ok = $false; Reason = 'A bejegyzés nem található visszaolvasáskor.' }
    }
    if (-not $task.Settings.Enabled) {
        return @{ Ok = $false; Reason = 'A bejegyzés le van tiltva (Enabled=false).' }
    }
    $action = $task.Actions | Select-Object -First 1
    if (-not $action -or $action.Execute -ne $ExpectedExecute) {
        return @{ Ok = $false; Reason = "Az Action.Execute eltér a várttól (talált: '$($action.Execute)', várt: '$ExpectedExecute')." }
    }
    if ($action.Arguments -ne $ExpectedArguments) {
        return @{ Ok = $false; Reason = "Az Action.Arguments eltér a várttól (talált: '$($action.Arguments)', várt: '$ExpectedArguments')." }
    }
    if ($ExpectedWorkingDirectory -and $action.WorkingDirectory -ne $ExpectedWorkingDirectory) {
        return @{ Ok = $false; Reason = "Az Action.WorkingDirectory eltér a várttól (talált: '$($action.WorkingDirectory)', várt: '$ExpectedWorkingDirectory')." }
    }
    return @{ Ok = $true; Reason = '' }
}
