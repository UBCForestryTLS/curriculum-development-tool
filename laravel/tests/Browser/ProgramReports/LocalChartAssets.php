<?php

namespace Tests\Browser\ProgramReports;

use Closure;
use Illuminate\Http\Request;

final class LocalChartAssets
{
    public function handle(Request $request, Closure $next)
    {
        $assets = ['highcharts.js', 'modules/exporting.js', 'modules/offline-exporting.js'];
        foreach ($assets as $asset) {
            if ($request->path() === '__browser/highcharts/'.$asset) {
                return response(file_get_contents(base_path('node_modules/highcharts/'.$asset)), 200, [
                    'Content-Type' => 'application/javascript',
                ]);
            }
        }

        $response = $next($request);
        if (str_contains($response->headers->get('Content-Type', ''), 'text/html')) {
            // Use the real chart library locally; report requests and rendering stay unchanged.
            $response->setContent(str_replace(
                array_map(fn ($asset) => 'https://code.highcharts.com/'.$asset, $assets),
                array_map(fn ($asset) => '/__browser/highcharts/'.$asset, $assets),
                $response->getContent(),
            ));
        }

        return $response;
    }
}
