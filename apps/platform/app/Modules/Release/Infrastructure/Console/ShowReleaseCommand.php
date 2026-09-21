<?php

declare(strict_types=1);

namespace App\Modules\Release\Infrastructure\Console;

use App\Modules\Release\Application\ReleaseIdentity;
use App\Modules\Release\Application\ReleaseIdentityUnavailable;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;

/**
 * `release:show`: which release is this, and what would rolling it back involve.
 *
 * The question an operator asks twice — once after the `current` symlink is swapped, to confirm the
 * release they meant is the one serving, and once when something is wrong and they need the
 * `schema_rollback` classification a person decided when there was time to think (ADR 0027).
 *
 * READ-ONLY. It opens one file and writes nothing: no cache, no log, no state. It is safe on a live
 * host at any moment, including mid-incident, which is when it is most likely to be run.
 *
 * FAIL-CLOSED. There is no "unknown" release. Anything that stops it stating the identity with
 * certainty — no manifest, an unreadable one, malformed JSON, a missing or invalid identity field —
 * exits non-zero and says which field and why. A deployment that cannot identify itself is one nobody
 * can verify against the artifact they built or match to a backup, and reporting that as a shrug
 * would turn a broken release into a quiet one.
 *
 * In a development working tree there is no manifest, because a manifest is something
 * `./flow release build` writes into an artifact. That still exits non-zero — the honest answer to
 * "which release is this?" is that this is not a release — but the message says so plainly rather
 * than implying damage. `security:production-check` sets the same precedent: a production contract
 * command is expected to fail in development, and saying so is more useful than passing vacuously.
 */
final class ShowReleaseCommand extends Command
{
    protected $signature = 'release:show';

    protected $description = 'Show which release this deployment is running, from the manifest that shipped with it.';

    public function handle(Application $app): int
    {
        try {
            $release = ReleaseIdentity::read($app);
        } catch (ReleaseIdentityUnavailable $e) {
            return $this->unavailable($e, $app);
        }

        $this->newLine();
        $this->line('<options=bold>Release</>');
        $this->pair('release id', $release->releaseId);
        $this->pair('version', $release->version ?? '<fg=yellow>none</> (this build carries no version tag)');
        $this->pair('commit', $release->commit);
        $this->pair('built', $release->builtAt);
        $this->pair('built from', $release->ref);

        $this->newLine();
        $this->line('<options=bold>Rollback</>');
        $this->pair('schema_rollback', $this->rollback($release->schemaRollback));
        $this->pair('classified by', $release->classifiedBy);
        $this->pair(
            'previous release',
            $release->previous === null
                ? 'none — this was built as a FIRST release, so there is no earlier release to switch back to'
                : "{$release->previous['ref']} @ {$release->previous['commit']}",
        );

        $this->newLine();
        $this->line('<options=bold>Provenance</>');
        if ($release->provenanceMet()) {
            $this->line("  <fg=green>✓</> built from the annotated tag <options=bold>{$release->tag}</>, reachable from main");
        } else {
            // Loud, and on every inspection: an artifact built outside the rule is legitimate for a
            // rehearsal and is not a release. Nothing downstream re-checks this, so this is the place.
            $this->line('  <fg=yellow>!</> this release does NOT meet the ADR 0027 provenance rule (an annotated tag reachable from main)');
            $this->line('      annotated tag: '.$this->yesNo($release->annotatedTag)
                .', reachable from main: '.$this->yesNo($release->onMain)
                .', built with --allow-untagged: '.$this->yesNo($release->provenanceOverride));
        }
        $this->newLine();

        return self::SUCCESS;
    }

    private function unavailable(ReleaseIdentityUnavailable $e, Application $app): int
    {
        $this->newLine();
        $this->error($e->getMessage());

        if ($e->isSourceTree) {
            $this->line('  This looks like a source working tree rather than a deployed release: a manifest is written');
            $this->line('  into the artifact by `./flow release build`, and is never committed.');
            $this->newLine();
            $this->line('  <options=bold>If this IS a deployed release, it is not identifiable and must not be trusted.</>');
            $this->line('  Re-upload the artifact and verify its checksum (docs/runbooks/deployment.md).');
        } else {
            $this->line('  This release cannot state its own identity, so nothing can verify which code is serving,');
            $this->line('  and its rollback classification — the thing an operator acts on — is unreadable.');
            $this->newLine();
            $this->line('  Re-upload the artifact and verify its checksum before doing anything else.');
        }

        $this->newLine();
        $this->line('  Manifest path: '.ReleaseIdentity::path($app));
        $this->newLine();

        return self::FAILURE;
    }

    private function rollback(string $value): string
    {
        return match ($value) {
            'not-applicable' => '<fg=green>not-applicable</> — no new migrations; switch `current` back',
            'code-only' => '<fg=yellow>code-only</> — the previous release runs against this schema; switch `current` back',
            'restore-required' => '<fg=red>restore-required</> — the database must be restored too, and everything written since is lost',
            default => $value,
        };
    }

    private function pair(string $label, string $value): void
    {
        $this->line(sprintf('  %-17s %s', $label, $value));
    }

    private function yesNo(bool $value): string
    {
        return $value ? 'yes' : 'no';
    }
}
