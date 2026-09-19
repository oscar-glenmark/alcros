@echo off
REM Run this in Duplicati: Job -> Options -> "Run script before" -> path to this file.
call "%~dp0backup-run.bat"
exit /b %ERRORLEVEL%
