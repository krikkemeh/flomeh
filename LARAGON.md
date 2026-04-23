# Laragon

Questa copia di Flomeh e' pronta per Laragon su Windows. L'obiettivo e' avviare il progetto dalla root del repository, senza spostare manualmente file dentro `public`.

## Requisiti

- Laragon con Apache e MySQL.
- PHP 7.2, 7.3 o 7.4 selezionato in Laragon.
- Composer disponibile nel Terminal di Laragon.
- Una chiave TMDB gratuita: https://www.themoviedb.org/settings/api

Flox usa Laravel 6: evita PHP 8 per questa installazione.

## Installazione

1. Metti il repository nella root web di Laragon, per esempio:

```bat
C:\laragon\www\flomeh
```

2. Avvia Laragon.

3. Apri il Terminal di Laragon nella cartella del progetto.

4. Esegui:

```bat
setup-laragon.bat
```

Lo script esegue le operazioni principali:

- trova PHP 7 e Composer;
- crea `backend\.env` da `backend\.env.laragon.example` se non esiste;
- chiede l'URL dell'app e aggiorna `APP_URL`/`CLIENT_URI`;
- crea le cartelle locali usate da cache, poster, backdrop ed export;
- prova a creare il database MySQL `flox` con `root` senza password;
- installa le dipendenze PHP con Composer;
- genera `APP_KEY` solo se e' vuota;
- su una nuova installazione esegue migrazioni e crea l'utente `admin / admin`;
- su un progetto gia' configurato esegue solo le migrazioni, per evitare utenti duplicati.

5. Apri `backend\.env` e inserisci la tua chiave TMDB:

```env
TMDB_API_KEY=la_tua_chiave
```

6. Visita:

```text
http://localhost/flomeh
```

Login iniziale:

```text
admin / admin
```

Cambia la password appena entri.

## Database

Lo script usa i valori pensati per Laragon:

```env
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=flox
DB_USERNAME=root
DB_PASSWORD=
```

Se MySQL usa credenziali diverse, crea il database manualmente e aggiorna `backend\.env`, poi rilancia:

```bat
setup-laragon.bat
```

## URL o porta diversa

Lo script propone `http://localhost/flomeh`, ma puoi inserire un URL diverso quando viene richiesto. Per esempio:

```env
APP_URL=http://localhost:8080/flomeh
CLIENT_URI=/flomeh
```

Se modifichi questi valori a mano, poi pulisci la configurazione:

```bat
cd backend
php artisan config:clear
```

## Worker

Import, refresh e aggiornamenti automatici richiedono il worker della queue:

```bat
cd backend
php artisan queue:work --tries=3
```

Lascia aperta quella finestra mentre vuoi processare import e refresh.

## File locali esclusi dal repository

Questi dati sono locali e non devono essere pubblicati:

- `backend\.env`
- `backend\vendor`
- cache e log in `backend\storage`
- cache in `backend\bootstrap\cache`
- poster e backdrop scaricati in `public\assets`
- export personali in `public\exports`

Il repository mantiene solo i placeholder `.gitkeep` dove servono, cosi' le cartelle esistono ma i dati personali restano fuori.
