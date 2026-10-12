<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class AddRequestId
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $requestId = $request->header('X-Request-ID', (string) Str::uuid());

        // Bind the request ID to the container so it can be accessed anywhere
        app()->instance('requestId', $requestId);

        $response = $next($request);

        // Add the request ID to the response headers
        $response->headers->set('X-Request-ID', $requestId);

        return $response;
    }
}
