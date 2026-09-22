<?php

declare(strict_types=1);

namespace App\Modules\Release\Application;

use Illuminate\Contracts\Foundation\Application;
use JsonException;

/**
 * Which release is executing, read from the manifest that shipped with it (ADR 0027).
 *
 * WHERE IT READS FROM, and why that is the only defensible answer: `release.json` sits at the root of
 * the release directory, beside `artisan`, which is exactly Laravel's base path. So the manifest this
 * reads is the one packaged with the code that is running — not the newest release on the host, not
 * whatever `current` points at, not something in shared state that outlives the release it describes.
 * On a host serving `releases/<id>/public` through the `current` symlink, an operator who runs this
 * from a release directory gets that release's identity, which is the question they are asking.
 *
 * It is deliberately NOT derived from git. Production artifacts ship no `.git` (the validator refuses
 * one), the host has no repository, and a command that asked GitHub what a tag means would be
 * reporting the state of a server somewhere rather than the state of this directory.
 *
 * It is also deliberately not exposed over HTTP: `release.json` lives outside `public/` and the
 * document root denies `/release.json` outright. A commit sha is a small disclosure and the deployment
 * procedure has shell access, so there is nothing to buy by widening a public endpoint.
 *
 * Validation here overlaps with `scripts/release/artifact.php`, and the overlap is the point. That
 * validator judges a whole artifact at BUILD time, before it is ever written out; this validates the
 * identity fields at READ time, on a host, long afterwards — because a file that was correct when it
 * was packaged can be truncated by a failed upload or edited by someone in a hurry.
 */
