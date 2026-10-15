<?php
/**
 * Throttled e-mail notifications for administrators.
 *
 * @package spam-checker-for-contact-form-7
 */

declare(strict_types=1);

namespace CF7Sec\Services;

use CF7Sec\Settings;

defined('ABSPATH') || exit;

/**
 * Sends admin e-mails with a per-event-type throttle so a spam storm
 * never floods the inbox.
 */
final class Mailer
{
    public function __construct(private readonly Settings $settings)
    {
    }

    /**
     * @param string $eventType Notification key, e.g. `ip_banned`.
     * @param string $subject
     * @param string $body
     */
    public function notify(string $eventType, string $subject, string $body): bool
    {
        if (!$this->settings->get('notifications.enabled', false)) {
            return false;
        }

        $events = (array) $this->settings->get('notifications.events', []);
        if ($eventType !== 'test' && !in_array($eventType, $events, true)) {
            return false;
        }

        $throttleKey = 'cf7sec_mail_' . md5($eventType);
        $throttleMin = max(1, (int) $this->settings->get('notifications.throttle_minutes', 60));
        if (get_transient($throttleKey)) {
            return false; // throttled
        }

        $to = (string) $this->settings->get('notifications.email', '');
        if (!is_email($to)) {
            $to = (string) get_option('admin_email');
        }

        $siteName = wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES);
        $subject  = sprintf('[%s] %s', $siteName, $subject);
        $headers  = ['Content-Type: text/plain; charset=UTF-8'];

        $sent = wp_mail($to, $subject, $body . "\n\n—\n" . admin_url('admin.php?page=cf7sec'), $headers);

        if ($sent) {
            set_transient($throttleKey, 1, $throttleMin * MINUTE_IN_SECONDS);
        }

        return $sent;
    }

    /**
     * Deliver a test e-mail (admin Tools page).
     */
    public function sendTest(string $to): bool
    {
        if (!is_email($to)) {
            return false;
        }

        return (bool) wp_mail(
            $to,
            sprintf(
                /* translators: %s: site name */
                __('[%s] Spam Checker Pro for Contact Form 7 — test notification', 'spam-checker-for-contact-form-7'),
                wp_specialchars_decode((string) get_option('blogname'), ENT_QUOTES)
            ),
            __("This is a test message from Spam Checker Pro for Contact Form 7.\nIf you can read it, e-mail notifications are configured correctly.", 'spam-checker-for-contact-form-7'),
            ['Content-Type: text/plain; charset=UTF-8']
        );
    }
}
