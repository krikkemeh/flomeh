# Schema import Flox

Questo documento descrive il formato JSON accettato dalla funzione di import di Flox.

## Formato minimo consigliato

L'import accetta anche un formato ridotto. In questo caso Flox prova ad arricchire online i dati usando TMDb. Se TMDb non risponde o un dato non puo essere completato, Flox crea comunque un elemento minimo che potra essere completato in seguito con la funzione Refresh.

Il formato minimo puo essere un array JSON:

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

Oppure puo stare dentro `items`:

```json
{
  "items": [
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
}
```

Regole del formato minimo:

- `titolo` o `title`: titolo della serie o del film.
- `tmdb_id`: ID TMDb reale.
- `stagione` e `episodio`: presenti solo per serie TV.
- Se `stagione` e `episodio` sono presenti, Flox considera l'elemento una serie TV.
- Se `stagione` e `episodio` non sono presenti, Flox considera l'elemento un film.
- Per le serie, Flox marca come visti gli episodi fino a `stagione` / `episodio` solo se e' presente `seen_until: true` oppure uno degli alias `mark_seen_until`, `visto_fino_a`, `visti_fino_a`.
- `stagione` e `episodio` da soli non marcano automaticamente gli episodi come visti.
- Per i film, Flox li importa come gia visti.

Esempio prompt per una IA:

```text
Genera un JSON per Flox in formato minimo.
Ogni elemento deve avere: titolo, tmdb_id.
Per le serie puoi aggiungere anche stagione ed episodio. Aggiungi seen_until: true solo se il testo dice esplicitamente che sono stati visti tutti gli episodi fino a quel punto.
Per i film non aggiungere stagione ed episodio.
Usa ID TMDb reali.
Non aggiungere testo fuori dal JSON.
```

Il file da importare deve avere estensione `.json` e deve contenere un oggetto JSON valido con questa struttura principale:

```json
{
  "items": [],
  "episodes": [],
  "alternative_titles": [],
  "settings": []
}
```

## Regole generali

- Non usare CSV.
- Non aggiungere testo fuori dal JSON.
- Non inserire commenti nel JSON.
- Non usare virgole finali.
- I campi `id` possono essere omessi: Flox li rigenera.
- Per i booleani usare `true` / `false` oppure `1` / `0`.
- Per i timestamp Unix usare secondi, non millisecondi.
- Per campi come `released_timestamp`, `created_at`, `updated_at`, `last_seen_at`, `refreshed_at` usare il formato `YYYY-MM-DD HH:MM:SS`, oppure `null`.

## Modalita di import

Nella pagina Backup ci sono due import:

- `Import`: sostituisce la libreria esistente con il contenuto del file.
- `Import and add`: aggiunge il file alla libreria esistente.

`Import and add` fa merge usando `tmdb_id` e `media_type`: se trova gia lo stesso film o la stessa serie, aggiorna il record invece di creare un duplicato. Anche gli episodi vengono aggiornati usando `tmdb_id`, `season_number` ed `episode_number`.

## Struttura principale

```json
{
  "items": [],
  "episodes": [],
  "alternative_titles": [],
  "settings": []
}
```

## Schema `items`

Ogni elemento in `items` rappresenta una serie TV o un film.

```json
{
  "tmdb_id": 76479,
  "title": "The Boys",
  "original_title": "The Boys",
  "poster": "/h55FdfqCsaYRvtS2lLc88dHmWJi.jpg",
  "media_type": "tv",
  "rating": "0",
  "released": 1564012800,
  "released_timestamp": "2019-07-25 00:00:00",
  "src": null,
  "subtitles": null,
  "fp_name": null,
  "backdrop": "/path_backdrop.jpg",
  "slug": "the-boys",
  "youtube_key": null,
  "imdb_id": "tt1190634",
  "overview": "Descrizione della serie o del film.",
  "tmdb_rating": "8.4",
  "imdb_rating": "8.7",
  "watchlist": false,
  "refreshed_at": null,
  "homepage": null,
  "created_at": "2026-04-15 12:30:00",
  "updated_at": "2026-04-15 12:30:00",
  "last_seen_at": "2026-04-15 12:30:00"
}
```

### Campi importanti di `items`

`tmdb_id`: ID TMDb numerico. Deve essere reale se possibile. Puo essere `null` solo per elementi manuali/file-parser, ma e meglio evitarlo.

`title`: titolo visualizzato.

`original_title`: titolo originale. Puo essere uguale a `title`.

`poster`: path poster TMDb, per esempio `/abc123.jpg`. Puo essere stringa vuota se non disponibile.

`media_type`: deve essere `tv` per le serie TV oppure `movie` per i film.

`rating`: valutazione personale in Flox. Usa `"0"` se non valutato. Per indicare un film visto, usare un valore diverso da `"0"`, per esempio `"1"`. Valori UI: `"4"` super positivo/cuore, `"1"` positivo, `"2"` medio, `"3"` brutto, `"0"` non definito.

`released`: timestamp Unix in secondi della data di uscita.

`released_timestamp`: data di uscita in formato `YYYY-MM-DD HH:MM:SS`.

`watchlist`: `false` se l'elemento e gia visto o presente nella libreria, `true` se e solo nella watchlist.

`last_seen_at`: ultima data di visione/modifica in formato `YYYY-MM-DD HH:MM:SS`.

## Schema `episodes`

Ogni elemento in `episodes` rappresenta un episodio di una serie.

```json
{
  "tmdb_id": 76479,
  "name": "The Name of the Game",
  "season_number": 1,
  "season_tmdb_id": 104849,
  "episode_number": 1,
  "episode_tmdb_id": 1817423,
  "seen": true,
  "src": null,
  "subtitles": null,
  "fp_name": null,
  "release_episode": 1564012800,
  "release_season": 1564012800,
  "created_at": "2026-04-15 12:30:00",
  "updated_at": "2026-04-15 12:30:00"
}
```

