@echo off
rem ValueLens starten (Windows). Voraussetzung: PHP 8 - einmalig installieren mit:  winget install PHP.PHP.8.4
rem Startet einen Server nur fuer diesen Rechner (127.0.0.1) und oeffnet den Browser. Beenden: dieses Fenster schliessen.
setlocal
cd /d "%~dp0"
rem Vom Update abgelegte neue Startdatei uebernehmen (Block wird vor der Ausfuehrung komplett gelesen)
if exist start.bat.neu (move /y start.bat.neu start.bat >nul & start "" "%~dp0start.bat" & exit /b)
where php >nul 2>nul
if errorlevel 1 (
  echo.
  echo PHP wurde nicht gefunden. Bitte einmalig installieren:
  echo     winget install PHP.PHP.8.4
  echo Danach dieses Fenster schliessen und start.bat erneut oeffnen.
  pause
  exit /b 1
)
for /f "delims=" %%i in ('where php') do (set "PHPEXE=%%i" & goto :gefunden)
:gefunden
for %%i in ("%PHPEXE%") do set "PHPDIR=%%~dpi"
if not exist data mkdir data
rem Windows-PHP kennt keine Zertifikate: Stammzertifikate aus Windows exportieren (kein Download)
rem PSModulePath leeren: aus PowerShell 7 gestartet laedt Windows PowerShell sonst das Zertifikat-Laufwerk nicht
set "PSModulePath="
powershell -NoProfile -ExecutionPolicy Bypass -Command "$sb=New-Object Text.StringBuilder; Get-ChildItem Cert:\LocalMachine\Root,Cert:\CurrentUser\Root | Sort-Object Thumbprint -Unique | ForEach-Object { [void]$sb.AppendLine('-----BEGIN CERTIFICATE-----'); [void]$sb.AppendLine([Convert]::ToBase64String($_.RawData,'InsertLineBreaks')); [void]$sb.AppendLine('-----END CERTIFICATE-----') }; [IO.File]::WriteAllText((Join-Path (Get-Location) 'data\cacert.pem'), $sb.ToString())"
set "CA=%CD%\data\cacert.pem"
for %%A in ("%CA%") do if %%~zA LSS 1000 echo WARNUNG: Zertifikate konnten nicht exportiert werden - Abrufe von Yahoo/SEC schlagen dann fehl.
rem Erweiterungen einschalten, falls PHP ohne php.ini installiert ist (winget)
rem Ohne php.ini: laengere Laufzeit (erster SEC-Abruf), Warnungen ins Protokoll statt in die Antwort
set OPT=-d curl.cainfo="%CA%" -d openssl.cafile="%CA%" -d max_execution_time=300 -d display_errors=0 -d log_errors=1 -d error_log="%CD%\data\php-fehler.log" -d memory_limit=512M
php -m | findstr /i /x "curl" >nul
if errorlevel 1 set OPT=-d extension_dir="%PHPDIR%ext" -d extension=curl -d extension=openssl -d extension=mbstring -d extension=zip %OPT%
if /i "%~1"=="pruefen" (php %OPT% pruefen.php & echo. & pause & exit /b)
if "%VALUELENS_PORT%"=="" set VALUELENS_PORT=8484
echo.
echo  ValueLens laeuft:  http://127.0.0.1:%VALUELENS_PORT%/
echo  Zum Beenden dieses Fenster schliessen.
echo.
if not "%VALUELENS_KEIN_BROWSER%"=="1" start "" cmd /c "timeout /t 2 >nul & start http://127.0.0.1:%VALUELENS_PORT%/"
php %OPT% -S 127.0.0.1:%VALUELENS_PORT% router.php
