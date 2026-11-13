<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SetupTest extends TestCase
{
    use RefreshDatabase;

    public function test_migrations_create_no_default_account(): void
    {
        $this->assertDatabaseCount('users', 0);
    }

    public function test_login_with_the_old_default_password_warns(): void
    {
        $user = User::factory()->create(['password' => 'the-password-of-choice']);

        $this->post('/login', ['email' => $user->email, 'password' => 'the-password-of-choice'])
            ->assertSessionHas('warning');
        $this->assertAuthenticatedAs($user);
    }

    public function test_login_records_the_time_without_touching_updated_at(): void
    {
        $user = User::factory()->create(['password' => 'a-secret-password', 'updated_at' => now()->subDay()]);
        $this->assertNull($user->last_login_at);

        $this->post('/login', ['email' => $user->email, 'password' => 'a-secret-password']);

        $user->refresh();
        $this->assertNotNull($user->last_login_at);
        $this->assertTrue($user->updated_at->lt(now()->subHour()));
    }

    public function test_login_leads_to_the_setup_before_the_first_user_exists(): void
    {
        $this->withoutVite();

        $this->get('/login')->assertRedirect(route('setup'));
        $this->get('/setup')->assertOk()->assertSee(__('Create account'));
    }

    public function test_setup_creates_the_first_user_as_system_admin(): void
    {
        $this->withoutVite();
        config(['boilerplate.system_admins' => ['1']]);
        // A system admin is the user with ID 1 and PostgreSQL does not reset the sequence between tests.
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER SEQUENCE users_id_seq RESTART WITH 1');
        }

        $this->post('/setup', [
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertRedirect(route('devices'));

        $user = User::query()->sole();
        $this->assertAuthenticatedAs($user);
        $this->assertSame('admin@example.com', $user->email);
        $this->assertTrue($user->is_system_admin);
    }

    public function test_setup_validates_the_input(): void
    {
        $this->withoutVite();
        $this->post('/setup', [
            'name' => '',
            'email' => 'not-an-email',
            'password' => 'short',
            'password_confirmation' => 'other',
        ])->assertSessionHasErrors(['name', 'email', 'password']);

        $this->assertDatabaseCount('users', 0);
    }

    public function test_setup_is_gone_once_a_user_exists(): void
    {
        $this->withoutVite();
        User::factory()->create();

        $this->get('/setup')->assertNotFound();
        $this->post('/setup', [
            'name' => 'Intruder',
            'email' => 'intruder@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
        ])->assertNotFound();
        $this->assertDatabaseCount('users', 1);
        $this->get('/login')->assertOk();
    }

    public function test_command_creates_a_user_and_changes_the_password(): void
    {
        $this->artisan('mdm:user', ['email' => 'admin@example.com', '--password' => 'secret-password'])
            ->assertSuccessful();
        $user = User::query()->sole();
        $this->assertSame('admin', $user->name);

        $this->artisan('mdm:user', ['email' => 'admin@example.com', '--password' => 'another-password'])
            ->expectsOutputToContain('Password of admin@example.com')
            ->assertSuccessful();
        $this->assertTrue(password_verify('another-password', $user->fresh()->password));
        $this->assertDatabaseCount('users', 1);

        $this->artisan('mdm:user', ['email' => 'admin@example.com', '--password' => 'short'])->assertFailed();
    }
}
