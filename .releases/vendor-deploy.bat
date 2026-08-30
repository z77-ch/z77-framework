@echo off
REM Double-click wrapper for .releases\vendor-deploy.php (see that file).
cd /d "%~dp0.."
echo.
echo   Rebuilds vendor\ with REAL copies of every path-repo package,
echo   production autoload, build stamp. composer.json stays untouched.
echo.
choice /C YN /M "Build deployable vendor"
if errorlevel 2 goto :eof
php ".releases\vendor-deploy.php"
echo.
pause
