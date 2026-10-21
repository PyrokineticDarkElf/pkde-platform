<?php

/**
 * Plugin Name: Contact Form 7 assets on demand
 * Description: Contact Form 7 loads its JS and CSS on every page. This turns that off sitewide and loads them
 *              only on a page that actually shows a form: one with the [contact-form-7] shortcode or block in
 *              its content, or the Contact page template's own form (form ID stored in the `_pkde_contact_form_id`
 *              post meta by bin/setup, rendered by template-contact.blade.php). A form rendered anywhere else
 *              (footer, widget, custom template) opts in with the `pkde_load_cf7_assets` filter.
 *
 * Method and filters are the plugin author's own recommendation:
 * https://contactform7.com/loading-javascript-and-stylesheet-only-when-it-is-necessary/
 * Re-test a form page after Contact Form 7 updates, since this relies on its wpcf7_enqueue_* functions.
 *
 * A form that renders without the opt-in still gets its assets, late (see the last filter). Cloudflare Turnstile is unaffected: its plugin enqueues its own script when it renders the widget in the form.
 */

namespace App\MuPlugins;

add_filter('wpcf7_load_js', '__return_false');
add_filter('wpcf7_load_css', '__return_false');

// Priority 20: Contact Form 7 registers its handles on wp_enqueue_scripts at priority 10.
add_action('wp_enqueue_scripts', function () {
    if (! function_exists('wpcf7_enqueue_scripts') || ! is_singular()) {
        return;
    }

    $post = get_queried_object();
    $hasForm = has_shortcode($post->post_content, 'contact-form-7')
        || get_post_meta($post->ID, '_pkde_contact_form_id', true);

    if (apply_filters('pkde_load_cf7_assets', (bool) $hasForm, $post)) {
        wpcf7_enqueue_scripts();
        wpcf7_enqueue_styles();
    }
}, 20);

// Safety net: a form rendered where nothing opted in (a theme template, a widget) would silently have no JS. Enqueue late
// instead: CF7's script is registered for the footer and late styles print in whichever head or footer is still open, so the form
// still works. The opt-in filter stays worthwhile for such pages: it guarantees the stylesheet is in the head.
add_filter('wpcf7_form_elements', function ($elements) {
    if (is_admin() || ! function_exists('wpcf7_enqueue_scripts') || wp_script_is('contact-form-7', 'enqueued')) {
        return $elements;
    }

    wpcf7_enqueue_scripts();
    wpcf7_enqueue_styles();

    if (defined('WP_DEBUG') && WP_DEBUG) {
        error_log('cf7-assets-on-demand: a Contact Form 7 form rendered on ' . ($_SERVER['REQUEST_URI'] ?? 'this request') . ' without the pkde_load_cf7_assets opt-in; assets were enqueued late. Add the opt-in for this page.');
    }

    return $elements;
});
