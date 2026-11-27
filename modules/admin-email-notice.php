<?php

/**
 * Plugin Name: Admin email notice
 * Description: A site keeps its admin email in .env as WP_ADMIN_EMAIL (1Password holds the .env) and the deploy's drift check fails when the
 *              WordPress option differs. This only warns, on Settings > General, to administrators: nothing overrides admin_email and nothing is
 *              written. No address is printed. Does nothing unless WP_ADMIN_EMAIL is set to a valid address.
 */

namespace App\MuPlugins;

/** Should the warning show? Both addresses valid enough to compare, and different (case does not matter). Pure. */
function admin_email_differs_from_env(string $fromEnv, string $option): bool
{
    $fromEnv = trim($fromEnv);

    return $fromEnv !== '' && filter_var($fromEnv, FILTER_VALIDATE_EMAIL) !== false && strcasecmp($fromEnv, trim($option)) !== 0;
}

if (! function_exists('add_action')) {
    return;
}

add_action('admin_notices', function () {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;

    if (! $screen || $screen->id !== 'options-general' || ! current_user_can('manage_options')) {
        return;
    }

    if (! admin_email_differs_from_env(\Pkde\Platform\setting('WP_ADMIN_EMAIL'), (string) get_option('admin_email'))) {
        return;
    }

    printf(
        '<div class="notice notice-warning"><p>%s</p></div>',
        esc_html__('The administration email address here differs from WP_ADMIN_EMAIL in this site\'s .env, which is the address the site is meant to use. The deploy\'s drift check fails until they match: set it with WP-CLI (wp option update admin_email "the address from .env"), which skips the confirmation email, or change the .env value in 1Password first.', 'sage'),
    );
});
