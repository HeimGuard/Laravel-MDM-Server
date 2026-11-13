<?php

namespace App\Http\Controllers;

use Illuminate\Support\Str;

class ChangelogController extends Controller
{
    /** The changelog is a file of the application (src/laravel/CHANGELOG.md, in the image /var/www/CHANGELOG.md). */
    public function index()
    {
        $path = base_path('CHANGELOG.md');
        $content = is_file($path) ? Str::markdown((string) file_get_contents($path), ['html_input' => 'strip']) : '';

        return view('changelog.index', ['content' => $content]);
    }
}
