<?php

/**
 * Plugin Name: Disable emoji
 * Description: Drops WordPress's emoji detection script and styles (wp-emoji-release.js on every page, front end and admin). Browsers render emoji natively; the script only
 *              swaps in images for very old ones. Moved out of the theme kit (phase 1) so a theme switch no longer brings the script back. Defines
 *              PKDE_PLATFORM_DISABLE_EMOJI, which the kit looks for; switch the module off with PKDE_PLATFORM_DISABLE=disable-emoji and the kit's copy runs again.
 */

namespace App\MuPlugins;

if (! defined('PKDE_PLATFORM_DISABLE_EMOJI')) {
    define('PKDE_PLATFORM_DISABLE_EMOJI', true);
}

/**
 * The hooks that print WordPress's emoji script and styles: [hook, function, priority]. WordPress 6.4 moved the styles to wp_enqueue_emoji_styles on
 * wp_enqueue_scripts (and admin_enqueue_scripts), so removing print_emoji_styles alone left an inline stylesheet on every page; the older hooks stay for older cores.
 *
 * @return list<array{0: string, 1: string, 2: int}>
 */
function emoji_hooks(): array
{
    return [
        ['wp_head', 'print_emoji_detection_script', 7],
        ['wp_print_styles', 'print_emoji_styles', 10],
        ['admin_print_scripts', 'print_emoji_detection_script', 10],
        ['admin_print_styles', 'print_emoji_styles', 10],
        ['wp_enqueue_scripts', 'wp_enqueue_emoji_styles', 10],
        ['admin_enqueue_scripts', 'wp_enqueue_emoji_styles', 10],
    ];
}

if (! function_exists('add_action')) {
    return; // loaded outside WordPress (the Pest tests)
}

add_action('init', function () {
    foreach (emoji_hooks() as [$hook, $function, $priority]) {
        remove_action($hook, $function, $priority);
    }
});