final readonly class ReleaseIdentity
{
    public const array ROLLBACK_VALUES = ['not-applicable', 'code-only', 'restore-required'];

    /**
     * @param  string|null  $version  the annotated tag this was built from, when it had one
     * @param  array{ref: string, commit: string}|null  $previous  the release this one was built against
     */
    private function __construct(
        public string $releaseId,
        public ?string $version,
        public string $commit,
        public string $builtAt,
        public string $ref,
        public ?string $tag,
        public bool $annotatedTag,
        public bool $onMain,
        public bool $provenanceOverride,
        public ?array $previous,
        public string $schemaRollback,
        public string $classifiedBy,
    ) {}

    public static function path(Application $app): string
    {
        return $app->basePath('release.json');
    }

    /** @throws ReleaseIdentityUnavailable */
    public static function read(Application $app): self
    {
        $path = self::path($app);

        if (! is_file($path)) {
            throw ReleaseIdentityUnavailable::noManifest($path);
        }
        $raw = @file_get_contents($path);
        if ($raw === false) {
            throw ReleaseIdentityUnavailable::unreadable($path);
        }
        try {
            $manifest = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw ReleaseIdentityUnavailable::malformed($path, $e->getMessage());
        }
        if (! is_array($manifest) || array_is_list($manifest)) {
            throw ReleaseIdentityUnavailable::malformed($path, 'it is not a JSON object.');
        }

        return self::fromManifest($manifest);
    }

    /**
     * @param  array<array-key, mixed>  $manifest
     *
     * @throws ReleaseIdentityUnavailable
     */
    public static function fromManifest(array $manifest): self
    {
        if (($manifest['manifest_version'] ?? null) !== 1) {
            throw ReleaseIdentityUnavailable::incomplete('manifest_version', 'this build of the application reads manifest version 1.');
        }

        $source = self::object($manifest, 'source');
        $rollback = self::object($manifest, 'schema_rollback');
        $migrations = self::object($manifest, 'migrations');

        $releaseId = self::string($manifest, 'release_id');
        if (preg_match('/^\d{8}T\d{6}-[0-9a-f]{7}$/', $releaseId) !== 1) {
            throw ReleaseIdentityUnavailable::incomplete('release_id', "'$releaseId' is not a release id (UTC timestamp and short commit sha).");
        }
        $commit = self::string($source, 'source.commit');
        if (preg_match('/^[0-9a-f]{40}$/', $commit) !== 1) {
            throw ReleaseIdentityUnavailable::incomplete('source.commit', 'it is not a full 40-character commit sha.');
        }
        if (! str_ends_with($releaseId, '-'.substr($commit, 0, 7))) {
            throw ReleaseIdentityUnavailable::incomplete('release_id', "it does not name the commit it ships ($releaseId vs $commit).");
        }
        $builtAt = self::string($manifest, 'built_at');
        if (preg_match('/^\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$/', $builtAt) !== 1) {
            throw ReleaseIdentityUnavailable::incomplete('built_at', "'$builtAt' is not a UTC ISO-8601 timestamp.");
        }

        foreach (['annotated_tag', 'on_main', 'provenance_override'] as $flag) {
            if (! is_bool($source[$flag] ?? null)) {
                throw ReleaseIdentityUnavailable::incomplete("source.$flag", 'it must be true or false. Provenance is never reported as unknown.');
            }
        }

        $value = $rollback['value'] ?? null;
        if (! in_array($value, self::ROLLBACK_VALUES, true)) {
            // The one field an operator acts on under pressure. A release that cannot state it is a
            // release nobody can safely roll back, so this refuses rather than defaulting.
            throw ReleaseIdentityUnavailable::incomplete('schema_rollback.value', 'it must be one of: '.implode(', ', self::ROLLBACK_VALUES).'.');
        }

        return new self(
            releaseId: $releaseId,
            version: self::nullableString($manifest, 'version'),
            commit: $commit,
            builtAt: $builtAt,
            ref: self::string($source, 'source.ref'),
            tag: self::nullableString($source, 'source.tag'),
            annotatedTag: (bool) $source['annotated_tag'],
            onMain: (bool) $source['on_main'],
            provenanceOverride: (bool) $source['provenance_override'],
            previous: self::previous($migrations),
            schemaRollback: (string) $value,
            classifiedBy: self::string($rollback, 'schema_rollback.classified_by'),
        );
    }

    /** True when this release meets the ADR 0027 provenance rule: an annotated tag reachable from main. */
    public function provenanceMet(): bool
    {
        return $this->annotatedTag && $this->onMain && ! $this->provenanceOverride;
    }

    /**
     * @param  array<array-key, mixed>  $manifest
     * @return array<array-key, mixed>
     */
    private static function object(array $manifest, string $key): array
    {
        $value = $manifest[$key] ?? null;
        if (! is_array($value)) {
            throw ReleaseIdentityUnavailable::incomplete($key, 'it must be an object.');
        }

        return $value;
    }

    /**
     * Every identity field is checked for this, not only `classified_by`: `release:show` prints all of
     * them to an operator's terminal, and this command exists precisely to be trusted after the
     * artifact validator (scripts/release/artifact.php) has already run — "a file that was correct when
     * it was packaged can be truncated by a failed upload or edited by someone in a hurry" (class
     * docblock above). A control character surviving into any of these fields is a terminal-injection
     * risk on a command that is often run mid-incident.
     */
    private static function hasControlCharacter(string $value): bool
    {
        return preg_match('/[[:cntrl:]]/', $value) === 1;
    }

    /** @param array<array-key, mixed> $source */
    private static function string(array $source, string $field): string
    {
        $key = str_contains($field, '.') ? substr($field, strrpos($field, '.') + 1) : $field;
        $value = $source[$key] ?? null;
        if (! is_string($value) || trim($value) === '' || self::hasControlCharacter($value)) {
            throw ReleaseIdentityUnavailable::incomplete($field, 'it must be a non-empty string with no control characters.');
        }

        return $value;
    }

    /** @param array<array-key, mixed> $source */
    private static function nullableString(array $source, string $field): ?string
    {
        $key = str_contains($field, '.') ? substr($field, strrpos($field, '.') + 1) : $field;
        $value = $source[$key] ?? null;
        if ($value === null) {
            return null;
        }
        if (! is_string($value) || trim($value) === '' || self::hasControlCharacter($value)) {
            throw ReleaseIdentityUnavailable::incomplete($field, 'it must be a string with no control characters, or null.');
        }

        return $value;
    }

    /**
     * @param  array<array-key, mixed>  $migrations
     * @return array{ref: string, commit: string}|null
     */
    private static function previous(array $migrations): ?array
    {
        $previous = $migrations['previous'] ?? null;
        if ($previous === null) {
            return null; // a first release: there is no earlier code to switch back to
        }
        if (! is_array($previous)) {
            throw ReleaseIdentityUnavailable::incomplete('migrations.previous', 'it must be an object or null.');
        }
        $commit = self::string($previous, 'migrations.previous.commit');
        if (preg_match('/^[0-9a-f]{40}$/', $commit) !== 1) {
            throw ReleaseIdentityUnavailable::incomplete('migrations.previous.commit', 'it is not a full 40-character commit sha.');
        }

        return ['ref' => self::string($previous, 'migrations.previous.ref'), 'commit' => $commit];
    }
}
