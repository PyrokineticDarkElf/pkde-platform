<?php

/*
Plugin Name:  Staging Mail Guard
Description:  Stops the staging site emailing real people. Production and local are untouched.
Version:      1.0.0
*/

/**
 * Staging is a clone of production: same contact forms, same recipient
 * addresses, and (once Post SMTP is configured) a real mail transport. Without
 * a guard, a test submission on staging emails the client.
 *
 * On WP_ENV === 'staging' only:
 * - STAGING_MAIL_TO unset (the default): every outgoing message is suppressed
 *   (wp_mail() returns false) and a line is written to the PHP error log (recipients masked, e.g. j***@example.com).
 * - STAGING_MAIL_TO=you@example.com in the server's .env: every message is
 *   delivered to that one address instead, subject prefixed "[staging]", Cc/Bcc
 *   dropped, the original recipients kept in an X-Staging-Original-To header.
 *
 * Hooks into core's `pre_wp_mail` and `wp_mail` filters, both of which Post
 * SMTP's own wp_mail() replacement applies, so it covers that plugin too.
 * Local development routes mail to Mailpit (mailpit-smtp.php) and production
 * is never affected: the whole file returns early unless WP_ENV is 'staging'.
 */

namespace App\MailGuard;

/**
 * Rewrites a wp_mail() argument array so the message goes to $to only.
 *
 * Pure function (no WordPress calls) so it can be unit tested without a
 * WordPress install.
 *
 * @param  array{to: string|array, subject: string, message: string, headers: string|array, attachments: string|array}  $atts
 * @return array same shape, redirected
 */
function redirect_message(array $atts, string $to): array
{
    $original = implode(', ', array_map('trim', (array) ($atts['to'] ?? [])));

    $headers = $atts['headers'] ?? [];
    $headers = is_array($headers) ? $headers : preg_split('/\r\n|\r|\n/', (string) $headers);
    $headers = array_values(array_filter(
        $headers,
        static fn($line) => trim((string) $line) !== '' && ! preg_match('/^\s*(cc|bcc)\s*:/i', (string) $line),
    ));
    $headers[] = 'X-Staging-Original-To: ' . str_replace(["\r", "\n"], '', $original);

    $subject = (string) ($atts['subject'] ?? '');

    $atts['to'] = $to;
    $atts['subject'] = str_starts_with($subject, '[staging]') ? $subject : '[staging] ' . $subject;
    $atts['headers'] = $headers;

    return $atts;
}

/**
 * Masks the addresses in a recipient value for the log: "Jane Doe <jane.doe@example.com>" and
 * "jane.doe@example.com" both become "j***@example.com". The log (error_log, then transcripts and
 * support tickets) only needs to show that mail was suppressed and roughly to whom, not who exactly.
 *
 * Pure function (no WordPress calls), like redirect_message().
 *
 * @param  string|array  $recipients
 */
function mask_recipients($recipients): string
{
    $masked = array_map(static function ($recipient) {
        $recipient = trim((string) $recipient);
        $address = preg_match('/<([^>]+)>/', $recipient, $m) ? $m[1] : $recipient;

        if (! str_contains($address, '@')) {
            return '***';
        }

        [$local, $domain] = explode('@', $address, 2);

        return substr($local, 0, 1) . '***@' . $domain;
    }, array_filter((array) $recipients, static fn($r) => trim((string) $r) !== ''));

    return $masked ? implode(', ', $masked) : '(none)';
}

if (! defined('WP_ENV') || WP_ENV !== 'staging') {
    return;
}

$redirect_to = trim((string) getenv('STAGING_MAIL_TO'));

if ($redirect_to === '' || ! is_email($redirect_to)) {
    add_filter('pre_wp_mail', static function ($short_circuit, array $atts) {
        error_log(sprintf(
            '[staging-mail-guard] suppressed "%s" to %s (set STAGING_MAIL_TO in .env to receive staging mail)',
            $atts['subject'] ?? '',
            mask_recipients($atts['to'] ?? []),
        ));

        return false;
    }, 1, 2);

    return;
}

add_filter('wp_mail', static fn(array $atts) => redirect_message($atts, $redirect_to), PHP_INT_MAX);
