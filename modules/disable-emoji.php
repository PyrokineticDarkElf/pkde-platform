<?php

/**
 * Plugin Name: Disable emoji
 * Description: Drops WordPress's emoji detection script and styles (wp-emoji-release.js on every page, front end and admin). Browsers render emoji natively; the script only
 *              swaps in images for very old ones. Moved out of the theme kit (WP-105, phase 1) so a theme switch no longer brings the script back. Defines
 *              PKDE_PLATFORM_DISABLE_EMOJI, which the kit looks for; switch the module off with PKDE_PLATFORM_DISABLE=disable-emoji and the kit's copy runs again.
 */

namespace App\MuPlugins;

if (! defined('PKDE_PLATFORM_DISABLE_EMOJI')) {
    define('PKDE_PLATFORM_DISABLE_EMOJI', true);
}

if (! function_exists('add_action')) {
    return; // loaded outside WordPress (the Pest tests)
}

add_action('init', function () {
    remove_action('wp_head', 'print_emoji_detection_script', 7);
    remove_action('wp_print_styles', 'print_emoji_styles');
    remove_action('admin_print_scripts', 'print_emoji_detection_script');
    remove_action('admin_print_styles', 'print_emoji_styles');
});
