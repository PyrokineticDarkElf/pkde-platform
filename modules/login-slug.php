<?php

/**
 * Plugin Name: Login slug from .env
 * Description: WPS Hide Login keeps the login URL in the `whl_page` option, which a database never shares between staging and
 *              production, so the two drift. When LOGIN_SLUG is set in .env (config/application.php defines the constant), that
 *              value is the login slug on every read of the option, a different value cannot be saved (Settings > General says
 *              so), and the field is read-only. Does nothing when LOGIN_SLUG is unset. Roll-out per site: copy production's
 *              current `whl_page` into LOGIN_SLUG in BOTH shared/.env files on the server, without printing it (launch guide).
 */

namespace App\MuPlugins;

/**
 * The login slug a LOGIN_SLUG value stands for, '' when it is unusable (empty, or containing wp-login). Runtime passes WordPress's
 * sanitize_title_with_dashes, which is what WPS Hide Login applies to its own field; without it a plain ASCII slug is built.
 */
function login_slug_clean(string $raw, ?callable $sanitize = null): string
{
    $slug = $sanitize !== null
        ? (string) $sanitize($raw)
        : trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower(trim($raw))), '-');

    return $slug === '' || str_contains($slug, 'wp-login') ? '' : $slug;
}

if (! function_exists('add_filter')) {
    return;
}

$loginSlug = defined('LOGIN_SLUG') ? login_slug_clean((string) LOGIN_SLUG, 'sanitize_title_with_dashes') : '';

if (defined('LOGIN_SLUG') && (string) LOGIN_SLUG !== '' && $loginSlug === '') {
    add_action('admin_notices', function () {
        if (current_user_can('manage_options')) {
            printf('<div class="notice notice-error"><p>%s</p></div>', esc_html__('LOGIN_SLUG in the .env file is not usable as a login slug (empty after cleaning, or it contains wp-login), so it is ignored.', 'pkde-text'));
        }
    });
}

if ($loginSlug === '') {
    return;
}

// Every get_option('whl_page') (the plugin's own reads included) answers with the constant, without touching the stored row.
add_filter('pre_option_whl_page', fn() => $loginSlug);

// A save of the field changes nothing: answering with the value already in force makes update_option a no-op (no database write).
add_filter('pre_update_option_whl_page', function ($value) use ($loginSlug) {
    if ((string) $value !== $loginSlug && function_exists('add_settings_error')) {
        add_settings_error('whl_page', 'pkde_login_slug_managed', __('The login URL is managed in the .env file (LOGIN_SLUG) and was not changed.', 'pkde-text'), 'warning');
    }

    return $loginSlug;
});

add_action('admin_notices', function () {
    global $pagenow;

    if ($pagenow === 'options-general.php' && current_user_can('manage_options')) {
        printf('<div class="notice notice-info"><p>%s</p></div>', esc_html__('The login URL is managed in the .env file (LOGIN_SLUG), so it is the same on staging and production. Change it there; it cannot be changed here.', 'pkde-text'));
    }
});

add_action('admin_footer-options-general.php', function () {
    echo '<script>document.addEventListener("DOMContentLoaded",function(){var f=document.getElementById("whl_page");if(f){f.readOnly=true;}});</script>';
});
