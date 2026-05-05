@echo off
setlocal EnableExtensions EnableDelayedExpansion

set "ROOT=%~dp0"
set "BACKEND=%ROOT%backend"
for %%I in ("%ROOT%..\..") do set "LARAGON_ROOT=%%~fI"
set "DEFAULT_DB=flomeh"
set "DEFAULT_URL=http://localhost/flomeh"
set "DEFAULT_USER=admin"
set "DEFAULT_PASS=admin"
set "FIRST_INSTALL=0"

echo.
echo Flox / Flomeh - setup per Laragon
echo ==================================
echo.

if not exist "%BACKEND%\artisan" (
  echo Cartella backend non trovata. Esegui questo file dalla root del progetto.
  exit /b 1
)

call :find_php
if errorlevel 1 exit /b 1

call :init_defaults
if errorlevel 1 exit /b 1

call :find_composer
if errorlevel 1 exit /b 1

call :find_mysql

if not exist "%BACKEND%\.env" (
  if not exist "%BACKEND%\.env.laragon.example" (
    echo File backend\.env.laragon.example non trovato.
    exit /b 1
  )

  copy "%BACKEND%\.env.laragon.example" "%BACKEND%\.env" >nul
  set "FIRST_INSTALL=1"
  echo Creato backend\.env da backend\.env.laragon.example
) else (
  echo backend\.env esiste gia: lo lascio invariato.
)

call :configure_url
if errorlevel 1 exit /b 1
call :configure_db
if errorlevel 1 exit /b 1
call :configure_tmdb_key
if errorlevel 1 exit /b 1
call :ensure_dirs

if defined MYSQL_FOUND (
  echo.
  echo Creo il database MySQL "%DEFAULT_DB%" se non esiste...
  mysql -uroot -e "CREATE DATABASE IF NOT EXISTS %DEFAULT_DB% CHARACTER SET utf8 COLLATE utf8_unicode_ci;"
  if errorlevel 1 (
    echo Non sono riuscito a creare il database con root senza password.
    echo Se Laragon usa credenziali diverse, crea il database a mano e aggiorna backend\.env.
  )
) else (
  echo.
  echo mysql.exe non trovato nel PATH. Crea il database "%DEFAULT_DB%" a mano se non esiste.
)

pushd "%BACKEND%"

echo.
echo Installo le dipendenze PHP...
call composer install --no-dev --optimize-autoloader
if errorlevel 1 goto failed

set "APP_KEY_VALUE="
for /f "tokens=1,* delims==" %%A in ('findstr /B "APP_KEY=" ".env"') do set "APP_KEY_VALUE=%%B"
if not defined APP_KEY_VALUE (
  echo.
  echo Genero la chiave applicazione...
  php artisan key:generate --force
  if errorlevel 1 goto failed
) else (
  echo APP_KEY gia presente: non la rigenero.
)

echo.
echo Pulisco la cache di configurazione...
php artisan config:clear
if errorlevel 1 goto failed

if "%FIRST_INSTALL%"=="1" (
  echo.
  echo Creo/aggiorno le tabelle e l'utente iniziale %DEFAULT_USER% / %DEFAULT_PASS%...
  php artisan flox:db %DEFAULT_USER% %DEFAULT_PASS%
  if errorlevel 1 goto failed
) else (
  echo.
  echo Aggiorno le tabelle esistenti...
  php artisan migrate --force
  if errorlevel 1 goto failed
  echo Non creo un nuovo utente admin per evitare duplicati.
)

popd

echo.
echo Fatto.
echo.
echo Prossimi passi:
echo   1. Verifica backend\.env e aggiorna TMDB_API_KEY se necessario.
echo   2. Avvia Apache e MySQL da Laragon.
echo   3. Visita %DEFAULT_URL%
echo.
echo Login iniziale per nuove installazioni: %DEFAULT_USER% / %DEFAULT_PASS%
echo Cambia la password appena entri.
exit /b 0

