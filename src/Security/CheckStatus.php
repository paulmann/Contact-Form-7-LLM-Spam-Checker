<?php
/**
 * Check status enumeration.
 *
 * @package contact-form-7-spam-checker
 */

declare(strict_types=1);

namespace CF7Sec\Security;

defined('ABSPATH') || exit;

enum CheckStatus: string
{
    /** The check ran and found nothing suspicious. */
    case Pass = 'pass';

    /** The check ran and flagged the submission as spam. */
    case Fail = 'fail';

    /** The check did not run (disabled, missing data, whitelist...). */
    case Skip = 'skip';

    /** The check ran but errored (remote API failure, parse error...). */
    case Error = 'error';
}
