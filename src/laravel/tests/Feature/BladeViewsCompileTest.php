<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Blade;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

/**
 * Every Blade view compiles to valid PHP. A view with a syntax error fails only when somebody renders it, and an
 * inline `@php(...)` that pairs with a later `@endphp` can swallow half a view without any error of its own.
 */
class BladeViewsCompileTest extends TestCase
{
    public function test_every_blade_view_compiles_to_valid_php(): void
    {
        $root = resource_path('views');
        $failures = [];
        $count = 0;

        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach ($files as $file) {
            if (! $file->isFile() || ! str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }
            $count++;
            $view = ltrim(str_replace('\\', '/', substr($file->getPathname(), strlen($root))), '/');

            try {
                $compiled = Blade::compileString(file_get_contents($file->getPathname()));
            } catch (\Throwable $e) {
                $failures[] = "$view: Blade could not compile it: ".$e->getMessage();

                continue;
            }

            try {
                token_get_all($compiled, TOKEN_PARSE);
            } catch (\ParseError $e) {
                $failures[] = "$view: ".$e->getMessage().' (compiled line '.$e->getLine().')';
            }
        }

        $this->assertGreaterThan(0, $count, 'No Blade views found in '.$root);
        $this->assertSame([], $failures, count($failures).' Blade view(s) do not compile to valid PHP. '
            .'Open each view at the line of the compiled PHP and fix the directive there; check an inline `@php(...)` '
            ."that pairs with a later `@endphp`, an unclosed `@if` / `@foreach`, or a missing `;`:\n".implode("\n", $failures));
    }
}
