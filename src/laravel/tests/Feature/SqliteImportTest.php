<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\SqliteImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/** When the data of an SQLite database is copied into PostgreSQL (the copy itself is pgloader in the entrypoint). */
#[Group('fresh-db')]
class SqliteImportTest extends TestCase
{
    use RefreshDatabase;

    private string $source;

    protected function setUp(): void
    {
        parent::setUp();
        $this->source = tempnam(sys_get_temp_dir(), 'mdm-sqlite-');
        config(['mdm.sqlite_import_file' => $this->source]);
        $this->forgetState();
    }

    protected function tearDown(): void
    {
        @unlink($this->source);
        $this->forgetState();
        parent::tearDown();
    }

    private function forgetState(): void
    {
        if (is_file(SqliteImport::statePath())) {
            unlink(SqliteImport::statePath());
        }
    }

    /** The SQLite file of an installation that has been used (one user), or one without any account. */
    private function fillSource(bool $withUser): void
    {
        $pdo = new PDO('sqlite:'.$this->source, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        $pdo->exec('create table users (id integer primary key autoincrement, email text)');
        $pdo->exec('create table devices (id integer primary key autoincrement, name text)');
        if ($withUser) {
            $pdo->exec("insert into users (email) values ('admin@example.com')");
        }
    }

    public function test_an_installation_that_has_been_used_is_imported_into_an_empty_database(): void
    {
        $this->fillSource(true);

        $this->assertSame($this->source, SqliteImport::pendingFor('sqlite'));
    }

    public function test_nothing_is_imported_without_accounts_or_without_a_file(): void
    {
        $this->assertNull(SqliteImport::pendingFor('sqlite'));

        $this->fillSource(false);
        $this->assertNull(SqliteImport::pendingFor('sqlite'));
    }

    public function test_a_database_that_is_in_use_is_never_overwritten(): void
    {
        $this->fillSource(true);
        User::factory()->create();

        $this->assertNull(SqliteImport::pendingFor('sqlite'));
    }

    public function test_an_interrupted_copy_is_repeated_and_a_finished_one_is_not(): void
    {
        $this->fillSource(true);
        User::factory()->create();

        // pgloader truncates the tables first, so the repeat is safe even when it got as far as the users.
        file_put_contents(SqliteImport::statePath(), 'started '.$this->source);
        $this->assertSame($this->source, SqliteImport::pendingFor('sqlite'));

        file_put_contents(SqliteImport::statePath(), 'done '.$this->source);
        $this->assertNull(SqliteImport::pendingFor('sqlite'));
    }

    public function test_only_a_postgresql_database_asks_for_it(): void
    {
        $this->fillSource(true);

        $this->assertSame('sqlite', config('database.connections.'.config('database.default').'.driver'));
        $this->assertNull(SqliteImport::pending());
    }
}
