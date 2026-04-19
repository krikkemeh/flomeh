# Flox - Contesto Compatto Per AI

Questo file serve come contesto rapido da fornire a un'AI quando si vogliono chiedere modifiche al progetto Flox installato in locale con Laragon.

## Obiettivo Del Progetto

Flox e' una web app self-hosted per gestire film e serie TV:

- ricerca e import dei dati da TMDb;
- libreria personale di film e serie;
- tracciamento degli episodi visti;
- watchlist;
- backup/import/export;
- refresh dei metadati online.

Questa installazione e' stata adattata per funzionare facilmente in locale con Laragon, sotto una sottocartella:

```text
http://localhost:60888/flox/
```

Percorso locale principale:

```text
D:\Web\laragon\www\flox
```

Nota workspace: se l'AI parte da `D:\TestAI\floxmeh`, quella e' solo la cartella della workspace. Il progetto Flox reale e' il secondo folder della workspace, cioe' `D:\Web\laragon\www\flox`. In quel caso non cercare `flox/AI_CONTEXT.md` dentro `D:\TestAI\floxmeh`, perche' non esiste.

## Ambiente Locale

La app e' vecchia e richiede PHP 7.4, non PHP 8.x.

PHP usato:

```text
D:\Web\laragon\bin\php\php-7.4.33-Win32-vc15-x64\php.exe
```

Composer usato:

```text
Composer 1.10.27
```

Database:

```text
MySQL
Database: flox
Utente: root
Password: vuota
```

Comandi utili da eseguire dalla cartella `backend`:

```bat
php artisan cache:clear
php artisan route:clear
php artisan config:clear
php artisan key:generate --force
php artisan flox:db --fresh admin admin
```

Nota: `flox:db --fresh` cancella tutti i dati.

## Struttura Principale

```text
flox/
  backend/                Applicazione Laravel 6
    app/
      Http/Controllers/   Controller API e pagine
      Services/           Logica applicativa principale
      Jobs/               Job di import/refresh
      Item.php            Model principale film/serie
      Episode.php         Model episodi
    database/migrations/  Schema DB
    routes/web.php        Rotte Laravel
    .env                  Configurazione locale

  client/                 Sorgenti Vue/Sass originali
    app/components/       Componenti Vue
    app/store/            Vuex store
    resources/sass/       Stili sorgente

  public/                 Asset serviti dal browser
    assets/app.js         Bundle JS compilato
    assets/app.css        CSS compilato
    assets/img/           Immagini statiche
    assets/poster/        Poster scaricati
    assets/backdrop/      Backdrop scaricati

  index.php               Entry point adattato per Laragon/sottocartella
  .htaccess               Rewrite per Laragon
  LARAGON.md              Note di setup locale
  IMPORT_SCHEMA.md        Schema import minimale
```

## Punto Importante Sul Frontend

Il frontend e' scritto in Vue, ma al momento non si sta ricompilando con npm.

Quando si modifica il frontend bisogna aggiornare entrambi:

1. i sorgenti in `client/app` o `client/resources/sass`;
2. i file gia' compilati in `public/assets/app.js` e/o `public/assets/app.css`.

Se si modifica solo `client`, Laragon continuera' a servire il vecchio bundle in `public/assets` e nel browser non cambiera' nulla.

Controllo utile dopo modifiche a `public/assets/app.js`:

```bat
node --check D:\Web\laragon\www\flox\public\assets\app.js
```

## Configurazione Importante In `.env`

Valori rilevanti:

```env
APP_URL=http://localhost:60888/flox
DB_DATABASE=flox
DB_USERNAME=root
DB_PASSWORD=
QUEUE_DRIVER=sync
TMDB_API_KEY=...
```

La chiave TMDb da usare e' la API Key classica, non il Read Access Token.

`QUEUE_DRIVER=sync` significa che import e job vengono eseguiti durante la richiesta HTTP, non da un worker separato. Quindi la tabella `jobs` puo' rimanere vuota anche mentre un import e' in corso.

## Funzionalita' Gia' Personalizzate

### Setup Laragon

La app e' stata adattata per partire da:

```text
/flox/
```

e non dalla root del dominio.

Sono stati corretti vari problemi tipici:

- PHP 8 incompatibile;
- Composer 2 incompatibile con il vecchio lock;
- pagina bianca da path errati;
- cache Laravel da svuotare dopo modifiche alle rotte.

### Layout

Sono state ridotte header e footer.

Il tema superiore usa una sfumatura bordeaux/nera.

Il logo e' stato sostituito con un'immagine personalizzata e poi ingrandito.

La barra in alto contiene anche il link `Settings`.

### Home E Lista Titoli

La lista ora mostra tutti gli elementi di default.

Il vecchio `Load more` non viene piu' usato come paginazione principale.

E' stato aggiunto il controllo:

