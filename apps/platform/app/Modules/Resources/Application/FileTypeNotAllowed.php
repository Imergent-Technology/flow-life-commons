<?php

declare(strict_types=1);

namespace App\Modules\Resources\Application;

use App\Modules\Resources\Domain\FileKind;
use RuntimeException;

/**
 * The upload is not a type the allowlist accepts, or its name's extension and its detected content do not agree on one
 * (`422 file_type_not_allowed`, ADR 0037 decision 64). The message lists what IS accepted and never echoes the file's name, claimed
 * type or detected type.
 */
final class FileTypeNotAllowed extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('That file type is not allowed, or the file\'s name does not match its contents. Allowed: '
            .implode(', ', array_map(static fn (string $e): string => '.'.$e, FileKind::allExtensions())).'.');
    }
}
