@echo off
title Disaster Management System Launcher
echo ====================================================
echo   Starting Disaster Management System...
echo ====================================================
echo.

:: Check if MySQL is already running
tasklist /FI "IMAGENAME eq mysqld.exe" 2>NUL | find /I /N "mysqld.exe" >NUL
if "%ERRORLEVEL%"=="0" (
    echo [OK] MySQL is already running.
) else (
    echo [*] Starting MySQL service...
    start "" /B "C:\xampp\mysql\bin\mysqld.exe" --defaults-file="C:\xampp\mysql\bin\my.ini"
    timeout /t 2 /nobreak >NUL
    echo [OK] MySQL started.
)

:: Open the web browser to the login page
echo [*] Opening portal in browser...
start http://localhost:8000/login.php

:: Start the PHP Web Server
echo [*] Starting PHP web server on http://localhost:8000...
echo.
echo ====================================================
echo   Server is running! Keep this window open.
echo   To stop the server, press Ctrl + C.
echo ====================================================
echo.

"C:\xampp\php\php.exe" -S localhost:8000 -t "%~dp0disaster-management"
