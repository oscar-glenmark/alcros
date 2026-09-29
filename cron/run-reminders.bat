@echo off
REM Run every 5 minutes via Windows Task Scheduler so visit/appointment email reminders are sent on time.
set "PHP=C:\xampp\php\php.exe"
set "SCRIPT=%~dp0send_appointment_reminders.php"
set "LOG=%~dp0..\storage\cron_reminder.log"

if not exist "%~dp0..\storage\" mkdir "%~dp0..\storage"

echo ===== %date% %time% =====>> "%LOG%"
"%PHP%" "%SCRIPT%" >> "%LOG%" 2>&1
echo Exit code: %ERRORLEVEL%>> "%LOG%"
echo.>> "%LOG%"