```text
Seen at the end
```

Quando attivo, film visti e serie completate vengono spostati in fondo e separati da:

```text
Seen or completed
```

Il separatore e la griglia sono stati corretti con una classe `item-grid`, per evitare problemi di impaginazione causati dai vecchi `float` e `nth-child`.

In fondo alla lista c'e' il pulsante:

```text
HIDE SEEN / SHOW SEEN
```

La home ora usa anche il flag persistente:

```text
items.watching_now
```

Di default la home carica solo gli elementi non completati con `watching_now = true`.
Gli elementi non completati ma non in corso e gli elementi visti/completati non vengono caricati subito: vengono richiesti solo dai pulsanti:

```text
SHOW NOT WATCHING / HIDE NOT WATCHING
SHOW SEEN / HIDE SEEN
```

Le sezioni visualizzate sono:

```text
Watching now
Not watching now
Seen or completed
```

Sulle card e' presente un piccolo pulsante con occhio in basso a sinistra. Per gli elementi `watching_now` resta visibile; per gli altri appare al passaggio del mouse. Il click chiama:

```text
PATCH /api/watching-now/{itemId}
```

Migration collegata:

```text
backend/database/migrations/2026_04_18_120000_add_watching_now_to_items_table.php
```

La migration inizializza i non-completi a `watching_now = false` e i completi a `watching_now = true`; la sezione completati ha comunque priorita' e rimane nascosta finche' non si preme `SHOW SEEN`.

Regola importante: quando una serie risulta completata dopo import, manual TMDb update, toggle episodio o toggle stagione, il backend imposta `watching_now = true`. Questo serve per farla tornare automaticamente nella sezione `Watching now` se in futuro TMDb aggiunge nuovi episodi. Le serie importate non completate restano invece `watching_now = false` e vanno in `Not watching now` finche' l'utente non clicca l'occhio.

### Gradimento / Own Rating

Il gradimento personale e' salvato nel campo `items.rating`.

Significato dei valori usati dall'interfaccia:

```text
0     gradimento non definito, mostrato come ?
1     positivo
2     intermedio
3     negativo
4     super positivo, mostrato con cuore
null  elemento non ancora aggiunto/votato
```

Il pulsante del rating sulle card deve ciclare fra cinque stati, dal migliore al non definito:

```text
4 -> 1 -> 2 -> 3 -> 0 -> 4
```

L'ordinamento `Own Rating` usa una graduatoria esplicita, non il valore numerico puro: `4` cuore, `1` positivo, `2` intermedio, `3` negativo, `0`/`?` in fondo. La logica e' in:

```text
backend/app/Services/Models/ItemService.php
```

Il ciclo del pulsante e' in:

```text
client/app/components/Rating.vue
public/assets/app.js
```

### Watchlist

La watchlist inizialmente risultava poco chiara. Sono stati aggiunti pulsanti testuali visibili sulle card:

```text
Add to Watchlist
Remove from Watchlist
```

La watchlist contiene elementi non ancora segnati come visti.

### Backup, Export, Import

Nella pagina Settings/Backup sono presenti:

- export backup completo;
- export CSV dei contenuti visti;
- import con sostituzione;
- import con aggiunta ai record esistenti.

Il CSV personalizzato esporta:

```text
titolo;stagione;episodio
```

Per i film esporta solo il titolo. Per le serie esporta solo l'ultimo episodio visto.

I bottoni di import/export sono stati allineati con spaziatura coerente.

### Import Minimale

L'import e' stato esteso per accettare dati ridotti e arricchirli online tramite TMDb.

Formato minimale atteso:

```json
[
  {
    "titolo": "The Boys",
    "tmdb_id": 76479,
    "stagione": 3,
    "episodio": 1,
    "seen_until": true
  },
  {
    "titolo": "Fight Club",
    "tmdb_id": 550
  }
]
```

Per una serie:

- `titolo` obbligatorio;
- `tmdb_id` obbligatorio;
- `stagione` opzionale, utile solo come dato di progresso/importazione;
- `episodio` opzionale, utile solo come dato di progresso/importazione;
- `seen_until: true` opzionale, necessario per segnare come visti tutti gli episodi fino a `stagione`/`episodio`.

Importante: `stagione`/`episodio` da soli non segnano piu' automaticamente gli episodi come visti. La marcatura progressiva avviene solo se e' presente un flag esplicito vero: `seen_until`, `mark_seen_until`, `visto_fino_a` o `visti_fino_a`.

Se una serie esiste gia' nel database con un progresso piu' avanzato, un import piu' vecchio non deve mai ridurre gli episodi visti. Esempio: se nel DB la serie e' vista fino all'episodio 13 e l'import indica `seen_until: true` fino all'episodio 10, il progresso resta fino al 13. La stessa regola vale per l'import di backup completo degli episodi: un episodio gia' `seen = true` non viene sovrascritto a `false` da un backup/import piu' vecchio.

