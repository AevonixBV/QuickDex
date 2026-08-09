@echo off
setlocal EnableDelayedExpansion

rem Index subcommand: quickdex index [root] [output.db] [--force]
rem NOTE: do NOT use "shift + %*" here. In cmd.exe, %* is a snapshot of the
rem original argument list and is never updated by shift, so "index"/"build"
rem would be forwarded to index.php as the root-directory argument.
rem Pass %2 %3 %4 directly instead — covers root/db in order plus a trailing
rem --force flag. Only supports --force appended after root/db, not mixed in
rem an arbitrary position (cmd.exe positional parsing has no clean way to scan
rem for a flag anywhere in the list the way the sh wrapper's "$@" passthrough can).
if "%~1" == "index" (
    php "%~dp0index.php" %2 %3 %4
    exit /b %ERRORLEVEL%
)
if "%~1" == "build" (
    php "%~dp0index.php" %2 %3 %4
    exit /b %ERRORLEVEL%
)

rem Walk up from CWD to find the nearest quickdex.db
set "SEARCH=%CD%"
:LOOP
if exist "!SEARCH!\quickdex.db" (
    set "QUICKDEX_DB=!SEARCH!\quickdex.db"
    goto :RUN
)
for %%P in ("!SEARCH!\..") do set "PARENT=%%~fP"
if "!PARENT!" == "!SEARCH!" goto :RUN
set "SEARCH=!PARENT!"
goto :LOOP

:RUN
php "%~dp0query.php" %*
rem %ERRORLEVEL% is expanded before endlocal runs (same-line parsing), so the
rem php exit code is captured correctly even though setlocal variables are discarded.
endlocal & exit /b %ERRORLEVEL%
