<?php

namespace App\Http\Controllers;

use App\Services\DockerIconService;

class DockerIconController extends Controller
{
    /** A stored icon of a container (the route only lets the names of stored files through). */
    public function show(string $file)
    {
        $icon = DockerIconService::file($file);
        abort_if($icon === null, 404);

        return response($icon['body'], 200, [
            'Content-Type' => $icon['type'],
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            // An SVG opened as a page must not run anything.
            'Content-Security-Policy' => "default-src 'none'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}
