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

# ---------------------------------------------------------------------
# Fázis 2, Checkpoint 3 — node-szerepkör (Önálló gép/Szerver/Kliens)
# kiválasztás + a config/installer-generated.php írásáért felelős
# tools/installer-set-topology.php PHP CLI-eszköz meghívása.
# ---------------------------------------------------------------------

# Pure — egy már beolvasott nyers választ (menüsorszám VAGY szó) alakít
# kanonikus szerepkörré. KÜLÖN a tényleges Read-Host hívástól (lásd
# install-windows.ps1 "Node-szerepkör kiválasztása" szakasza), hogy Pester
# konzol-I/O nélkül tesztelhesse a válasz-értelmezést.
function Resolve-NodeRoleChoice {
    param([string]$RawInput, [string]$DefaultRole = 'standalone')
    $trimmed = $RawInput
    if ($null -eq $trimmed) { $trimmed = '' }
    $trimmed = $trimmed.Trim()
    if ($trimmed -eq '') { return $DefaultRole }
    if ($trimmed -eq '1') { return 'standalone' }
    if ($trimmed -eq '2') { return 'server' }
    if ($trimmed -eq '3') { return 'client' }
    $lower = $trimmed.ToLowerInvariant()
    if (@('standalone', 'server', 'client') -contains $lower) { return $lower }
    return $null
}

# A tools/installer-set-topology.php CLI-eszköz vékony, argumentum-építő +
# JSON-parszoló hívóburka — MAGA a PHP-eszköz végzi a tényleges
# olvasást/írást/UrlSafety-ellenőrzést (lásd annak docblokkja: "NE egy
# PowerShell-oldali, saját PHP-array-szerializálás" — ugyanaz az elv, mint a
# GitHub Release-ellenőrzésnél, csak itt egyenesen a valódi PHP-kódot hívjuk
# meg, nem a szabályait másoljuk).
function Invoke-FountainTradeTopologyTool {
    param(
        [Parameter(Mandatory)][string]$PhpExe,
        [Parameter(Mandatory)][string]$ToolPath,
        [Parameter(Mandatory)][string]$Action,
        [string]$NodeRole,
        [string]$ServerUrl,
        [string]$ClientId,
        [string]$ClientSecret
    )
    $argList = @($ToolPath, "--action=$Action")
    if ($NodeRole) { $argList += "--node-role=$NodeRole" }
    if ($PSBoundParameters.ContainsKey('ServerUrl')) { $argList += "--server-url=$ServerUrl" }
    if ($PSBoundParameters.ContainsKey('ClientId')) { $argList += "--client-id=$ClientId" }
    if ($PSBoundParameters.ContainsKey('ClientSecret')) { $argList += "--client-secret=$ClientSecret" }

    $output = & $PhpExe @argList
    $exitCode = $LASTEXITCODE
    $rawText = ($output -join "`n")
    $json = $null
    try { $json = $rawText | ConvertFrom-Json } catch { }
    return @{ ExitCode = $exitCode; Json = $json; Raw = $rawText }
}

# Fázis 6, Rész B — PURE, mellékhatás-mentes döntési logika: kínálható-e
# fel/futtatható-e az Ollama-telepítés EBBEN a szerepkörben/kérésben.
# Admin-jog/hálózat/tényleges telepítés NÉLKÜL tesztelhető (a kör 25.
# pontja). Kliens node-on az Ollama SOSE kínálható fel, MÉG akkor sem, ha
# a hívó (tévedésből) -InstallOllama-t adott meg — ez STRUKTURÁLIS
# biztosíték, nem csak egy figyelmen kívül hagyható figyelmeztetés.
function Resolve-OllamaProvisionDecision {
    param(
        [Parameter(Mandatory)][ValidateSet('standalone', 'server', 'client')][string]$NodeRole,
        [bool]$InstallRequested = $false,
        [bool]$SkipRequested = $false
    )
    if ($NodeRole -eq 'client') {
        return @{ ShouldOffer = $false; ShouldInstall = $false; Reason = 'Kliens node — az Ollama nem elérhető/nem kínálható fel ezen a node-on.' }
    }
    if ($SkipRequested) {
        return @{ ShouldOffer = $true; ShouldInstall = $false; Reason = 'A telepítő kifejezetten kihagyta az Ollama-telepítést (-SkipOllama).' }
    }
    if ($InstallRequested) {
        return @{ ShouldOffer = $true; ShouldInstall = $true; Reason = 'A telepítő explicit kérte az Ollama-telepítést (-InstallOllama).' }
    }
    return @{ ShouldOffer = $true; ShouldInstall = $false; Reason = 'Nincs explicit kérés — alapból NEM települ csendben (interaktív módban a szkript rákérdez).' }
}

