<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;
use PDO;
use Throwable;

/**
 * What to copy when DB_CONNECTION is changed from SQLite to PostgreSQL. The entrypoint asks for it (artisan
 * mdm:sqlite-import-source) after the migrations have created the tables of the PostgreSQL database, and copies the data
 * with pgloader (data only, tables truncated first, sequences reset). The file is not deleted afterwards, it gets the
 * extension .bkp.
 *
 * Copied once: when the SQLite file has users or devices and the PostgreSQL database has no users (a database that is in use
 * is never overwritten). The state file says what the entrypoint did: "started <file>" (an interrupted copy is repeated: it
 * truncates first, so it is safe) and "done <file>" (never again).
 */
class SqliteImport
{
    /** Where the entrypoint writes what it did. */
    public static function statePath(): string
    {
        return storage_path('sqlite-import.state');
    }

    /** The SQLite file to copy into the default (PostgreSQL) database; null when there is nothing to do. */
    public static function pending(): ?string
    {
        $connection = (string) config('database.default');

        return config("database.connections.$connection.driver") === 'pgsql' ? self::pendingFor($connection) : null;
    }

    /** The same for any target connection (the tests use SQLite). */
    public static function pendingFor(string $connection): ?string
    {
        $state = self::state();
        if (($state['status'] ?? null) === 'done') {
            return null;
        }
        $path = self::sourcePath();
        if ($path === null) {
            return null;
        }
        if (($state['status'] ?? null) === 'started') {
            return $path;
        }

        return self::hasAccounts($path) && self::targetIsEmpty($connection) ? $path : null;
    }

    /** @return array{status?: string, file?: string} */
    public static function state(): array
    {
        $line = is_file(self::statePath()) ? trim((string) file_get_contents(self::statePath())) : '';

        return preg_match('/^(started|done) (.+)$/', $line, $m) ? ['status' => $m[1], 'file' => $m[2]] : [];
    }

    /** SQLITE_IMPORT_FILE, else database/database.sqlite, else storage/database.sqlite; only a file with something in it. */
    public static function sourcePath(): ?string
    {
        $candidates = array_filter([config('mdm.sqlite_import_file'), database_path('database.sqlite'), storage_path('database.sqlite')]);
        foreach ($candidates as $path) {
            if (is_file($path) && filesize($path) > 0) {
                return (string) $path;
            }
        }

        return null;
    }

    /** The SQLite database has an account or a device: there is something worth keeping in it. */
    private static function hasAccounts(string $path): bool
    {
        try {
            $pdo = new PDO('sqlite:'.$path, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            foreach (['users', 'devices'] as $table) {
                if ($pdo->query("select exists (select 1 from $table)")->fetchColumn()) {
                    return true;
                }
            }
        } catch (Throwable) {
        }

        return false;
    }

    /** No user yet (the migrations only add the scripts they seed); false when the tables are not there. */
    private static function targetIsEmpty(string $connection): bool
    {
        try {
            return DB::connection($connection)->table('users')->doesntExist();
        } catch (Throwable) {
            return false;
        }
    }
}
