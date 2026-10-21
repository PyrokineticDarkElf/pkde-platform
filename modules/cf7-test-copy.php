<?php

/**
 * Plugin Name: Contact Form 7 test copy
 * Description: Production test mode. While the option `pkde_cf7_test_until` (a unix timestamp) is in the future,
 *              every Contact Form 7 mail (mail and mail_2) also goes as a Bcc to the site's admin_email, so a
 *              test submission on a live site is visible without changing the form's real recipients, subject
 *              or body. The option is deleted once it has expired. On for an hour:
 *              `wp option update pkde_cf7_test_until $(( $(date +%s) + 3600 ))`; off: `wp option delete pkde_cf7_test_until`.
 */

namespace App\MuPlugins;

const CF7_TEST_OPTION = 'pkde_cf7_test_until';

/** Is the test copy switched on? $until is the stored option (a unix timestamp, or anything else for off). */
function cf7_test_copy_active($until, int $now): bool
{
    return is_numeric($until) && (int) $until > $now;
}

/** Adds `Bcc: $admin` to the additional headers unless that address already receives the mail. */
function cf7_test_copy_components(array $components, string $admin): array
{
    $headers = (string) ($components['additional_headers'] ?? '');
    $already = preg_match('/^(?:cc|bcc):.*' . preg_quote($admin, '/') . '/im', $headers)
        || stripos((string) ($components['recipient'] ?? ''), $admin) !== false;

    if ($admin === '' || $already) {
        return $components;
    }

    $components['additional_headers'] = ($headers === '' ? '' : rtrim($headers) . "\n") . 'Bcc: ' . $admin;

    return $components;
}

// Returns early outside WordPress so the helpers above load in the Pest tests.
if (! function_exists('add_filter')) {
    return;
}

add_filter('wpcf7_mail_components', function ($components) {
    $until = get_option(CF7_TEST_OPTION);

    if (cf7_test_copy_active($until, time()) && is_email($admin = get_option('admin_email'))) {
        return cf7_test_copy_components($components, $admin);
    }

    return $components;
});

// Self-expiring: an old timestamp is removed on the next request, so a forgotten test copy cannot linger.
add_action('init', function () {
    $until = get_option(CF7_TEST_OPTION);

    if ($until !== false && ! cf7_test_copy_active($until, time())) {
        delete_option(CF7_TEST_OPTION);
    }
});

add_action('admin_notices', function () {
    $until = get_option(CF7_TEST_OPTION);

    if (current_user_can('manage_options') && cf7_test_copy_active($until, time())) {
        printf(
            '<div class="notice notice-warning"><p>%s</p></div>',
            esc_html(sprintf(__('CF7 test copy active until %s: every form mail is also sent to %s.', 'pkde-text'), wp_date('H:i', (int) $until), get_option('admin_email'))),
        );
    }
});
