<?php

namespace App\Http\Middleware;

use Closure;
use Symfony\Component\HttpFoundation\Response;

class VerifyExternalProgressToken
{
    public function handle($request, Closure $next)
    {
        $expectedToken = config('services.external_progress.token');

        if( ! $expectedToken) {
            return response(['message' => 'External progress token is not configured'], Response::HTTP_SERVICE_UNAVAILABLE);
        }

        $token = $this->extractToken($request);

        if( ! $token) {
            return response(['message' => 'No external progress token provided'], Response::HTTP_UNAUTHORIZED);
        }

        if($token !== $expectedToken) {
            return response(['message' => 'No valid bearer token provided'], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }

    private function extractToken($request)
    {
        $customHeader = trim((string) $request->header('X-External-Progress-Token', ''));

        if($customHeader !== '') {
            return $customHeader;
        }

        $header = trim((string) $request->header('Authorization', ''));

        if($header !== '') {
            return preg_replace('/^Bearer\s+/i', '', $header);
        }

        return trim((string) $request->input('token', ''));
    }
}
