<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Modules\Crm\Application\NewInteraction;
use App\Modules\Crm\Domain\InteractionKind;
use Illuminate\Foundation\Http\FormRequest;

final class RecordInteractionRequest extends FormRequest
{
    use DeclaresInteractions;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return $this->interactionRules(required: true);
    }

    public function newInteraction(): NewInteraction
    {
        $kind = $this->input('kind');
        $occurredAt = $this->input('occurred_at');

        return new NewInteraction(
            is_string($kind) ? InteractionKind::from($kind) : InteractionKind::Note,
            $this->string('body')->toString(),
            is_string($occurredAt) ? $this->instant($occurredAt) : null,
        );
    }
}
