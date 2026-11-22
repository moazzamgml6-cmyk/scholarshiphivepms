@echo off
title Processing Portal - TRAINING ENVIRONMENT (Port 8080)
echo ======================================================================
echo   PROCESSING TEAM PORTAL - ISOLATED TRAINING ENVIRONMENT
echo ======================================================================
echo.
echo   Database : %~dp0database\training_portal.sqlite
echo   URL      : http://127.0.0.1:8080
echo.
echo   Default Training Login Credentials:
echo     Admin Portal   : http://127.0.0.1:8080/admin
echo                      Username: admin      Password: Password123!
echo.
echo     Officer Portal : http://127.0.0.1:8080/officer
echo                      Front End: nimra / mujassam     (Password: Password123!)
echo                      Back End : minahil / sehrish / farwa (Password: Password123!)
echo.
echo     Student Portal : http://127.0.0.1:8080/student
echo                      Username: sajjad.khan           (Password: Password123!)
echo ======================================================================
echo.

where php >nul 2>nul
if %ERRORLEVEL% EQU 0 (
    php -S 127.0.0.1:8080 -t "%~dp0portal" "%~dp0portal\router.php"
    goto :eof
)

if exist "C:\Users\hafiz\php\php.exe" (
    "C:\Users\hafiz\php\php.exe" -S 127.0.0.1:8080 -t "%~dp0portal" "%~dp0portal\router.php"
    goto :eof
)

echo [ERROR] PHP executable not found in PATH. Please install PHP 8.1+ or add php.exe to PATH.
pause
