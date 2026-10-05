<?php

declare(strict_types=1);

namespace App\Modules\Resources\Domain;

use RuntimeException;

/** The file store could not do what it was asked (a write, a removal). Never carries a path or a filename in its message. */
final class FileStoreFailure extends RuntimeException {}
