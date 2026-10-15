<?php

namespace Tests\Feature;

use App\Livewire\User\Language;
use App\Models\AlertRule;
use App\Models\Device;
use App\Models\User;
use App\Support\AlertEvaluator;
use App\Support\Locales;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Tests\TestCase;

class LocaleTest extends TestCase
{
    use RefreshDatabase;

    /** Texts translated from a variable (labels of constants, ucfirst of states, menu titles). */
    private const DYNAMIC_KEYS = [
        'Status', 'CPU usage', 'Memory usage', 'Disk usage', 'Disk health', 'Services',
        'Remediations', 'Security findings', 'New device', 'Unknown device', 'Exposed service', 'Address changed', 'The device is offline',
        'Average CPU usage above the threshold', 'Average usage above a percentage, or free memory below a size', 'A drive fuller than a percentage, or with less free space than a size', 'A disk reports a S.M.A.R.T. warning or failure', 'A service failed or a container is unhealthy', 'The latest run of a remediation script failed',
        'The security scanner found something of high or critical severity', 'A device is enrolled or added', 'An agent sees a device the portal does not know in its network', 'A port scan found an exposed or weak service on a host that is not a managed device', 'A ping-only device with a MAC address got another IP address (dynamic address)', 'Installed software', 'Running processes',
        'Listening ports', 'Startup items', 'Administrators', 'Security settings', 'Security events', 'Failed sign-ins',
        'Account created', 'Account locked out', 'Added to administrators', 'Audit log cleared', 'Service installed', 'Scheduled task created',
        'Malware detected', 'Protection turned off', 'Failed sudo', 'Restart', 'Agent update', 'Disk',
        'S.M.A.R.T.', 'Remediation', 'All', 'Windows', 'Linux', 'Every hour',
        'Every 6 hours', 'Every day at 3:00', 'Every Sunday at 3:00', 'On the 1st of the month at 3:00', 'Devices', 'Networks',
        'Security', 'Notifications', 'Settings', 'Audit', 'User', 'Logs',
        'Jobs', 'Cache', 'Backup', 'Api', 'Critical', 'High',
        'Medium', 'Low', 'Info', 'Pending', 'Sent', 'Compliant',
        'Noncompliant', 'Remediated', 'Failed', 'Error', 'Rejected', 'Expired',
        'Superseded', 'Server', 'Laptop', 'Desktop', 'Ping', 'LAN',
        'Wi-Fi', 'VPN', 'Docker', 'Bridge', 'Virtual', 'Mobile',
        'Bluetooth', 'Running', 'Stopped', 'Unknown', 'Exited', 'Paused',
        'Restarting', 'Created', 'Dead', 'Protocol', 'Address', 'Port',
        'Process', 'Name', 'Source', 'Enabled', 'Location', 'Command',
        'Version', 'Publisher', 'Path', 'Command Line', 'Search...', 'Setting Saved',
        'Updated', 'Removed', 'Language', 'Language of the user interface',
    ];

    public function test_setup_saves_the_language_for_the_admin_and_as_default(): void
    {
        $this->withoutVite();

        $this->post('/setup', [
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'locale' => 'de',
        ])->assertRedirect(route('devices'));

        $user = User::query()->sole();
        $this->assertSame('de', Locales::ofUser($user));
        $this->assertSame('de', Locales::system());
    }

    public function test_setup_refuses_an_unknown_language(): void
    {
        $this->withoutVite();

        $this->post('/setup', [
            'name' => 'Admin',
            'email' => 'admin@example.com',
            'password' => 'secret-password',
            'password_confirmation' => 'secret-password',
            'locale' => 'xx',
        ])->assertSessionHasErrors('locale');
        $this->assertDatabaseCount('users', 0);
    }

    public function test_setup_page_can_be_shown_in_another_language(): void
    {
        $this->withoutVite();

        $this->get('/setup?lang=cs')->assertOk()->assertSee('Vytvořit účet')->assertSee('lang="cs"', false);
        // Remembered until signing in.
        $this->get('/setup')->assertOk()->assertSee('Vytvořit účet');
    }

    public function test_pages_are_in_the_language_of_the_user(): void
    {
        $this->withoutVite();
        Locales::setSystem('de');
        $user = User::factory()->create();
        Locales::setForUser($user, 'fr');

        $this->actingAs($user)->get(route('profile.index'))
            ->assertOk()
            ->assertSee('lang="fr"', false)
            ->assertSee('Changer le mot de passe');
    }