:failed
popd
echo.
echo Setup interrotto. Controlla l'errore sopra e rilancia setup-laragon.bat.
exit /b 1

:configure_url
echo.
echo URL applicazione
echo Premi INVIO per usare il valore predefinito:
echo   %DEFAULT_URL%
set /p "APP_URL_INPUT=APP_URL: "
if "%APP_URL_INPUT%"=="" set "APP_URL_INPUT=%DEFAULT_URL%"
for /f "usebackq delims=" %%U in (`php -r "$value = trim(getenv('APP_URL_INPUT')); echo $value;"`) do set "APP_URL_INPUT=%%U"

for /f "usebackq delims=" %%U in (`php -r "$url = getenv('APP_URL_INPUT'); $path = parse_url($url, PHP_URL_PATH); echo $path ? rtrim($path, '/') : '/';"`) do set "CLIENT_URI_INPUT=%%U"
if "%CLIENT_URI_INPUT%"=="" set "CLIENT_URI_INPUT=/"

php -r "$file = '%BACKEND%\\.env'; $app = getenv('APP_URL_INPUT'); $client = getenv('CLIENT_URI_INPUT'); $env = file_get_contents($file); $env = preg_replace('/^APP_URL=.*/m', 'APP_URL=' . $app, $env); $env = preg_replace('/^CLIENT_URI=.*/m', 'CLIENT_URI=' . $client, $env); file_put_contents($file, $env);"
if errorlevel 1 exit /b 1

echo Uso APP_URL=%APP_URL_INPUT%
echo Uso CLIENT_URI=%CLIENT_URI_INPUT%
exit /b 0

:configure_db
echo.
echo Database MySQL
echo Premi INVIO per usare il valore predefinito:
echo   %DEFAULT_DB%
set /p "DB_NAME_INPUT=DB_DATABASE: "
if "%DB_NAME_INPUT%"=="" set "DB_NAME_INPUT=%DEFAULT_DB%"
for /f "usebackq delims=" %%D in (`php -r "$value = trim(getenv('DB_NAME_INPUT')); echo $value;"`) do set "DB_NAME_INPUT=%%D"
set "DEFAULT_DB=%DB_NAME_INPUT%"

php -r "$file = '%BACKEND%\\.env'; $db = getenv('DB_NAME_INPUT'); $env = file_get_contents($file); $env = preg_replace('/^DB_DATABASE=.*/m', 'DB_DATABASE=' . $db, $env); file_put_contents($file, $env);"
if errorlevel 1 exit /b 1

echo Uso DB_DATABASE=%DEFAULT_DB%
exit /b 0

:configure_tmdb_key
set "TMDB_KEY_CURRENT="
for /f "tokens=1,* delims==" %%A in ('findstr /B "TMDB_API_KEY=" "%BACKEND%\.env"') do set "TMDB_KEY_CURRENT=%%B"

if defined TMDB_KEY_CURRENT (
  echo.
  echo TMDB_API_KEY gia presente: la lascio invariata.
  exit /b 0
)

echo.
echo TMDB API Key
echo Inserisci una chiave TMDB valida per completare migrazioni e generi iniziali.
set /p "TMDB_KEY_INPUT=TMDB_API_KEY: "
for /f "usebackq delims=" %%K in (`php -r "$value = trim(getenv('TMDB_KEY_INPUT')); echo $value;"`) do set "TMDB_KEY_INPUT=%%K"

if "%TMDB_KEY_INPUT%"=="" (
  echo TMDB_API_KEY obbligatoria. Rilancia lo script e inseriscila quando richiesto.
  exit /b 1
)

php -r "$file = '%BACKEND%\\.env'; $key = getenv('TMDB_KEY_INPUT'); $env = file_get_contents($file); $env = preg_replace('/^TMDB_API_KEY=.*/m', 'TMDB_API_KEY=' . $key, $env); file_put_contents($file, $env);"
if errorlevel 1 exit /b 1

echo TMDB_API_KEY salvata.
exit /b 0

