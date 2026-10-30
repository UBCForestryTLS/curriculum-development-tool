<?php

namespace Tests\Browser\ProgramReports;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PreserveStreamedDownloads
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        if (! $response instanceof StreamedResponse) {
            return $response;
        }

        // Pest's server uses mb_trim on streamed responses, which corrupts XLSX bytes.
        // Buffer the real download here so the browser receives the file unchanged.
        ob_start();
        try {
            $response->sendContent();
        } finally {
            $content = ob_get_clean();
        }

        return new Response($content, $response->getStatusCode(), $response->headers->all());
    }
}
