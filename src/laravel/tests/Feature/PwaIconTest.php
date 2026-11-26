<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * The icon of the installed app (PWA): the apple-touch-icon and the manifest the pages link to carry a version of the file, so a
 * replaced icon reaches the phones that added the portal to the home screen (the @pwa directive printed them without one).
 */
class PwaIconTest extends TestCase
{
    public function test_the_icon_and_the_manifest_are_linked_with_the_version_of_the_file(): void
    {
        $html = view('partials.pwa')->render();

        $icon = substr(md5_file(public_path('pwa/ios/180.png')), 0, 8);
        $manifest = substr(md5_file(public_path('manifest.json')), 0, 8);

        $this->assertStringContainsString('rel="apple-touch-icon" href="'.asset('/pwa/ios/180.png').'?v='.$icon.'"', $html);
        $this->assertStringContainsString('rel="manifest" href="'.asset('/manifest.json').'?v='.$manifest.'"', $html);
        $this->assertStringContainsString('content="'.asset('/pwa/ios/180.png').'?v='.$icon.'"', $html, 'the og:image too');
        $this->assertStringContainsString("serviceWorker.register('".asset('/sw.js')."')", $html);
    }

    public function test_every_layout_includes_the_partial_and_none_the_directive(): void
    {
        foreach (['layout', 'layout-app', 'layout-auth'] as $layout) {
            $source = file_get_contents(resource_path("views/components/{$layout}.blade.php"));

            $this->assertStringContainsString("@include('partials.pwa')", $source, $layout);
            $this->assertStringNotContainsString('@pwa(', $source, $layout);
        }
    }

    public function test_the_icons_of_the_manifest_exist_and_have_a_version(): void
    {
        $manifest = json_decode(file_get_contents(public_path('manifest.json')), true);

        foreach ($manifest['icons'] as $icon) {
            $this->assertMatchesRegularExpression('/\?v=\w+$/', $icon['src'], 'a version in the URL: '.$icon['src']);
            $this->assertFileExists(public_path(explode('?', $icon['src'])[0]));
        }
    }
}