:init_defaults
for /f "usebackq delims=" %%D in (`php -r "$path = getenv('ROOT'); $name = basename(rtrim($path, '\\\\/')); $safe = preg_replace('/[^A-Za-z0-9_]+/', '_', $name); echo strtolower($safe ?: 'flomeh');"`) do set "ROOT_NAME=%%D"
if not defined ROOT_NAME set "ROOT_NAME=flomeh"
set "DEFAULT_DB=%ROOT_NAME%"
set "DEFAULT_URL=http://localhost/%ROOT_NAME%"
exit /b 0

:find_php
where php >nul 2>nul
if not errorlevel 1 (
  goto php_found
)

for /d %%P in ("%LARAGON_ROOT%\bin\php\php-7*") do (
  if exist "%%~fP\php.exe" set "PHP_DIR=%%~fP"
)

if not defined PHP_DIR (
  for /d %%P in ("C:\laragon\bin\php\php-7*") do (
    if exist "%%~fP\php.exe" set "PHP_DIR=%%~fP"
  )
)

if defined PHP_DIR set "PATH=%PHP_DIR%;%PATH%"

where php >nul 2>nul
if errorlevel 1 (
  echo PHP non trovato nel PATH.
  echo Apri Laragon, seleziona PHP 7.2, 7.3 o 7.4 e riprova dal Terminal di Laragon.
  exit /b 1
)

:php_found
php -r "exit(PHP_MAJOR_VERSION === 7 && PHP_MINOR_VERSION >= 2 ? 0 : 1);"
if errorlevel 1 (
  php -v
  echo.
  echo Versione PHP non supportata. Usa PHP 7.2, 7.3 o 7.4: Laravel 6/Flox non e' pensato per PHP 8.
  exit /b 1
)

echo PHP trovato:
php -v
exit /b 0

:find_composer
where composer >nul 2>nul
if errorlevel 1 (
  if exist "%LARAGON_ROOT%\bin\composer\composer.bat" set "PATH=%LARAGON_ROOT%\bin\composer;%PATH%"
)

where composer >nul 2>nul
if errorlevel 1 (
  echo Composer non trovato nel PATH.
  echo Installa Composer oppure apri il Terminal di Laragon se lo include gia.
  exit /b 1
)

echo Composer trovato.
exit /b 0

:find_mysql
where mysql >nul 2>nul
if not errorlevel 1 (
  set "MYSQL_FOUND=1"
  exit /b 0
)

for /d %%M in ("%LARAGON_ROOT%\bin\mysql\mysql-*") do (
  if exist "%%~fM\bin\mysql.exe" set "MYSQL_DIR=%%~fM\bin"
)

if not defined MYSQL_DIR (
  for /d %%M in ("C:\laragon\bin\mysql\mysql-*") do (
    if exist "%%~fM\bin\mysql.exe" set "MYSQL_DIR=%%~fM\bin"
  )
)

if defined MYSQL_DIR set "PATH=%MYSQL_DIR%;%PATH%"

where mysql >nul 2>nul
if not errorlevel 1 set "MYSQL_FOUND=1"
exit /b 0

:ensure_dirs
if not exist "%ROOT%public\assets\poster" mkdir "%ROOT%public\assets\poster"
if not exist "%ROOT%public\assets\poster\subpage" mkdir "%ROOT%public\assets\poster\subpage"
if not exist "%ROOT%public\assets\backdrop" mkdir "%ROOT%public\assets\backdrop"
if not exist "%ROOT%public\exports" mkdir "%ROOT%public\exports"
if not exist "%BACKEND%\storage\framework\cache" mkdir "%BACKEND%\storage\framework\cache"
if not exist "%BACKEND%\storage\framework\sessions" mkdir "%BACKEND%\storage\framework\sessions"
if not exist "%BACKEND%\storage\framework\views" mkdir "%BACKEND%\storage\framework\views"
if not exist "%BACKEND%\storage\logs" mkdir "%BACKEND%\storage\logs"
exit /b 0
