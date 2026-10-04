<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Domain\CardType;
use App\Modules\Resources\Domain\ExternalUri;
use App\Modules\Resources\Domain\ResourceText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A new Draft Card. Shape only; the domain and the document profile own the rules. `type` is `basic` or `external_link`: there is
 * no file Card yet, and a Card's Type is fixed for good at creation.
 */
final class CreateCardRequest extends FormRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(CardType::class)],
            'title' => ['required', 'string', 'max:'.(ResourceText::TITLE_MAX * 4)],
            'content' => ['sometimes', 'nullable', 'array'],
            'uri' => ['sometimes', 'nullable', 'string', 'max:'.(ExternalUri::MAX_LENGTH * 2)],
            'summary' => ['sometimes', 'nullable', 'string', 'max:'.(ResourceText::SUMMARY_MAX * 4)],
        ];
    }

    public function type(): CardType
    {
        return CardType::from($this->string('type')->toString());
    }

    public function title(): string
    {
        return $this->string('title')->toString();
    }

    /** @return array<mixed>|null */
    public function content(): ?array
    {
        $content = $this->input('content');

        return is_array($content) ? $content : null;
    }

    public function address(): ?string
    {
        $uri = $this->input('uri');

        return is_string($uri) ? $uri : null;
    }

    public function summary(): ?string
    {
        $summary = $this->input('summary');

        return is_string($summary) ? $summary : null;
    }
}
