<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A file download's one input: `disposition`, `attachment` (the default) or `inline`. Inline is a REQUEST, honoured only for a file
 * that may be shown inline (PDF and the raster images, ADR 0037 decision 66); any other file is still sent as an attachment.
 */
final class DownloadFileRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['disposition' => ['sometimes', 'nullable', 'string', 'in:attachment,inline']];
    }

    public function wantsInline(): bool
    {
        return BlankInput::optional($this->query('disposition')) === 'inline';
    }
}
