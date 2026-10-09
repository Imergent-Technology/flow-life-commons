<?php

declare(strict_types=1);

namespace App\Modules\Relationships\Infrastructure;

use App\Modules\Relationships\Application\DefinitionSource;
use App\Modules\Relationships\Domain\InvalidRelationshipDefinition;

/**
 * Loads the production definition documents from this module's `Definitions` directory (ADR 0038, F2).
 * A document is data returned by the file. A file that returns anything else is named and refused.
 */
final class DirectoryDefinitionSource implements DefinitionSource
{
    public function documents(): array
    {
        $files = glob(dirname(__DIR__).'/Definitions/*.php') ?: [];
        sort($files);
        $documents = [];
        foreach ($files as $file) {
            $document = require $file;
            if (! is_array($document)) {
                throw new InvalidRelationshipDefinition(basename($file), 'a definition document must return an array');
            }
            /** @var array<string, mixed> $document */
            $documents[] = $document;
        }

        return $documents;
    }
}
