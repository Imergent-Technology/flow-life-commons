<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Modules\Resources\Application\ManagedCardView;
use App\Modules\Resources\Application\ManagedPackView;

/** The privileged library's test data (ResourcesViewerLibraryTest): one of everything a viewer reads and delivery would not. */
final readonly class ViewerFixture
{
    public function __construct(
        public string $guides,
        public string $safety,
        public ManagedPackView $welcome,
        public ManagedCardView $live,
        public ManagedCardView $draftCard,
        public ManagedPackView $shared,
        public ManagedCardView $everyone,
        public ManagedCardView $narrowed,
        public ManagedPackView $draft,
        public ManagedCardView $draftOnly,
        public ManagedCardView $file,
        public ManagedPackView $empty,
        public ManagedPackView $loose,
    ) {}
}