# A tools/ollama-provision-cli.php CLI-eszköz vékony, argumentum-építő +
# JSON-parszoló hívóburka — UGYANAZ a minta, mint Invoke-FountainTradeTopologyTool
# fent: a tényleges logikát (letöltés+SHA-256-ellenőrzés+csendes futtatás,
# lásd OllamaProvisioner.php) a MEGLÉVŐ PHP-osztály végzi, ez a függvény
# csak meghívja és értelmezi a JSON-kimenetet.
function Invoke-FountainTradeOllamaProvisionTool {
    param(
        [Parameter(Mandatory)][string]$PhpExe,
        [Parameter(Mandatory)][string]$ToolPath,
        [Parameter(Mandatory)][ValidateSet('status', 'install', 'pull-model')][string]$Action,
        [string]$BaseUrl,
        [string]$Model
    )
    $argList = @($ToolPath, "--action=$Action")
    if ($BaseUrl) { $argList += "--base-url=$BaseUrl" }
    if ($Model) { $argList += "--model=$Model" }

    $output = & $PhpExe @argList
    $exitCode = $LASTEXITCODE
    $rawText = ($output -join "`n")
    $json = $null
    try { $json = $rawText | ConvertFrom-Json } catch { }
    return @{ ExitCode = $exitCode; Json = $json; Raw = $rawText }
}

# Pure — a kör 15./21. pontja kutatásának RÖGZÍTETT eredménye: az Ollama
# hivatalos Windows-telepítője (Inno Setup, app/ollama.iss a forrásban:
# "PrivilegesRequired=lowest") NEM igényel Rendszergazdai/UAC-jogosultságot
# — a saját felhasználói fiók %LOCALAPPDATA%-jába települ. EZ a függvény
# teszi ezt a döntést EXPLICITTÉ és tesztelhetővé (a kör 25. pontja "UAC/
# elevation decision"), sose implicit/hallgatólagos feltételezés.
function Test-OllamaInstallerRequiresElevation {
    return $false
}

# Pure — eldönti, hogy egy MÁR lekérdezett Ollama-állapot alapján kell-e
# egyáltalán telepítést kísérelni (idempotencia — a kör 25. pontja
# "already-installed detection"/"idempotent re-run"). $StatusJson egy
# Invoke-FountainTradeOllamaProvisionTool -Action 'status' válasz .Json
# mezője (vagy egy azzal egyező alakú objektum/hashtable).
function Test-ShouldSkipOllamaInstall {
    param($StatusJson)
    if ($null -eq $StatusJson) { return $false }
    return [bool]($StatusJson.ok) -and [bool]($StatusJson.installed)
}

# Pure — eldönti, hogy egy VISSZAOLVASOTT tűzfalszabály (LocalPort/Protocol/
# RemoteAddress/Enabled, ahogy egy Get-NetFirewallRule + Get-
# NetFirewallPortFilter/Get-NetFirewallAddressFilter páros visszaadná) a
# Fázis 2 Szerver-mód elvárt alakjának felel-e meg — KÜLÖN a tényleges
# New-NetFirewallRule/Get-NetFirewallRule hívásoktól (ADMIN jogot igényelnek,
# lásd install-windows.ps1), hogy a döntési logika admin nélkül, tisztán
# tesztelhető legyen.
function Test-FirewallRuleMatchesExpected {
    param(
        [string]$LocalPort,
        [string]$Protocol,
        [object]$RemoteAddress,
        [string]$Enabled,
        [Parameter(Mandatory)][int]$ExpectedPort
    )
    if ($Enabled -ne 'True') {
        return @{ Ok = $false; Reason = 'A szabály le van tiltva (Enabled != True).' }
    }
    if ($Protocol -ne 'TCP') {
        return @{ Ok = $false; Reason = "A protokoll eltér a várttól (talált: '$Protocol', várt: 'TCP')." }
    }
    if ($LocalPort -ne "$ExpectedPort") {
        return @{ Ok = $false; Reason = "A port eltér a várttól (talált: '$LocalPort', várt: '$ExpectedPort')." }
    }
    if ($RemoteAddress -notcontains 'LocalSubnet') {
        return @{ Ok = $false; Reason = "A távoli cím nincs LocalSubnetre korlátozva (talált: '$($RemoteAddress -join ', ')')." }
    }
    return @{ Ok = $true; Reason = '' }
}

# Pure — a node-szerepkör alapján eldönti, mire kösse a beépített PHP
# szervert. Szerver: 0.0.0.0 (minden interfész — DHCP miatt a LAN-IP később
# változhat, lásd a kör 3. pontja). Önálló/Kliens: localhost (SOSE nyílik
# hálózati port, ha nincs rá szükség).
function Get-FountainTradeBindHost {
    param([Parameter(Mandatory)][string]$NodeRole)
    if ($NodeRole -eq 'server') { return '0.0.0.0' }
    return 'localhost'
}