    public function test_users_without_a_language_get_the_default_of_the_system(): void
    {
        $this->withoutVite();
        Locales::setSystem('it');
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('profile.index'))->assertOk()->assertSee('Cambia password');
    }

    public function test_english_without_any_setting(): void
    {
        $this->withoutVite();
        $user = User::factory()->create();

        $this->actingAs($user)->get(route('profile.index'))->assertOk()->assertSee('Change Password');
    }

    public function test_the_profile_changes_the_language(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Livewire::test(Language::class, ['user' => $user])
            ->set('locale', 'es')
            ->call('store')
            ->assertRedirect(route('profile.index'));
        $this->assertSame('es', Locales::ofUser($user->fresh()));

        Livewire::test(Language::class, ['user' => $user])
            ->set('locale', 'xx')
            ->call('store')
            ->assertHasErrors('locale');
        $this->assertSame('es', Locales::ofUser($user->fresh()));
    }

    public function test_alerts_stay_in_english(): void
    {
        $user = User::factory()->create();
        Locales::setForUser($user, 'de');
        $rule = AlertRule::query()->create(['user_id' => $user->id, 'type' => 'address_changed', 'target' => [], 'channels' => [], 'enabled' => true]);
        $printer = new Device;
        $printer->forceFill(['kind' => 'ping', 'name' => 'Printer', 'os' => '', 'token' => hash('sha256', 'p'), 'ping_address' => '192.168.1.31', 'ping_prefix' => 24, 'ping_mac' => 'AA:BB:CC:00:00:30'])->save();

        // As if a request of a user with another language caused it.
        App::setLocale('de');
        AlertEvaluator::addressChanged($printer, '192.168.1.30', '192.168.1.31');

        $this->assertStringContainsString('is at 192.168.1.31 now, the portal pings 192.168.1.30', $rule->events()->sole()->message);
        // The language of the request is back afterwards.
        $this->assertSame('de', App::getLocale());
    }

    /** Every text of the user interface has a translation in each language, with the same placeholders. */
    public function test_every_text_is_translated(): void
    {
        $keys = array_unique(array_merge($this->keysInSource(), self::DYNAMIC_KEYS));
        $this->assertGreaterThan(500, count($keys));

        foreach (array_keys(Locales::available()) as $locale) {
            if ($locale === 'en') {
                continue;
            }
            $translations = json_decode(file_get_contents(lang_path($locale.'.json')), true, flags: JSON_THROW_ON_ERROR);
            $missing = array_values(array_diff($keys, array_keys($translations)));
            $this->assertSame([], $missing, "Missing in lang/$locale.json");

            foreach ($translations as $key => $text) {
                preg_match_all('/:[a-zA-Z_]+\b|\{[^}]*\}/', $key, $placeholders);
                foreach (array_unique($placeholders[0]) as $placeholder) {
                    $this->assertStringContainsString($placeholder, $text, "lang/$locale.json: \"$key\"");
                }
            }
        }
    }

    /** @return list<string> the literal keys of __() in app/ and resources/views/ */
    private function keysInSource(): array
    {
        $keys = [];
        foreach ([app_path(), resource_path('views')] as $dir) {
            foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir)) as $file) {
                if (! str_ends_with($file, '.php')) {
                    continue;
                }
                preg_match_all('/(?:__|trans|trans_choice)\(\s*(\'(?:[^\'\\\\]|\\\\.)*\'|"(?:[^"\\\\]|\\\\.)*")/s', file_get_contents($file), $matches);
                foreach ($matches[1] as $literal) {
                    $text = substr($literal, 1, -1);
                    $text = $literal[0] === "'" ? str_replace(["\\'", '\\\\'], ["'", '\\'], $text) : stripcslashes($text);
                    // Keys of PHP files (boilerplate::auth.login, validation.required) are not JSON keys.
                    if ($text !== '' && ! str_contains($text, '::') && ! preg_match('/^[a-z_]+\.[a-z_.]+$/', $text)) {
                        $keys[] = $text;
                    }
                }
            }
        }

        return array_values(array_unique($keys));
    }
}
