<?php

declare(strict_types=1);

namespace App\Modules\Resources\Http;

use App\Modules\Resources\Application\IncomingFile;
use Illuminate\Foundation\Http\FormRequest;
use LogicException;

/**
 * A File Card's new file: a multipart form with one `file` part and nothing else that matters. No revision: replacing a file is not
 * an authored edit (ADR 0037, decision 56).
 */
final class ReplaceCardFileRequest extends FormRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        return ['file' => ['required', 'file']];
    }

    protected function prepareForValidation(): void
    {
        UploadedFileInput::refuseOversized($this->file('file'));
    }

    public function upload(): IncomingFile
    {
        return UploadedFileInput::incoming($this->file('file')) ?? throw new LogicException('validated as a file');
    }
}
