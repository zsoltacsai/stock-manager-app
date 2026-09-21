@echo off
setlocal EnableExtensions
title FountainTrade Telepito

rem ============================================================
rem FountainTrade Windows Telepito - minimalis inditowrapper.
rem
rem Ez a fajl NEM tartalmaz semmilyen titkot, cron-tokent, API-
rem kulcsot vagy jelszot - kizarolag azt vegzi el, hogy a tenyleges
rem telepitesi logikat vegzo install-windows.ps1 rendszergazdai
rem jogosultsaggal, a Windows globalis Execution Policy-jenek
rem tartos modositasa nelkul elinduljon.
rem ============================================================

rem --- 1) Windows-ellenorzes ---
ver >nul 2>&1
if errorlevel 1 (
    echo [HIBA] Ez a telepito csak Windows alatt fut.
    pause
    exit /b 1
)

rem --- 2) PowerShell elerhetoseg-ellenorzes ---
where powershell >nul 2>&1
if errorlevel 1 (
    echo [HIBA] A PowerShell nem talalhato ezen a gepen.
    echo A FountainTrade telepitesehez Windows PowerShell 5.1 vagy ujabb szukseges
    echo ^(ez alapbol resze minden tamogatott Windows 10/11 telepitesnek^).
    pause
    exit /b 1
)

rem --- 3) Rendszergazdai jog ellenorzese, szukseg eseten UAC-emeles ---
net session >nul 2>&1
if not errorlevel 1 goto :run

echo.
echo Rendszergazdai jogosultsag szukseges a telepiteshez.
echo Elfogadd a most megjeleno UAC-ablakot a folytatashoz...
echo.
powershell -NoProfile -ExecutionPolicy Bypass -Command "Start-Process -FilePath '%~f0' -WorkingDirectory '%~dp0' -Verb RunAs"
exit /b 0

:run
cd /d "%~dp0"

if not exist "%~dp0install-windows.ps1" (
    echo [HIBA] Nem talalhato az install-windows.ps1 fajl ugyanabban a mappaban, mint ez a fajl.
    echo Ellenorizd, hogy a teljes FountainTrade-Installer mappat masoltad-e at, nem csak ezt a fajlt onmagaban.
    pause
    exit /b 1
)

echo.
echo FountainTrade telepito inditasa...
echo Ez nehany percig eltarthat, kulonosen ha a PHP-t is most kell letoltenie.
echo.

rem A -ExecutionPolicy Bypass KIZAROLAG erre az egy folyamatra vonatkozik -
rem a Windows globalis Execution Policy-je valtozatlan marad.
powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install-windows.ps1"
set PS_EXIT=%errorlevel%

rem A telepito sajat maga mar varakozik billentyulenyomasra hiba eseten
rem (lasd install-windows.ps1 Exit-WithFailureSummary fuggvenye) - itt
rem nem duplikaljuk a varakozast, csak jelezzuk a vegeredmenyt.
if not %PS_EXIT%==0 (
    echo.
    echo [HIBA] A telepito hibaval lepett ki ^(kilepesi kod: %PS_EXIT%^).
)

exit /b %PS_EXIT%
