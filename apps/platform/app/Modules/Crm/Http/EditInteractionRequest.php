<?php

declare(strict_types=1);

namespace App\Modules\Crm\Http;

use App\Modules\Crm\Domain\InteractionKind;
use Closure;
use DateTimeImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

final class EditInteractionRequest extends FormRequest
{
    use DeclaresInteractions;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return $this->interactionRules(required: false);
    }

    /** @return list<Closure(Validator): void> */
    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! $this->hasAny(['kind', 'body', 'occurred_at'])) {
                $validator->errors()->add('body', 'Send at least one of kind, body or occurred_at.');
            }
        }];
    }

    /** @return array{kind?: InteractionKind, body?: string, occurred_at?: DateTimeImmutable} */
    public function changes(): array
    {
        $changes = [];
        if ($this->has('kind')) {
            $changes['kind'] = InteractionKind::from($this->string('kind')->toString());
        }
        if ($this->has('body')) {
            $changes['body'] = $this->string('body')->toString();
        }
        if ($this->has('occurred_at')) {
            $changes['occurred_at'] = $this->instant($this->string('occurred_at')->toString());
        }

        return $changes;
    }
}
