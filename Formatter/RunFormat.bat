@echo off
setlocal enabledelayedexpansion

REM Log folder relative to this script's location
set "logdir=%~dp0logs"

REM Create the directory if it doesn't exist
if not exist "%logdir%" mkdir "%logdir%"

REM Get a locale-independent timestamp via PowerShell
for /f %%I in ('powershell -NoProfile -Command "Get-Date -Format yyyy-MM-dd_HH-mm-ss"') do set "timestamp=%%I"

set "logfile=%logdir%\log_%timestamp%.txt"

echo Running command, output will be saved to: %logfile%

REM ==== Replace the line below with your actual command ====
python .\format_docx_headings.py > "%logfile%" 2>&1

echo Done. Log saved as %logfile%
pause