<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Identity\Application\InvitationDelivery;
use App\Modules\Identity\Application\InvitationNotifier;
use App\Modules\Identity\Application\IssuedInvitation;
use Illuminate\Support\Facades\DB;

/**
 * Stands in for the mail adapter and records, for each message, who it was for, the token it carried, and how many
 * transactions were open when it was sent (compare with `baseline`: RefreshDatabase holds one open for the whole
 * test, so "committed" means "nothing the request opened is still open").
 */
final class FakeInvitationNotifier implements InvitationNotifier
{
    /** @var list<InvitationDelivery> the outcomes to give, in order; Sent when it runs out */
    public array $outcomes = [];

    /** @var list<array{email: string, token: string, level: int}> */
    public array $sent = [];

    public int $baseline;

    public static function install(): self
    {
        $fake = new self;
        $fake->baseline = DB::transactionLevel();
        app()->instance(InvitationNotifier::class, $fake);

        return $fake;
    }

    public function send(IssuedInvitation $invitation): InvitationDelivery
    {
        $this->sent[] = ['email' => $invitation->email, 'token' => $invitation->revealToken(), 'level' => DB::transactionLevel()];

        return array_shift($this->outcomes) ?? InvitationDelivery::Sent;
    }
}
