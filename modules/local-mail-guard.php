<?php

/**
 * Plugin Name: Local mail guard
 * Description: A local site that was filled from a live database keeps Post SMTP's stored `postman_options` (the live SMTP host, login and
 *              sender) and the live Contact Form 7 recipients, so a local test form could send real mail to the real owner. When WP_ENV is
 *              'development' this module answers every read of `postman_options` with a fresh option that points Post SMTP at Mailpit
 *              (MAIL_HOST / MAIL_PORT): none of the stored values is used, so none of the live credentials is either. If MAIL_HOST is not
 *              set there is nowhere safe to deliver, so every message is dropped (wp_mail() returns false) and a line goes to the PHP error
 *              log. Staging (staging-mail-guard.php) and production are never touched: the whole file returns unless WP_ENV is 'development'.
 *
 * The same development-only guard answers the other live state a database import brings, at runtime and without writing to the database:
 * the site admin email and the WooCommerce / Contact Form 7 recipients become LOCAL_TEST_EMAIL (default local-test@example.com), Stripe is
 * forced into test mode with every non-test key and secret blanked, and requests to marketing and newsletter APIs (Mailchimp, Sender,
 * Brevo, Klaviyo, MailerLite, HubSpot) are refused. The stored values stay in the database for the scrub steps in "After importing a live
 * database" (docs/modules.md); this makes a forgotten step harmless.
 */

namespace App\MuPlugins;

/**
 * The Post SMTP options for local development: Mailpit, no login, no encryption. Pure: it takes nothing from the stored option, so live
 * credentials, tokens and recipients in it cannot leak into a local send.
 *
 * @return array<string, string|int>
 */
function local_mail_options(string $host, int $port, string $from): array
{
    return [
        'transport_type' => 'smtp',
        'auth_type' => 'none',
        'enc_type' => 'none',
        'hostname' => $host,
        'port' => $port,
        'sender_email' => $from,
        'envelope_sender' => $from,
    ];
}

/** WooCommerce Stripe settings for local use: test mode on, every key, secret, token and webhook secret except the test_ ones blanked. */
function local_stripe_settings(mixed $settings): mixed
{
    if (! is_array($settings)) {
        return $settings;
    }

    foreach ($settings as $key => $value) {
        if (is_string($value) && preg_match('/(key|secret|webhook|token)/i', (string) $key) && ! str_starts_with((string) $key, 'test_')) {
            $settings[$key] = '';
        }
    }
    $settings['testmode'] = 'yes';

    return $settings;
}

/** True for a URL on a marketing or newsletter service that a local site must not talk to. */
function local_blocked_host(string $url): bool
{
    $host = strtolower((string) parse_url($url, PHP_URL_HOST));

    return $host !== '' && (bool) preg_match('/(^|\.)(mailchimp\.com|list-manage\.com|sender\.net|brevo\.com|sendinblue\.com|klaviyo\.com|mailerlite\.com|hubapi\.com|hubspot\.com)$/', $host);
}

if (! function_exists('add_filter') || \Pkde\Platform\setting('WP_ENV') !== 'development') {
    return;
}

$mailSetting = fn(string $name): string => \Pkde\Platform\setting($name);

$testEmail = filter_var($mailSetting('LOCAL_TEST_EMAIL'), FILTER_VALIDATE_EMAIL) ?: 'local-test@example.com';

add_filter('pre_option_admin_email', fn() => $testEmail);
add_filter('pre_option_new_admin_email', fn() => $testEmail);

foreach (['new_order', 'cancelled_order', 'failed_order'] as $email) {
    add_filter("option_woocommerce_{$email}_settings", fn($settings) => is_array($settings) ? array_merge($settings, ['recipient' => $testEmail]) : $settings);
}
add_filter('wpcf7_mail_components', fn($components) => is_array($components) ? array_merge($components, ['recipient' => $testEmail]) : $components, 999);

add_filter('option_woocommerce_stripe_settings', __NAMESPACE__ . '\\local_stripe_settings');

add_filter('pre_http_request', function ($preempt, $args, $url) {
    return local_blocked_host((string) $url) ? new \WP_Error('pkde_local_blocked', 'Blocked on a local site: ' . (string) parse_url((string) $url, PHP_URL_HOST)) : $preempt;
}, 0, 3);

if ($host = $mailSetting('MAIL_HOST')) {
    // pre_option_: the stored row is never read, so its credentials never reach Post SMTP (or its settings screen).
    add_filter('pre_option_postman_options', function () use ($host, $mailSetting, $testEmail) {
        $from = $testEmail;

        return local_mail_options($host, (int) ($mailSetting('MAIL_PORT') ?: 1025), $from);
    });

    // mailpit-smtp.php sets PHPMailer's own transport; assert it again last for any plugin that sends through PHPMailer directly.
    add_action('phpmailer_init', function ($phpmailer) use ($host, $mailSetting) {
        $phpmailer->isSMTP();
        $phpmailer->Host = $host;
        $phpmailer->Port = (int) ($mailSetting('MAIL_PORT') ?: 1025);
        $phpmailer->SMTPAuth = false;
        $phpmailer->SMTPAutoTLS = false;
        $phpmailer->SMTPSecure = '';
        $phpmailer->Username = '';
        $phpmailer->Password = '';
    }, 999);

    return;
}

// No Mailpit configured: send nothing.
add_filter('pre_wp_mail', function () {
    error_log('local-mail-guard: mail dropped, MAIL_HOST is not set (a local site never sends real mail).');

    return false;
}, 0);
