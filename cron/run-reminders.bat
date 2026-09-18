@echo off
REM Run every 5 minutes via Windows Task Scheduler so visit/appointment email reminders are sent on time.
"C:\xampp\php\php.exe" "%~dp0send_appointment_reminders.php"
