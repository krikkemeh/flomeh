<?php

  namespace App\Services;

  use DiDom\Document;

  class IMDB {

    private $document;

    public function __construct(Document $document)
    {
      $this->document = $document;
    }

    public function parseRating($id = null)
    {
      if( ! $id) {
        return null;
      }

      try {
        $document = $this->document->loadHtmlFile(config('services.imdb.url') . $id);
      } catch(\Throwable $exception) {
        return null;
      }

      // We don't need to check if we found a result if we loop over them.
      foreach($document->find('.ratingValue strong span') as $rating) {
        return $rating->text();
      }

      return null;
    }
  }
