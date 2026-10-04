<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

/** Whether content changes may rewrite a Card's stored summary (ADR 0037, decision 34). */
enum SummaryMode: string
{
    case Derived = 'derived';
    case Custom = 'custom';
}
