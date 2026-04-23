<?php

namespace App\Http\Controllers;

use App\ExternalProgressEvent;
use App\Services\ExternalProgressService;
use Illuminate\Support\Facades\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

class ExternalProgressController
{
    private $externalProgress;
    private $events;

    public function __construct(ExternalProgressService $externalProgress, ExternalProgressEvent $events)
    {
        $this->externalProgress = $externalProgress;
        $this->events = $events;
    }

    public function store()
    {
        $validator = Validator::make(Request::all(), [
            'source' => 'required|string|max:50',
            'seriesTitle' => 'required|string|max:255',
            'tmdbId' => 'nullable|integer|min:1',
            'season' => 'required|integer|min:1',
            'episode' => 'required|integer|min:1',
            'episodeTitle' => 'nullable|string|max:255',
            'progress' => 'nullable|numeric|min:0|max:100',
            'markSeen' => 'required|boolean',
            'completed' => 'nullable|boolean',
            'playbackId' => 'nullable|string|max:255',
            'href' => 'nullable|string|max:255',
            'capturedAt' => 'nullable|date',
        ]);

        if($validator->fails()) {
            return response()->json([
                'ok' => false,
                'matched' => false,
                'updatedEpisodes' => 0,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->externalProgress->ingest($validator->validated());

        return response()->json($result, $result['httpStatus'] ?? Response::HTTP_OK);
    }

    public function next()
    {
        $validator = Validator::make(Request::all(), [
            'source' => 'required|string|max:50',
            'seriesTitle' => 'required|string|max:255',
            'tmdbId' => 'nullable|integer|min:1',
            'season' => 'nullable|integer|min:1',
            'platformTitleId' => 'nullable|string|max:255',
            'href' => 'nullable|string|max:255',
        ]);

        if($validator->fails()) {
            return response()->json([
                'ok' => false,
                'matched' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->externalProgress->lookupNextEpisode($validator->validated());

        return response()->json($result, $result['httpStatus'] ?? Response::HTTP_OK);
    }

    public function index()
    {
        $limit = min(max((int) Request::input('limit', 100), 1), 250);

        return [
            'data' => $this->events->orderBy('id', 'desc')->limit($limit)->get()->map(function($event) {
                $override = $this->externalProgress->findOverrideForSeries($event->source, $event->series_title);

                return array_merge($event->toArray(), [
                    'suggested_family_title' => $this->externalProgress->deriveFamilyTitle($event->series_title),
                    'override' => $override ? [
                        'id' => $override->id,
                        'family_series_title' => $override->family_series_title,
                        'tmdb_id' => $override->tmdb_id,
                        'force_season' => $override->force_season,
                        'episode_shift' => $override->episode_shift,
                    ] : null,
                ]);
            })->values(),
        ];
    }

    public function storeOverride($eventId)
    {
        $event = $this->events->findOrFail($eventId);

        $validator = Validator::make(Request::all(), [
            'tmdb_id' => 'required|integer|min:1',
            'family_title' => 'nullable|string|max:255',
            'force_season' => 'nullable|integer|min:1',
            'episode_shift' => 'nullable|integer|min:-10000|max:10000',
        ]);

        if($validator->fails()) {
            return response()->json([
                'ok' => false,
                'message' => $validator->errors()->first(),
                'errors' => $validator->errors(),
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = $this->externalProgress->createOverrideForEvent($event, $validator->validated());

        return response()->json($result, $result['httpStatus'] ?? Response::HTTP_OK);
    }
}
