<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** /changelog shows CHANGELOG.md of the application (base_path), which the Docker image copies with the rest of src/laravel. */
class ChangelogTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_changelog_file_is_shown(): void
    {
        $this->assertFileExists(base_path('CHANGELOG.md'));

        $this->actingAs(User::factory()->create())
            ->get('/changelog')
            ->assertOk()
            ->assertSee('Unreleased', false);
    }

    public function test_the_page_needs_a_login(): void
    {
        $this->get('/changelog')->assertRedirect();
    }
}
