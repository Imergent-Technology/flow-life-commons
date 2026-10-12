<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Resources\Application\ManagedCardView;
use App\Modules\Resources\Application\ManagedPackView;

/** The audience-reading test data (MyResourcesTest): what each audience is, and is not, meant to see. */
final readonly class ReadingFixture
{
    public function __construct(
        public ManagedPackView $guardian,
        public ManagedCardView $guardianLive,
        public ManagedCardView $guardianDraftCard,
        public ManagedCardView $guardianFile,
        public ManagedCardView $guardianDraftFile,
        public ManagedPackView $volunteer,
        public ManagedPackView $member,
        public ManagedPackView $shared,
        public ManagedCardView $both,
        public ManagedCardView $volunteersOnly,
        public ManagedPackView $hollow,
        public ManagedCardView $hollowCard,
        public ManagedPackView $draft,
    ) {}
}