Per un film:

- `titolo` obbligatorio;
- `tmdb_id` obbligatorio;
- niente `stagione`;
- niente `episodio`.

Se i dati TMDb non vengono caricati completamente, il sistema puo' creare dati minimi e permettere un refresh successivo.

Quando l'import minimale contiene un `tmdb_id`, il titolo ufficiale recuperato da TMDb deve prevalere sul titolo scritto nel JSON. Questo evita che un JSON con titolo sbagliato ma `tmdb_id` corretto sovrascriva il nome di un elemento gia' presente.

### Import with AI

In Settings > Backup e' stato aggiunto un blocco "Import with AI".

La pagina Settings mostra come prima tab `Backup`; le vecchie tab `Misc` e `API` sono state nascoste dalla navigazione. E' presente anche una tab `Error` che legge lo stato import da `/api/import-jobs/pending`, mostra messaggio, progresso, ultimo item, orari e link al log, e puo' cancellare l'errore con `PATCH /api/import-jobs/clear-error`.

Flusso:

1. L'utente incolla testo poco formattato.
2. Il frontend chiama `POST /api/import-ai-format`.
3. Il backend usa `App\Services\ImportAiFormatter` per inviare il testo al provider AI e chiedere un JSON minimale per FloMeh.
4. Il JSON normalizzato viene mostrato in una textarea modificabile.
5. L'utente puo' importarlo come add (`/api/import-add`) oppure come replace (`/api/import`).

Il prompt AI deve chiedere `seen_until: true` solo quando il testo sorgente dice esplicitamente che tutti gli episodi fino a quella stagione/episodio sono stati visti. La sola presenza di season/episode non deve implicare marcatura automatica.

Configurazione in `backend/.env`:

```env
HF_API_KEY=...
HF_MODEL=meta-llama/Llama-3.1-8B-Instruct:novita
```

Il token HF deve restare lato backend: non metterlo mai in `client/app` o nel bundle pubblico.

File importanti:

- `backend/app/Http/Controllers/ExportImportController.php`
- `backend/app/Services/ImportAiFormatter.php`
- `backend/routes/web.php`
- `client/app/components/Content/Settings/Backup.vue`
- `client/app/components/Content/Settings/Index.vue`
- `client/resources/sass/components/_content.scss`
- `public/assets/app.js`
- `public/assets/app.css`

### Performance Home

La home usa `ItemService::getWithPagination()` e carica gli item con `latestEpisode` e `episodesWithSrcCount`.

Per velocizzare il calcolo del prossimo episodio non visto e del conteggio episodi con file, sono stati aggiunti indici su `episodes` tramite migration:

```text
backend/database/migrations/2026_04_16_170000_add_home_query_indexes_to_episodes_table.php
```

Indici creati:

- `episodes_tmdb_seen_season_episode_idx` su `tmdb_id`, `seen`, `season_number`, `episode_number`;
- `episodes_tmdb_src_idx` su `tmdb_id`, `src(191)`.

In `App\Item::latestEpisode()` e' stato rimosso `latest()`: la relazione ora cerca il prossimo episodio non visto dopo l'ultimo episodio visto in ordine naturale `season_number`, `episode_number`, ignorando eventuali buchi precedenti.

La stessa logica e' condivisa dalla scope `App\Episode::afterLatestSeenProgress()` e usata anche da `EpisodeService::getAllByTmdbId()` per il next episode nella modale episodi.

Benchmark locale dopo indici e nuova logica: caricamento backend home circa 0,9s con 352 item e circa 12k episodi. Prima degli indici era circa 4,3s.

### Manual TMDb Update

Nella scheda film/serie, sotto il refresh, e' stato aggiunto:

```text
Manual TMDb update
```

Serve per correggere manualmente il TMDb ID.

Quando si aggiorna manualmente il TMDb ID di una serie, la logica deve preservare anche il progresso episodi gia' visti. Il codice deve controllare l'ultimo episodio visto prima del cambio e rimarcarlo dopo l'aggiornamento dati.

Il tasto `Edit` visibile sulla card in home/lista per gli elementi senza `tmdb_id` usa la stessa rotta del Manual TMDb update (`PATCH /api/manual-tmdb-update/{itemId}`): chiede il nuovo TMDb ID con un prompt e poi ricarica la pagina.

File importanti:

```text
backend/app/Http/Controllers/ItemController.php
backend/app/Services/Models/ItemService.php
client/app/components/Content/Subpage.vue
public/assets/app.js
```

## Pending Jobs / Attivita' In Background

Era stata aggiunta una voce:

```text
Pending jobs...
```

nel footer dentro:

```html
<div class="sub-links">
```

