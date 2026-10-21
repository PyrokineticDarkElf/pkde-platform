<?php

/**
 * Plugin Name: Hide the WordPress version
 * Description: Stops the site advertising which WordPress version it runs: removes the generator <meta> tag and
 *              the feeds' generator, and strips the core version from the ?ver= query string of core scripts and
 *              styles. Plugin and theme versions in ?ver= are left alone (they cache-bust their own assets).
 *              Also drops the generator <meta> tag that listed plugins print about themselves (see below), and the
 *              X-Powered-By header (Acorn's and PHP's).
 *              Hardening only: it hides the number, it does not replace updating.
 */

namespace App\MuPlugins;

/**
 * Removes `ver=<core version>` from an asset URL, keeping every other query argument.
 *
 * Pure function (no WordPress calls) so it can be unit tested without a WordPress install.
 */
function strip_core_version(string $src, string $coreVersion): string
{
    $version = preg_quote($coreVersion, '/');
    $src = preg_replace("/\?ver={$version}&/", '?', $src);

    return preg_replace("/[?&]ver={$version}(?=&|$)/", '', $src);
}

// Plugins that print their own `<meta name="generator" content="<plugin> <version>">`. Each is added to wp_head when
// the plugin loads, which is after mu-plugins, so it can only be removed once plugins are loaded (init).
// speculation-rules: plsr_render_generator_meta_tag() in its hooks.php. Add a callback name here for any other plugin.
const PLUGIN_GENERATOR_CALLBACKS = ['plsr_render_generator_meta_tag'];

if (! function_exists('add_filter')) {
    return; // loaded outside WordPress (unit tests): the helper above is all there is
}

remove_action('wp_head', 'wp_generator');

// No X-Powered-By: Acorn adds "Acorn 6.2.0 (Laravel 13.12.0)" through wp_headers (its own acorn/powered_by filter
// switches that off) and PHP adds "PHP/<version>" when expose_php is on. header_remove() takes PHP's own off for any
// request that loads WordPress. A page served straight from a page cache never reaches this, and expose_php is a
// server setting (php.ini, not .user.ini), so an unproxied host should set expose_php=Off itself.
add_filter('acorn/powered_by', '__return_false');
header_remove('X-Powered-By');

add_action('init', static function () {
    foreach (PLUGIN_GENERATOR_CALLBACKS as $callback) {
        remove_action('wp_head', $callback);
    }
});
add_filter('the_generator', '__return_empty_string');

foreach (['script_loader_src', 'style_loader_src'] as $filter) {
    add_filter($filter, static fn($src) => is_string($src) ? strip_core_version($src, get_bloginfo('version')) : $src, 20);
}
