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

rem --- 3) Rendszergazdai jog / UAC-emeles ---
rem Az UAC-emelest MAGA az install-windows.ps1 vegzi el (lasd a szkript
rem sajat "0. Self-elevation" szakasza) - PowerShell tomb-alapu
rem -ArgumentList hasznalataval, ami megbizhatobb, mint egy kezzel
rem osszerakott, tobb reteg idezojelet tartalmazo string (bat -> powershell
rem -Command -> ujabb Start-Process -Verb RunAs). EZ a fajl emiatt
rem SZANDEKOSAN nem probal maga elolegesen emelni - csak egyszeruen
rem elinditja a szkriptet, ami majd sajat magat UAC-on keresztul
rem ujraindítja, ha szukseges, es MEGVARJA, amig az az ELEVALT peldany
rem befejezodik, mielott ez az ablak bezarna (lasd lent).
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

if not %PS_EXIT%==0 (
    echo.
    echo [HIBA] A telepito hibaval lepett ki ^(kilepesi kod: %PS_EXIT%^).
) else (
    echo.
    echo Kesz. Ha a bongeszo nem nyilt meg magatol, nyisd meg kezzel: http://localhost:8000/
)

echo.
pause
exit /b %PS_EXIT%
