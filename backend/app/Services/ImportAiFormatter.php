<?php

  namespace App\Services;

  use GuzzleHttp\Client;

  class ImportAiFormatter {

    private $client;

    public function __construct(Client $client)
    {
      $this->client = $client;
    }

    public function format($text)
    {
      $apiKey = env('HF_API_KEY');

      if( ! $apiKey) {
        throw new \RuntimeException('HF_API_KEY is not configured in backend/.env.');
      }

      $response = $this->client->post($this->endpoint(), [
        'timeout' => 60,
        'headers' => [
          'Authorization' => 'Bearer ' . $apiKey,
          'Content-Type' => 'application/json',
        ],
        'json' => [
          'model' => $this->model(),
          'messages' => [
            ['role' => 'user', 'content' => $this->buildPrompt(mb_substr($text, 0, 12000))],
          ],
          'temperature' => 0,
          'max_tokens' => 4000,
        ],
      ]);

      $body = json_decode((string) $response->getBody());

      if(json_last_error() !== JSON_ERROR_NONE || isset($body->error)) {
        throw new \RuntimeException('Invalid AI response.');
      }

      $content = $body->choices[0]->message->content ?? '';
      $json = $this->extractJson($content);
      $decoded = json_decode($json);

      if(json_last_error() !== JSON_ERROR_NONE) {
        throw new \RuntimeException('AI did not return valid JSON.');
      }

      return $decoded;
    }

    private function endpoint()
    {
      return env('HF_ENDPOINT', 'https://router.huggingface.co/v1/chat/completions');
    }

    private function model()
    {
      return env('HF_MODEL', 'meta-llama/Llama-3.1-8B-Instruct:novita');
    }

    private function buildPrompt($text)
    {
      return 'Converti il testo seguente in JSON per importare film e serie TV in FloMeh. ' .
        'Rispondi esclusivamente con JSON valido, senza markdown e senza spiegazioni. ' .
        'Formato richiesto: un array di oggetti. Ogni oggetto deve avere: ' .
        'title string, media_type "movie" oppure "tv", tmdb_id numero o null. ' .
        'Per le serie TV puoi aggiungere season e episode come interi se il testo indica un episodio o un punto della serie. ' .
        'Non segnare automaticamente episodi come visti solo perche\' sono presenti season e episode. ' .
        'Aggiungi seen_until: true solo se il testo dice esplicitamente che sono stati visti tutti gli episodi fino a quella stagione/episodio. ' .
        'Se il TMDb ID non e\' esplicitamente presente nel testo, usa tmdb_id: null: non inventarlo. ' .
        'Se non sei sicuro se sia film o serie, usa tv quando sono presenti stagione/episodio, altrimenti movie. ' .
        'Esempio senza marcatura visti: [{"title":"The Boys","media_type":"tv","tmdb_id":76479,"season":3,"episode":1}]. ' .
        'Esempio con marcatura visti fino a quel punto: [{"title":"The Boys","media_type":"tv","tmdb_id":76479,"season":3,"episode":1,"seen_until":true}]. ' .
        'Esempio film: [{"title":"Fight Club","media_type":"movie","tmdb_id":550}]. ' .
        'Testo da convertire: ' . $text;
    }

    private function extractJson($content)
    {
      $content = trim($content);
      $content = preg_replace('/^```(?:json)?/i', '', $content);
      $content = preg_replace('/```$/', '', $content);
      $content = trim($content);

      $firstArray = strpos($content, '[');
      $firstObject = strpos($content, '{');

      if($firstArray === false && $firstObject === false) {
        return $content;
      }

      if($firstArray !== false && ($firstObject === false || $firstArray < $firstObject)) {
        $start = $firstArray;
        $end = strrpos($content, ']');
      } else {
        $start = $firstObject;
        $end = strrpos($content, '}');
      }

      if($end === false || $end <= $start) {
        return $content;
      }

      return substr($content, $start, $end - $start + 1);
    }
  }
