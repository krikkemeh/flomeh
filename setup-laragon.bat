@echo off
setlocal EnableExtensions EnableDelayedExpansion

set "ROOT=%~dp0"
set "BACKEND=%ROOT%backend"
set "DEFAULT_DB=flox"
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
composer install --no-dev --optimize-autoloader
if errorlevel 1 goto failed

findstr /R /C:"^APP_KEY=$" ".env" >nul 2>nul
if not errorlevel 1 (
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
echo   1. Apri backend\.env e imposta TMDB_API_KEY.
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

for /f "usebackq delims=" %%U in (`php -r "$url = getenv('APP_URL_INPUT'); $path = parse_url($url, PHP_URL_PATH); echo $path ? rtrim($path, '/') : '/';"`) do set "CLIENT_URI_INPUT=%%U"
if "%CLIENT_URI_INPUT%"=="" set "CLIENT_URI_INPUT=/"

php -r "$file = '%BACKEND%\\.env'; $app = getenv('APP_URL_INPUT'); $client = getenv('CLIENT_URI_INPUT'); $env = file_get_contents($file); $env = preg_replace('/^APP_URL=.*/m', 'APP_URL=' . $app, $env); $env = preg_replace('/^CLIENT_URI=.*/m', 'CLIENT_URI=' . $client, $env); file_put_contents($file, $env);"
if errorlevel 1 exit /b 1

echo Uso APP_URL=%APP_URL_INPUT%
echo Uso CLIENT_URI=%CLIENT_URI_INPUT%
exit /b 0

:find_php
where php >nul 2>nul
if not errorlevel 1 (
  goto php_found
)

for /d %%P in ("C:\laragon\bin\php\php-7*") do (
  if exist "%%~fP\php.exe" set "PHP_DIR=%%~fP"
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

for /d %%M in ("C:\laragon\bin\mysql\mysql-*") do (
  if exist "%%~fM\bin\mysql.exe" set "MYSQL_DIR=%%~fM\bin"
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
