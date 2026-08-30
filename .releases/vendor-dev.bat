@echo off
REM Double-click wrapper for .releases\vendor-dev.php (see that file).
cd /d "%~dp0.."
echo.
echo   Restores vendor\ for development: path-repo packages as links
echo   (live edits) + dev dependencies, deploy stamp removed.
echo.
choice /C YN /M "Restore dev vendor"
if errorlevel 2 goto :eof
php ".releases\vendor-dev.php"
echo.
pause
