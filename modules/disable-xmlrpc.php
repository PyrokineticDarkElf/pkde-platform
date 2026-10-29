<?php

/**
 * Plugin Name: XML-RPC off by default
 * Description: xmlrpc.php lets anyone who can guess a login try thousands of passwords in one request (system.multicall), use the site as a
 *              pingback proxy, and upload media without the sanitising the REST and admin uploaders get. None of the PKDE sites uses it, so it
 *              is OFF: WordPress's `xmlrpc_enabled` is false, every XML-RPC method is removed, the X-Pingback header is dropped, and the
 *              deploy tools deny the file in .htaccess. A site that really needs it (the WordPress mobile app, Jetpack) sets
 *              ALLOW_XMLRPC=true in .env or adds `add_filter('pkde_allow_xmlrpc', '__return_true')`. SVG uploads are refused over XML-RPC
 *              even then (safe-svg.php), because that route has no sanitiser hook.
 */

namespace App\MuPlugins;

/** True when the site opted in to XML-RPC: a truthy ALLOW_XMLRPC value, or the filter already said yes. */
function xmlrpc_allowed(string $setting, bool $filtered = false): bool
{
    return $filtered || in_array(strtolower(trim($setting)), ['1', 'true', 'yes', 'on'], true);
}

if (! function_exists('add_filter')) {
    return;
}

add_action('plugins_loaded', function () {
    if (xmlrpc_allowed(\Pkde\Platform\setting('ALLOW_XMLRPC'), (bool) apply_filters('pkde_allow_xmlrpc', false))) {
        return;
    }

    add_filter('xmlrpc_enabled', '__return_false');
    add_filter('xmlrpc_methods', '__return_empty_array');
    add_filter('wp_headers', function ($headers) {
        unset($headers['X-Pingback']);

        return $headers;
    });
    add_filter('pings_open', '__return_false', 99);
}, 1);
