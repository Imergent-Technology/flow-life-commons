<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\IncomingFile;
use App\Modules\Resources\Domain\CardType;
use App\Modules\Resources\Domain\ExternalUri;
use App\Modules\Resources\Domain\InvalidResourceInput;
use App\Modules\Resources\Domain\ResourceText;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use JsonException;

/**
 * A new Draft Card. Shape only; the domain, the document profile and the file allowlist own the rules. A Card's Type is fixed for good
 * at creation.
 *
 * `basic` and `external_link` Cards are created with a JSON body. A `file` Card is created with a MULTIPART form (ADR 0037, the
 * contract's route table), because its file comes with it: the `file` part is required for a `file` Card and refused for any other.
 * In a form every field is text, so a `file` Card's optional `content` is the document's JSON text, decoded here (a blank one is
 * none); in a JSON body `content` is the document itself, as before.
 */
final class CreateCardRequest extends FormRequest
{
    /** The longest `content` text a form may carry; the profile's own 256 KiB limit on the stored document still applies after. */
    public const int CONTENT_TEXT_MAX = 1024 * 1024;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $isFile = $this->input('type') === CardType::File->value;

        return [
            'type' => ['required', Rule::enum(CardType::class)],
            'title' => ['required', 'string', 'max:'.(ResourceText::TITLE_MAX * 4)],
            'content' => $this->isJson() || is_array($this->input('content'))
                ? ['sometimes', 'nullable', 'array']
                : ['sometimes', 'nullable', 'string', 'max:'.self::CONTENT_TEXT_MAX],
            'uri' => ['sometimes', 'nullable', 'string', 'max:'.(ExternalUri::MAX_LENGTH * 2)],
            'summary' => ['sometimes', 'nullable', 'string', 'max:'.(ResourceText::SUMMARY_MAX * 4)],
            'file' => $isFile ? ['required', 'file'] : ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        UploadedFileInput::refuseOversized($this->file('file'));
    }

    public function type(): CardType
    {
        return CardType::from($this->string('type')->toString());
    }

    public function title(): string
    {
        return $this->string('title')->toString();
    }

    /**
     * @return array<mixed>|null
     *
     * @throws InvalidResourceInput
     */
    public function content(): ?array
    {
        $content = $this->input('content');
        if (is_array($content)) {
            return $content;
        }
        $text = BlankInput::optional($content);
        if ($text === null) {
            return null;
        }

        try {
            $decoded = json_decode($text, true, 64, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $decoded = null;
        }
        if (! is_array($decoded)) {
            throw new InvalidResourceInput('content', 'The content is not in the allowed format: it must be a document.', InvalidResourceInput::CONTENT);
        }

        return $decoded;
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

    public function upload(): ?IncomingFile
    {
        return UploadedFileInput::incoming($this->file('file'));
    }
}
