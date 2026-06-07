<?php

namespace App\Http\Middleware;

use App\Support\ApiBugReporter;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

class CaptureApiBugReports
{
    public function handle(Request $request, Closure $next): Response
    {
        if (ApiBugReporter::shouldSkip($request)) {
            return $next($request);
        }

        try {
            /** @var Response $response */
            $response = $next($request);
        } catch (Throwable $exception) {
            ApiBugReporter::reportException($request, $exception);
            throw $exception;
        }

        // Only 5xx — skip 4xx to avoid bot/probe noise (404s, validation errors).
        if ($response->getStatusCode() >= 500) {
            ApiBugReporter::reportFailedResponse($request, $response->getStatusCode());
        }

        return $response;
    }
}
