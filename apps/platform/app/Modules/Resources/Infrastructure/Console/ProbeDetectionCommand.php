<?php

declare(strict_types=1);

namespace App\Modules\Resources\Infrastructure\Console;

use App\Modules\Resources\Domain\FileKind;
use App\Modules\Resources\Domain\MediaTypeDetector;
use App\Modules\Resources\Domain\OriginalFilename;
use App\Modules\Resources\Infrastructure\DetectionSamples;
use Illuminate\Console\Command;
use ReflectionExtension;

/**
 * `resources:assets:probe`: shows what THIS host's `fileinfo` makes of a real example of every allowed file kind and of what must be
 * refused, and whether the allowlist then decides as it does in development (ADR 0037, decision 64, which asks for the measurement
 * on the production host). Read-only: it touches no database, no store and no setting, and writes only temporary files it removes.
 *
 * Exits non-zero when any example is decided differently from the development measurement pinned by the tests: an allowed kind that
 * would be refused (uploads of it would fail), or a refused one that would be accepted (which must stop a release). It runs under the
 * CLI's PHP; the web server's PHP normally loads the same fileinfo build, and the runbook says how to confirm that.
 */
final class ProbeDetectionCommand extends Command
{
    protected $signature = 'resources:assets:probe';

    protected $description = 'Check that this host\'s fileinfo detects Resources file types as development does (read-only).';

    public function handle(MediaTypeDetector $detector): int
    {
        $this->line('PHP '.PHP_VERSION.', fileinfo '.(phpversion('fileinfo') ?: 'missing').', libmagic '.self::libmagic());
        $different = 0;
        foreach (DetectionSamples::all() as $label => [$filename, $bytes, $expected]) {
            $path = DetectionSamples::temporary($bytes);
            try {
                $detected = $detector->detect($path);
            } finally {
                unlink($path);
            }
            $decided = FileKind::judge(OriginalFilename::fromClient($filename)->extension, $detected);
            $ok = $decided === $expected;
            $different += $ok ? 0 : 1;
            $this->line(sprintf(
                '  %s %-26s detected %-75s %s',
                $ok ? '<fg=green>✓</>' : '<fg=red>✗</>',
                $label,
                $detected,
                $decided === null ? 'refused' : 'accepted as '.$decided->value,
            ).($ok ? '' : ' (development: '.($expected === null ? 'refused' : 'accepted as '.$expected->value).')'));
        }

        if ($different > 0) {
            $this->error("{$different} example(s) are decided differently on this host. Do not release File Cards here until this is understood.");

            return self::FAILURE;
        }
        $this->info('Every example is decided as in development.');

        return self::SUCCESS;
    }

    private static function libmagic(): string
    {
        ob_start();
        (new ReflectionExtension('fileinfo'))->info();
        preg_match('/libmagic\s*=>\s*(\S+)/', strip_tags((string) ob_get_clean()), $m);

        return $m[1] ?? 'unknown';
    }
}
