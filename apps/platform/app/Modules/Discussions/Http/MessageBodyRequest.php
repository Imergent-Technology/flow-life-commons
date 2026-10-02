<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Http;

use App\Modules\Discussions\Domain\MessageBody;
use Illuminate\Foundation\Http\FormRequest;

/** A reply or an edit: just the text. Shape only; the domain owns the rules. No author field exists. */
final class MessageBodyRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['body' => ['required', 'string', 'max:'.(MessageBody::MAX_LENGTH * 4)]];
    }

    public function body(): string
    {
        return $this->string('body')->toString();
    }
}
