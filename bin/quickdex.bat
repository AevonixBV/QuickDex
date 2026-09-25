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
rem `call php`, not a bare `php`: on a box where PHP is reached through a shim batch
rem file (Herd installs C:\Users\Game\.config\herd\bin\php.bat and puts it on PATH),
rem invoking it WITHOUT call makes cmd.exe transfer control to that batch file instead
rem of running it as a subprocess - a batch invoking a batch never returns. The index
rem was built correctly and then the next line here never ran, and neither did anything
rem left in the script that called quickdex: it died silently with exit code 0. That bit
rem KinetixSEO's scripts\setup-worktree.bat, where every step after the index build
rem (SSR port notice, memory junction, completion message) was skipped with nothing to
rem show for it. `call` also makes %ERRORLEVEL% below reflect php's real exit code
rem rather than whatever preceded it.
if "%~1" == "index" (
    call php "%~dp0index.php" %2 %3 %4
    exit /b %ERRORLEVEL%
)
if "%~1" == "build" (
    call php "%~dp0index.php" %2 %3 %4
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
rem call, for the same reason as the index branch above - without it cmd hands control
rem to Herd's php.bat and the endlocal/exit line below is never reached.
call php "%~dp0query.php" %*
rem %ERRORLEVEL% is expanded before endlocal runs (same-line parsing), so the
rem php exit code is captured correctly even though setlocal variables are discarded.
endlocal & exit /b %ERRORLEVEL%
