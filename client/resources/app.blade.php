<!doctype html>
<html>
<head>

  <meta charset="utf-8">
  <meta id="token" content="{{ csrf_token() }}">
  <meta name="viewport" content="width=device-width, initial-scale=1, user-scalable=0">

  <title>FloMeh</title>
  <link rel="stylesheet" href="{{ rtrim(config('app.url'), '/') }}/assets/app.css?v=5">
  <link href="{{ rtrim(config('app.url'), '/') }}/assets/favicon.ico?v=5" rel="icon" type="image/x-icon">

</head>
<body
  data-env="{{ config('app.env') }}"
  data-url="{{ rtrim(config('app.url'), '/') }}"
  data-uri="{{ config('app.CLIENT_URI') }}"
  data-poster-tmdb="{{ config('services.tmdb.poster') }}"
  data-poster-subpage-tmdb="{{ config('services.tmdb.poster_subpage') }}"
  data-backdrop-tmdb="{{ config('services.tmdb.backdrop') }}"
  data-auth="{{ Auth::check() }}"
  data-language="{{ $lang }}"
  class="{{ Auth::check() ? 'logged' : 'guest' }}"
>

  <div id="app">
    @if(Request::is('login') || Request::is(trim(config('app.CLIENT_URI'), '/') . '/login'))
      <login></login>
    @else
      <modal></modal>
      <site-header></site-header>
      <router-view></router-view>
      <site-footer></site-footer>
    @endif
  </div>

  <script src="{{ rtrim(config('app.url'), '/') }}/assets/vendor.js"></script>
  <script src="{{ rtrim(config('app.url'), '/') }}/assets/app.js?v=5"></script>

</body>
</html>
