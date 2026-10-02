<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Http;

use App\Modules\Discussions\Domain\DiscussionTitle;
use App\Modules\Discussions\Domain\MessageBody;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Request SHAPE only (the field is present and is a string, not absurdly large). The rules (length, control characters) are
 * the domain's, which answers with `invalid_discussion_input`, so the limits are stated once. There is deliberately no
 * author field: the author is the signed-in caller.
 */
final class StartDiscussionRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:'.(DiscussionTitle::MAX_LENGTH * 4)],
            'body' => ['required', 'string', 'max:'.(MessageBody::MAX_LENGTH * 4)],
        ];
    }

    public function title(): string
    {
        return $this->string('title')->toString();
    }

    public function body(): string
    {
        return $this->string('body')->toString();
    }
}
