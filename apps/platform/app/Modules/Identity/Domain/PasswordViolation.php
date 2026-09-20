<?php

declare(strict_types=1);

namespace App\Modules\Identity\Domain;

/**
 * Why a proposed password is refused. Reason CLASSES only: they say what kind of thing is wrong,
 * never anything about the text, so they are safe to return to a caller and to record.
 */
enum PasswordViolation: string
{
    /** Fewer than PlainPassword::MIN_CODE_POINTS Unicode code points. */
    case TooShort = 'too_short';

    /** More than PlainPassword::MAX_BYTES UTF-8 bytes: bcrypt would silently ignore the rest. */
    case TooLong = 'too_long';

    /** Not well-formed UTF-8, so it cannot be normalised. */
    case NotUtf8 = 'not_utf8';

    /** Contains a NUL byte, at which bcrypt would silently stop reading. */
    case ContainsNul = 'contains_nul';

    /** Known from public breaches or a common-password list. Decided by the Application layer. */
    case Compromised = 'compromised';
}