### Campi importanti di `episodes`

`tmdb_id`: deve corrispondere al `tmdb_id` della serie in `items`.

`name`: titolo episodio.

`season_number`: numero stagione.

`episode_number`: numero episodio.

`season_tmdb_id`: ID TMDb della stagione. Se non e noto, usare un numero coerente e stabile, ma e meglio usare quello reale.

`episode_tmdb_id`: ID TMDb dell'episodio. Se non e noto, usare un numero coerente e stabile, ma e meglio usare quello reale.

`seen`: `true` se episodio visto, `false` se episodio non visto.

`release_episode`: timestamp Unix in secondi della data di uscita episodio. Puo essere `null`.

`release_season`: timestamp Unix in secondi della data di uscita stagione. Puo essere `null`.

## Schema `alternative_titles`

`alternative_titles` puo essere un array vuoto. Serve per titoli alternativi.

```json
{
  "tmdb_id": 76479,
  "title": "The Boys",
  "country": "US"
}
```

## Schema `settings`

`settings` puo essere lasciato vuoto se si vogliono importare solo elementi ed episodi:

```json
"settings": []
```

In alternativa si puo usare una configurazione base:

```json
{
  "show_date": true,
  "show_genre": false,
  "episode_spoiler_protection": true,
  "last_fetch_to_file_parser": null,
  "show_watchlist_everywhere": false,
  "show_ratings": "always",
  "refresh_automatically": false,
  "reminders_send_to": null,
  "daily_reminder": false,
  "weekly_reminder": false
}
```

## Esempio completo minimo

Questo esempio crea una serie con due episodi, uno visto e uno non visto, piu un film visto.

```json
{
  "items": [
    {
      "tmdb_id": 76479,
      "title": "The Boys",
      "original_title": "The Boys",
      "poster": "/h55FdfqCsaYRvtS2lLc88dHmWJi.jpg",
      "media_type": "tv",
      "rating": "0",
      "released": 1564012800,
      "released_timestamp": "2019-07-25 00:00:00",
      "src": null,
      "subtitles": null,
      "fp_name": null,
      "backdrop": null,
      "slug": "the-boys",
      "youtube_key": null,
      "imdb_id": "tt1190634",
      "overview": "A group of vigilantes set out to take down corrupt superheroes.",
      "tmdb_rating": "8.4",
      "imdb_rating": "8.7",
      "watchlist": false,
      "refreshed_at": null,
      "homepage": null,
      "created_at": "2026-04-15 12:30:00",
      "updated_at": "2026-04-15 12:30:00",
      "last_seen_at": "2026-04-15 12:30:00"
    },
    {
      "tmdb_id": 550,
      "title": "Fight Club",
      "original_title": "Fight Club",
      "poster": "/pB8BM7pdSp6B6Ih7QZ4DrQ3PmJK.jpg",
      "media_type": "movie",
      "rating": "1",
      "released": 938736000,
      "released_timestamp": "1999-10-15 00:00:00",
      "src": null,
      "subtitles": null,
      "fp_name": null,
      "backdrop": null,
      "slug": "fight-club",
      "youtube_key": null,
      "imdb_id": "tt0137523",
      "overview": "An insomniac office worker and a soap maker form an underground fight club.",
      "tmdb_rating": "8.4",
      "imdb_rating": "8.8",
      "watchlist": false,
      "refreshed_at": null,
      "homepage": null,
      "created_at": "2026-04-15 12:30:00",
      "updated_at": "2026-04-15 12:30:00",
      "last_seen_at": "2026-04-15 12:30:00"
    }
  ],
  "episodes": [
    {
      "tmdb_id": 76479,
      "name": "The Name of the Game",
      "season_number": 1,
      "season_tmdb_id": 104849,
      "episode_number": 1,
      "episode_tmdb_id": 1817423,
      "seen": true,
      "src": null,
      "subtitles": null,
      "fp_name": null,
      "release_episode": 1564012800,
      "release_season": 1564012800,
      "created_at": "2026-04-15 12:30:00",
      "updated_at": "2026-04-15 12:30:00"
    },
    {
      "tmdb_id": 76479,
      "name": "Cherry",
      "season_number": 1,
      "season_tmdb_id": 104849,
      "episode_number": 2,
      "episode_tmdb_id": 1817424,
      "seen": false,
      "src": null,
      "subtitles": null,
      "fp_name": null,
      "release_episode": 1564012800,
      "release_season": 1564012800,
      "created_at": "2026-04-15 12:30:00",
      "updated_at": "2026-04-15 12:30:00"
    }
  ],
  "alternative_titles": [],
  "settings": []
}
```

## Prompt breve da dare a una IA

```text
Genera un file JSON compatibile con l'import di Flox.

Il JSON deve avere questa struttura:
{
  "items": [],
  "episodes": [],
  "alternative_titles": [],
  "settings": []
}

Per ogni serie TV crea un record in items con media_type "tv" e uno o piu record in episodes con lo stesso tmdb_id della serie.
Per ogni film crea un record in items con media_type "movie"; se il film e visto, imposta watchlist false e rating diverso da "0".
Usa ID TMDb reali quando possibile.
Usa timestamp Unix in secondi per released, release_episode e release_season.
Usa date in formato "YYYY-MM-DD HH:MM:SS" per created_at, updated_at, last_seen_at e released_timestamp.
Non aggiungere testo fuori dal JSON.
Non creare CSV.
Non inserire commenti.
```

Nota: Flox lavora meglio quando `tmdb_id`, `season_tmdb_id` ed `episode_tmdb_id` sono ID TMDb reali.
