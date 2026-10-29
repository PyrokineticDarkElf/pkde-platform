<?php

/**
 * Plugin Name: unfiltered_html for administrators only
 * Description: WordPress lets Editors (and, on a multisite-less install, only Administrators and Editors) save post content without kses, so an Editor
 *              account can store script in a page that runs for every visitor and for the Administrators who open it (stored XSS to
 *              administrator, MGR-67: tcs). The capability is removed from everyone who cannot `manage_options`; Administrators keep it for
 *              their GTM snippets, embed codes and forms. A site that wants Editors to keep it adds `add_filter('pkde_editors_unfiltered_html', '__return_true')`.
 */

namespace App\MuPlugins;

/** The capabilities map_meta_cap should return for unfiltered_html: unchanged for an administrator (or an opted-in site), do_not_allow for the rest. */
function unfiltered_html_caps(array $caps, string $cap, bool $isAdministrator, bool $optedIn = false): array
{
    return $cap === 'unfiltered_html' && ! $isAdministrator && ! $optedIn ? ['do_not_allow'] : $caps;
}

if (! function_exists('add_filter')) {
    return;
}

add_filter('map_meta_cap', function ($caps, $cap, $userId) {
    if ($cap !== 'unfiltered_html') {
        return $caps;
    }

    return unfiltered_html_caps(
        (array) $caps,
        (string) $cap,
        user_can((int) $userId, 'manage_options'),
        (bool) apply_filters('pkde_editors_unfiltered_html', false),
    );
}, 10, 3);