Il controllo originale guardava solo la tabella `jobs`, ma con:

```env
QUEUE_DRIVER=sync
```

non ci sono veri job persistenti in background.

Ora l'import web scrive anche un file di stato:

```text
backend/storage/app/import-status.json
```

con contenuto tipo:

```json
{
  "running": true,
  "started_at": "2026-04-16 10:30:00",
  "finished_at": null,
  "message": "Importing 12/80: The Simpsons",
  "current": 12,
  "total": 80,
  "failed": false
}
```

Il backend imposta `running=true` all'inizio dell'import e `running=false` alla fine. In caso di errore fatale prova a salvare `failed=true` tramite shutdown handler.

Il footer controlla lo stato al caricamento della pagina e poi fa polling della rotta:

```text
GET /api/import-jobs/pending
```

che ora restituisce sia il vecchio conteggio dei job sia lo stato letto da `import-status.json`.

Comportamento del footer:

```text
Pending jobs...        se c'e' un import/job ancora attivo
Error: log             se l'ultimo import e' fallito
```

Il link `log` punta a una rotta autenticata che legge il log Laravel relativo:

```text
GET /api/import-jobs/log/{YYYY-MM-DD}
```

File coinvolti:

```text
backend/app/Http/Controllers/ExportImportController.php
backend/routes/web.php
client/app/components/Footer.vue
public/assets/app.js
```

## File Da Controllare Spesso

Backend:

```text
backend/routes/web.php
backend/app/Http/Controllers/ExportImportController.php
backend/app/Http/Controllers/ItemController.php
backend/app/Services/Models/ItemService.php
backend/app/Services/Models/EpisodeService.php
backend/app/Jobs/
backend/storage/logs/laravel.log
```

Frontend sorgente:

```text
client/app/components/Header.vue
client/app/components/Footer.vue
client/app/components/Rating.vue
client/app/components/Content/Content.vue
client/app/components/Content/Item.vue
client/app/components/Content/Subpage.vue
client/app/components/Content/Settings/Backup.vue
client/resources/sass/components/_content.scss
client/resources/sass/components/_header.scss
client/resources/sass/components/_footer.scss
```

Frontend servito davvero da Laragon:

```text
public/assets/app.js
public/assets/app.css
```

## Verifiche Utili

Sintassi PHP:

```bat
php -l backend\app\Http\Controllers\ExportImportController.php
php -l backend\app\Http\Controllers\ItemController.php
php -l backend\app\Services\Models\ItemService.php
```

Pulizia cache Laravel:

```bat
php backend\artisan route:clear
php backend\artisan cache:clear
php backend\artisan config:clear
```

Controllo JS:

```bat
node --check public\assets\app.js
```

Log Laravel:

```bat
type backend\storage\logs\laravel.log
```

## Regole Per Chi Modifica Il Progetto

1. Non aggiornare Laravel a una versione moderna se l'obiettivo e' solo far funzionare questa installazione locale.
2. Non usare PHP 8.x: questa app e le dipendenze sono pensate per PHP 7.4.
3. Dopo modifiche backend a rotte o config, pulire le cache Laravel.
4. Dopo modifiche frontend, aggiornare anche `public/assets/app.js` e/o `public/assets/app.css`.
5. Fare attenzione a non cancellare dati utente con `flox:db --fresh`.
6. Quando si lavora su import/export, preservare la modalita' "replace" e la modalita' "add".
7. Quando si corregge il TMDb ID di una serie, preservare il progresso degli episodi visti.
8. Non affidarsi alla tabella `jobs` per sapere se c'e' import in corso quando `QUEUE_DRIVER=sync`.
9. Per il gradimento personale, ricordare che `rating = 0` significa `?`, non "non visto"; il valore `null` significa non aggiunto/votato.

## Prompt Consigliato Da Dare A Una AI

```text
Sto lavorando su Flox, una vecchia app Laravel 6 + Vue installata in locale con Laragon in D:\Web\laragon\www\flox e servita da http://localhost:60888/flox/.

Leggi AI_CONTEXT.md prima di modificare il progetto.

Usa PHP 7.4, non PHP 8. Dopo modifiche backend pulisci cache/route. Dopo modifiche frontend aggiorna sia i sorgenti in client sia i bundle gia' compilati in public/assets/app.js e public/assets/app.css, perche' Laragon serve questi ultimi.

Mantieni le personalizzazioni gia' fatte: import minimale con arricchimento TMDb, export CSV visto, add import, Settings nel menu alto, Seen at the end, HIDE SEEN, watchlist piu' chiara, ordinamento Own Rating con `?` in fondo, ciclo rating `4 -> 1 -> 2 -> 3 -> 0 -> 4` con cuore per il super positivo, Manual TMDb update con preservazione episodi visti.

Richiesta:
[scrivi qui la modifica desiderata]
```
