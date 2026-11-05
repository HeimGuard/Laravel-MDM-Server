<?php

namespace App\Console\Commands;

use App\Support\Syslog;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Started by rsyslog (src/docker/rsyslog/rsyslog.conf, omprog): reads the received messages from stdin, one JSON object
 * per line, and stores the events of the ones that come from a known device. Ends when rsyslog closes the input.
 */
class SyslogIngest extends Command
{
    protected $signature = 'mdm:syslog-ingest {--batch=500 : Messages that are stored at once} {--seconds=3 : Longest wait before a batch is stored}';

    protected $description = 'Store the syslog messages that rsyslog passes on (stdin, one JSON object per line)';

    /** The senders that are not devices are named in the log at most this often. */
    private const REPORT_SECONDS = 300;

    public function handle(): int
    {
        $batch = max(1, (int) $this->option('batch'));
        $seconds = max(1, (int) $this->option('seconds'));
        $stdin = STDIN;
        stream_set_blocking($stdin, false);
        $buffer = '';
        $messages = [];
        $unknown = [];
        $since = null;
        $reported = time();
        $open = true;
        while ($open || $messages !== []) {
            if ($open) {
                $read = [$stdin];
                $write = $except = null;
                if (stream_select($read, $write, $except, 1) > 0) {
                    $chunk = fread($stdin, 65536);
                    if ($chunk === '' || $chunk === false) {
                        $open = feof($stdin) ? false : true;
                    } else {
                        $buffer .= $chunk;
                    }
                }
                while (($end = strpos($buffer, "\n")) !== false) {
                    $line = substr($buffer, 0, $end);
                    $buffer = substr($buffer, $end + 1);
                    $message = json_decode($line, true);
                    if (is_array($message)) {
                        $messages[] = $message;
                        $since ??= time();
                    }
                }
                if (strlen($buffer) > 1_000_000) {
                    $buffer = '';
                }
            }
            if ($messages !== [] && (! $open || count($messages) >= $batch || time() - ($since ?? time()) >= $seconds)) {
                try {
                    foreach (Syslog::ingest($messages)['unknown'] as $address => $count) {
                        $unknown[$address] = ($unknown[$address] ?? 0) + $count;
                    }
                } catch (Throwable $e) {
                    Log::warning('Syslog batch dropped: '.$e->getMessage());
                }
                $messages = [];
                $since = null;
            }
            if ($unknown !== [] && time() - $reported >= self::REPORT_SECONDS) {
                arsort($unknown);
                $this->line('Syslog dropped, the sender is no device (its IP address is not one of a device): '.implode(', ', array_map(
                    fn ($address, $count) => "$address ($count)", array_keys(array_slice($unknown, 0, 10, true)), array_slice($unknown, 0, 10, true),
                )));
                $unknown = [];
                $reported = time();
            }
        }

        return self::SUCCESS;
    }
}
