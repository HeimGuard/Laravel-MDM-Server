<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Enrolment extends Model
{
    public $timestamps = false;

    use HasFactory;

    /** Characters of a code: no 0/O, 1/I/L (read from a screen, typed into a command line). */
    private const ALPHABET = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789';

    public const LENGTH = 8;

    /** Failed registrations (all clients) after which the open codes are dropped. */
    public const MAX_FAILURES = 20;

    /** A random code an agent registers with: 8 characters of 31, not guessable in its 15 minutes. */
    public static function generateCode(): string
    {
        $code = '';
        for ($i = 0; $i < self::LENGTH; $i++) {
            $code .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $code;
    }
}
