<?php

  namespace App\Http\Controllers;

  use App\Services\Storage;
  use Illuminate\Support\Facades\Auth;
  use Illuminate\Support\Facades\Request;

  class HomeController {

    public function app(Storage $storage, $uri = null)
    {
      $clientUri = '/' . trim(config('app.CLIENT_URI'), '/');
      $baseUrl = rtrim(config('app.url'), '/');
      $requestPath = '/' . ltrim(Request::path(), '/');
      $loginPath = rtrim($clientUri, '/') . '/login';
      $privacyPath = rtrim($clientUri, '/') . '/privacypolicy';
      $loginUrl = $baseUrl . '/login';
      $homeUrl = $baseUrl;

      if (!Auth::check() && $requestPath !== $loginPath && $requestPath !== $privacyPath) {
        return redirect()->to($loginUrl);
      }

      if (Auth::check() && $requestPath === $loginPath) {
        return redirect()->to($homeUrl);
      }

      $language = $storage->parseLanguage();

      return view('app')
        ->withLang($language);
    }
  }
