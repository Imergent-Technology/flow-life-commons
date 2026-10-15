<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use RuntimeException;

/**
 * DEVELOPMENT AND TESTING ONLY. Keeps the sessions {@see E2eSessionSeeder} minted from idling out while a long browser run is under way
 * (`./flow test e2e` runs this every few minutes until the run ends).
 *
 * The platform ends a session after 30 minutes without a request (`session.lifetime`), judged on the session row's `last_activity`.
 * A minted session is first used whenever its journey is scheduled, and on a slow run that is later than 30 minutes after seeding, so
 * the journey would find a Sign-in page where its session was. This does for those sessions what a request would: it moves
 * `last_activity` to now, and nothing else.
 *
 * What it does not do, on purpose:
 * - It never changes a session's payload, so no proof is refreshed and a session minted stale stays stale (its journey is about that).
 * - It never revives a session that is already past the lifetime: such a session is gone, as it would be to a browser.
 * - It never touches the absolute 12-hour limit, which is measured from `authenticated_at`, nor any session it did not mint: a session a
 *   journey makes by signing in, or a minted one that a proof has since replaced (that one has a new id), is not in the list.
 */
final class E2eSessionKeepAlive extends Seeder
{
    public function run(): void
    {
        $kept = $this->keep();
        $this->command->info("E2E session keep-alive: {$kept} minted sessions kept.");
    }

    /** @return int how many minted sessions were kept (still within their lifetime, and so moved forward) */
    public function keep(): int
    {
        if (! $this->container->environment('local', 'testing')) {
            throw new RuntimeException('The e2e session keep-alive may only run in a local or testing environment.');
        }

        $file = storage_path(E2eSessionSeeder::IDS_FILE);
        if (! File::exists($file)) {
            return 0; // nothing was minted
        }
        $ids = array_values(array_filter((array) json_decode(File::get($file), true, 512, JSON_THROW_ON_ERROR), is_string(...)));
        $lifetime = config('session.lifetime');
        assert(is_int($lifetime));

        $now = time();

        return DB::table('sessions')
            ->whereIn('id', $ids)
            ->where('last_activity', '>=', $now - $lifetime * 60)
            ->update(['last_activity' => $now]);
    }
}
