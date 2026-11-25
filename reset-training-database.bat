@echo off
echo ======================================================================
echo   RESET TRAINING DATABASE TO CLEAN INITIAL STATE
echo ======================================================================
echo.
copy /Y "%~dp0database\training_portal_reset_backup.sqlite" "%~dp0database\training_portal.sqlite"
echo.
echo [DONE] Training database has been restored to its clean initial state!
pause
