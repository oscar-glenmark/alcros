@echo off
setlocal
cd /d "%~dp0.."

if exist "C:\xampp\php\php.exe" (
    set "PHP_BIN=C:\xampp\php\php.exe"
) else (
    set "PHP_BIN=php"
)

"%PHP_BIN%" "%~dp0backup-run.php"
exit /b %ERRORLEVEL%