# Pure — a telepítési könyvtár legkisebb jogosultságú ACL-terve (icacls
# argumentumlisták, sorrendben). A PHP szerver és a cron-feladatok a
# telepítő fiókjával ($OwnerSid) futnak (Register-ScheduledTask explicit
# principal nélkül) — egyedül ez a fiók (+ SYSTEM/Administrators) írhat.
# A BUILTIN\Users (pl. pénztáros Windows-fiókok) a kódot csak olvashatja/
# futtathatja, a config/ (kliens-titok), data/ (adatbázis, API-kulcsok,
# mentés-kulcs) és invoices/ (vevőadatok) mappákhoz pedig NINCS hozzáférése.
# A ProgramData-tól öröklött "Users: fájl létrehozása" jog is megszűnik
# (/inheritance:r), így új fájl (pl. egy .php) sem hozható létre a webroot-ban.
# Idempotens: /reset /T minden korábbi explicit bejegyzést (pl. a régebbi
# telepítők rekurzív "Users: Modify" jogát) eltávolít, mielőtt a terv újra
# felépül.
function Get-FountainTradeAclPlan {
    param(
        [Parameter(Mandatory)][string]$InstallPath,
        [Parameter(Mandatory)][string]$OwnerSid
    )
    $system = '*S-1-5-18'
    $admins = '*S-1-5-32-544'
    $users = '*S-1-5-32-545'
    $owner = "*$OwnerSid"

    $plan = @(
        @{ Path = $InstallPath; Arguments = @($InstallPath, '/reset', '/T', '/C', '/Q') },
        @{ Path = $InstallPath; Arguments = @($InstallPath, '/inheritance:r', '/grant:r', "${system}:(OI)(CI)F", "${admins}:(OI)(CI)F", "${owner}:(OI)(CI)M", "${users}:(OI)(CI)RX", '/C', '/Q') }
    )
    foreach ($sub in @('config', 'data', 'invoices')) {
        $subPath = Join-Path $InstallPath $sub
        $plan += @{ Path = $subPath; Arguments = @($subPath, '/inheritance:r', '/grant:r', "${system}:(OI)(CI)F", "${admins}:(OI)(CI)F", "${owner}:(OI)(CI)M", '/C', '/Q') }
    }
    return ,$plan
}

function Invoke-FountainTradeAclPlan {
    param([Parameter(Mandatory)][object[]]$Plan)
    foreach ($step in $Plan) {
        if (-not (Test-Path -LiteralPath $step.Path)) {
            New-Item -ItemType Directory -Force -Path $step.Path | Out-Null
        }
        $icaclsArgs = $step.Arguments
        & icacls @icaclsArgs | Out-Null
        if ($LASTEXITCODE -ne 0) {
            throw "icacls sikertelen: $($step.Path) (kilépési kód: $LASTEXITCODE)"
        }
    }
}

# Pure — Szerver szerepkörben a közvetlen (nem proxyzott) API-forgalom
# MINDIG hitelesítést igényel (Auth::isEnabled()), ezért egy jelszó nélküli
# Szerver felülete zárva maradna. Ez dönti el, mit tegyen a telepítő:
# 'skip' (nem Szerver), 'keep' (már van jelszó — idempotens újrafuttatás),
# 'use_env' (FOUNTAINTRADE_APP_PASSWORD), 'prompt' (interaktív bekérés),
# 'fail' (nem-interaktív, jelszó nélkül — sose telepítünk nyitott Szervert).
function Resolve-ServerAppPasswordAction {
    param(
        [Parameter(Mandatory)][ValidateSet('standalone', 'server', 'client')][string]$NodeRole,
        [bool]$HasPassword = $false,
        [bool]$EnvPasswordProvided = $false,
        [bool]$NonInteractive = $false
    )
    if ($NodeRole -ne 'server') { return 'skip' }
    if ($HasPassword) { return 'keep' }
    if ($EnvPasswordProvided) { return 'use_env' }
    if ($NonInteractive) { return 'fail' }
    return 'prompt'
}

# A jelszó KIZÁRÓLAG stdin-en megy a PHP-eszköznek (sose argumentumként —
# az a folyamatlistában látszana), a UTF-8 bájtjainak base64-ében: a
# Windows PowerShell 5.1 a natív programnak csövezett szöveget a konzol
# kódlapjára kódolja ($OutputEncoding-tól függetlenül — élő teszttel
# igazolva, az ékezetes betűk '?'-lé váltak), ami egy böngészőből soha
# nem egyező jelszó-hash-t eredményezne.
function Invoke-FountainTradeAppPasswordTool {
    param(
        [Parameter(Mandatory)][string]$PhpExe,
        [Parameter(Mandatory)][string]$ToolPath,
        [Parameter(Mandatory)][ValidateSet('status', 'set')][string]$Action,
        [string]$Password
    )
    if ($Action -eq 'set') {
        $encodedPassword = [Convert]::ToBase64String([System.Text.Encoding]::UTF8.GetBytes($Password))
        $output = $encodedPassword | & $PhpExe $ToolPath "--action=$Action" '--stdin=base64'
    } else {
        $output = & $PhpExe $ToolPath "--action=$Action"
    }
    $exitCode = $LASTEXITCODE
    $rawText = ($output -join "`n")
    $json = $null
    try { $json = $rawText | ConvertFrom-Json } catch { }
    return @{ ExitCode = $exitCode; Json = $json; Raw = $rawText }
}
