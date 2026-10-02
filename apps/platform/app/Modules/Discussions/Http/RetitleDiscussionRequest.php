<?php

declare(strict_types=1);

namespace App\Modules\Discussions\Http;

use App\Modules\Discussions\Domain\DiscussionTitle;
use Illuminate\Foundation\Http\FormRequest;

final class RetitleDiscussionRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['title' => ['required', 'string', 'max:'.(DiscussionTitle::MAX_LENGTH * 4)]];
    }

    public function title(): string
    {
        return $this->string('title')->toString();
    }
}
