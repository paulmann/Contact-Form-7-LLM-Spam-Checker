<?php
/**
 * Excessive-links detector.
 *
 * @package spam-checker-for-contact-form-7
 */

declare(strict_types=1);

namespace CF7Sec\Security\Checks;

use CF7Sec\Security\CheckInterface;
use CF7Sec\Security\CheckResult;
use CF7Sec\Security\SubmissionContext;

defined('ABSPATH') || exit;

final class LinkSpamCheck implements CheckInterface
{
    public function id(): string
    {
        return 'link_spam';
    }

    public function label(): string
    {
        return __('Link-spam detection', 'spam-checker-for-contact-form-7');
    }

    public function enabled(SubmissionContext $context): bool
    {
        return $context->settings->isEnabled('link_spam');
    }

    public function run(SubmissionContext $context): CheckResult
    {
        $max = max(0, (int) $context->settings->get('checks.link_spam.max_links', 2));

        foreach ($context->textFields() as $field => $value) {
            $count = preg_match_all('#(?:https?://|ftp://|\bwww\.)#i', $value);
            if ($count > $max) {
                $links = [];
                if (preg_match_all('#https?://[^\s<>"\']{4,120}#i', $value, $m)) {
                    $links = array_slice($m[0], 0, 5);
                }

                return CheckResult::fail(
                    $this->id(),
                    sprintf(
                        /* translators: 1: link count, 2: limit, 3: field name */
                        __('%1$d links in field "%3$s" — above the limit of %2$d.', 'spam-checker-for-contact-form-7'),
                        $count,
                        $max,
                        $field
                    ),
                    'link_spam',
                    ['field' => $field, 'count' => $count, 'max' => $max, 'links' => $links]
                );
            }
        }

        return CheckResult::pass($this->id(), ['max' => $max]);
    }
}
