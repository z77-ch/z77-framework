@echo off
cd /d "%~dp0"
composer install
start "z77 Dev Server" cmd /k php -S 127.0.0.1:8080 -t public
timeout /t 2 /nobreak > nul
start "" "http://127.0.0.1:8080"
