<?php

namespace App\Domains\Shared\Support;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * What the System page shows about the machine the app runs on: whether its background
 * processes are up, how much queued work is waiting or has failed, and the latest errors.
 *
 * Read-only throughout. Nothing here changes a booking, a member or a setting.
 *
 * 🔒 Errors are shown REDACTED. A log line can carry a phone number, a national ID, an email
 * or a token, and a dashboard screenshot must not carry them somewhere else. Redaction is
 * best-effort pattern matching, which is why the page stays super-admin only as well.
 */
final class SystemHealth
{
    /** How old the heartbeat may be before the scheduler counts as stopped. */
    private const int HEARTBEAT_STALE_SECONDS = 180;

    /** Only the tail of a log is read: enough for the latest errors, cheap on a large file. */
    private const int LOG_TAIL_BYTES = 262_144;

    private const array SERIOUS = ['WARNING', 'ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    public static function heartbeatFile(): string
    {
        return storage_path('framework/schedule-heartbeat');
    }

    /**
     * Each background process: running / stopped / unknown (no systemd here, e.g. a laptop).
     *
     * @return array<string, string>
     */
    public static function services(): array
    {
        $prefix = (string) config('rafeeq.ops.service_prefix') ?: basename(base_path());

        $status = ['scheduler' => self::schedulerStatus()];

        foreach (['queue', 'reverb'] as $name) {
            $status[$name] = self::unitStatus("{$prefix}-{$name}");
        }

        return $status;
    }

    public static function schedulerLastBeat(): ?Carbon
    {
        $file = self::heartbeatFile();

        return is_file($file) ? Carbon::createFromTimestamp((int) filemtime($file)) : null;
    }

    private static function schedulerStatus(): string
    {
        $last = self::schedulerLastBeat();

        if ($last === null) {
            return 'unknown';
        }

        return $last->gt(now()->subSeconds(self::HEARTBEAT_STALE_SECONDS)) ? 'running' : 'stopped';
    }

    private static function unitStatus(string $unit): string
    {
        try {
            $result = Process::timeout(3)->run(['systemctl', 'is-active', $unit]);
        } catch (Throwable) {
            return 'unknown';
        }

        return match (trim($result->output())) {
            'active' => 'running',
            'inactive', 'failed', 'deactivating' => 'stopped',
            'activating' => 'starting',
            default => 'unknown',
        };
    }

    /**
     * @return array{pending: int, oldest_seconds: ?int, failed: int}
     */
    public static function queue(): array
    {
        $pending = Schema::hasTable('jobs') ? DB::table('jobs')->count() : 0;
        $oldest = Schema::hasTable('jobs') ? DB::table('jobs')->min('created_at') : null;

        return [
            'pending' => $pending,
            'oldest_seconds' => $oldest === null ? null : max(0, now()->timestamp - (int) $oldest),
            'failed' => Schema::hasTable('failed_jobs') ? DB::table('failed_jobs')->count() : 0,
        ];
    }

    /**
     * The latest failed jobs, newest first, with the reason's first line redacted.
     *
     * @return array<int, array{uuid: string, job: string, queue: string, failed_at: ?Carbon, reason: string}>
     */
    public static function failedJobs(int $limit = 20): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return [];
        }

        return DB::table('failed_jobs')
            ->orderByDesc('failed_at')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['uuid', 'queue', 'payload', 'exception', 'failed_at'])
            ->map(function ($row): array {
                $payload = json_decode((string) $row->payload, true);

                return [
                    'uuid' => (string) $row->uuid,
                    'job' => class_basename((string) ($payload['displayName'] ?? 'job')),
                    'queue' => (string) $row->queue,
                    'failed_at' => $row->failed_at ? Carbon::parse($row->failed_at) : null,
                    'reason' => self::redact(mb_substr(strtok((string) $row->exception, "\n") ?: '', 0, 300)),
                ];
            })
            ->all();
    }

    /**
     * The latest warnings and errors from the newest log file, newest first.
     *
     * @return array<int, array{at: string, level: string, message: string}>
     */
    public static function recentErrors(int $limit = 30): array
    {
        $file = self::newestLog();

        if ($file === null) {
            return [];
        }

        $size = (int) filesize($file);
        $handle = fopen($file, 'rb');

        if ($handle === false) {
            return [];
        }

        fseek($handle, max(0, $size - self::LOG_TAIL_BYTES));
        $tail = (string) stream_get_contents($handle);
        fclose($handle);

        preg_match_all('/^\[(\d{4}-\d{2}-\d{2}[ T][^\]]+)\] [\w-]+\.([A-Z]+): (.*)$/m', $tail, $matches, PREG_SET_ORDER);

        $entries = [];

        foreach (array_reverse($matches) as [, $at, $level, $message]) {
            if (! in_array($level, self::SERIOUS, true)) {
                continue;
            }

            $entries[] = [
                'at' => $at,
                'level' => $level,
                // The first line only: the stack trace that follows is for a developer, not this page.
                'message' => self::redact(mb_substr($message, 0, 400)),
            ];

            if (count($entries) >= $limit) {
                break;
            }
        }

        return $entries;
    }

    private static function newestLog(): ?string
    {
        $files = File::glob(storage_path('logs/*.log')) ?: [];

        usort($files, fn (string $a, string $b) => filemtime($b) <=> filemtime($a));

        return $files[0] ?? null;
    }

    /**
     * Masks what must not reach a screen: phone numbers, national IDs, emails, tokens and
     * anything labelled as a password or secret.
     */
    public static function redact(string $text): string
    {
        $patterns = [
            // "password":"…", password=…, secret / token / key values.
            '/("?(?:password|passwd|secret|token|api[_-]?key|authorization)"?\s*[:=]\s*)("[^"]*"|\S+)/i' => '$1"***"',
            '/\bBearer\s+[A-Za-z0-9._~+\/=-]+/i' => 'Bearer ***',
            '/[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}/' => '***@***',
            // Egyptian national ID: 14 digits.
            '/(?<!\d)\d{14}(?!\d)/' => '**************',
            // Phone numbers: +20 / 0020 / 01x…
            '/(?:\+|00)?20\s?1[0125]\d{8}(?!\d)/' => '+20*********',
            '/(?<!\d)01[0125]\d{8}(?!\d)/' => '01*********',
            // Long opaque strings: tokens, signed URLs, keys.
            '/\b[A-Za-z0-9_-]{40,}\b/' => '***',
        ];

        return (string) preg_replace(array_keys($patterns), array_values($patterns), $text);
    }
}
