<?php

/**
 * Plugin Name: Contact Form 7 forms by key
 * Description: Finds a Contact Form 7 form by a lookup key kept on the form post as meta `_pkde_form_key` (TPL-186, WP-83), so a theme finds its form without relying on its title (a
 *              rename never breaks it) or on an ID (IDs differ between staging and production). `contact` is the standard key of the contact page's form; a site may add its own.
 *              bin/deploy/cf7-form-key.php (pkde-deploy) tags the forms of an existing site; bin/setup tags a new site's. Moved out of the theme kit (WP-105, phase 1): the kit's
 *              App\form_id() and App\form_shortcode() stay as thin wrappers that call these. Same meta key. Defines PKDE_PLATFORM_CF7_FORM_KEY; switch the module off with
 *              PKDE_PLATFORM_DISABLE=cf7-form-key and the kit's own lookup runs again.
 */

namespace App\MuPlugins;

if (! defined('PKDE_PLATFORM_CF7_FORM_KEY')) {
    define('PKDE_PLATFORM_CF7_FORM_KEY', true);
}

/** The ID of the Contact Form 7 form tagged with the lookup key, 0 when no form has it. Cached for the request. */
function cf7_form_id(string $key = 'contact'): int
{
    static $found = [];

    if (! isset($found[$key])) {
        $ids = function_exists('get_posts') ? get_posts([
            'post_type' => 'wpcf7_contact_form',
            'post_status' => 'any',
            'numberposts' => 1,
            'orderby' => 'ID',
            'order' => 'ASC',
            'fields' => 'ids',
            'meta_key' => '_pkde_form_key',
            'meta_value' => $key,
            'no_found_rows' => true,
        ]) : [];
        $found[$key] = (int) ($ids[0] ?? 0);
    }

    return $found[$key];
}

/** The `[contact-form-7 id="N"]` shortcode of the form with that key, or '' when the plugin is off or no form has the key. */
function cf7_form_shortcode(string $key = 'contact'): string
{
    $id = cf7_form_id($key);

    return $id > 0 && function_exists('shortcode_exists') && shortcode_exists('contact-form-7') ? '[contact-form-7 id="' . $id . '"]' : '';
}
