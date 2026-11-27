<?php

/**
 * Plugin Name: Contact Form 7 preview in the block editor
 * Description: The block editor's preview of a Contact Form 7 form: a Shortcode or Paragraph block that holds only `[contact-form-7 ...]` shows the real form in the canvas, not the
 *              raw shortcode. The kit's editor script asks this REST route (pkde/v1/form-preview). Display only: scripts and the Turnstile widget are removed from the markup, no CF7
 *              script is loaded in the canvas, and the preview is inert, so nothing is submitted and no Turnstile request goes out. Only `contact-form-7` shortcodes are answered; any
 *              other text gets an empty answer. Anyone who can edit posts may ask. Moved out of the theme kit (phase 1); the editor script (resources/js/form-preview.jsx)
 *              stays in the kit, because it is built with the kit's own bundle. Route, namespace and answer as the kit had them. Defines PKDE_PLATFORM_CF7_EDITOR_PREVIEW;
 *              switch the module off with PKDE_PLATFORM_DISABLE=cf7-editor-preview and the kit's route runs again.
 */

namespace App\MuPlugins;

if (! defined('PKDE_PLATFORM_CF7_EDITOR_PREVIEW')) {
    define('PKDE_PLATFORM_CF7_EDITOR_PREVIEW', true);
}

/** The shortcode a text holds when it is only `[contact-form-7 ...]` (tags and entities removed), else ''. */
function cf7_preview_shortcode(string $text): string
{
    $text = trim(html_entity_decode(strip_tags($text), ENT_QUOTES | ENT_HTML5), " \t\n\r\0\x0B\u{00A0}");

    return preg_match('/^\[contact-form-7(?:\s[^\[\]]*)?\]$/', $text) === 1 ? $text : '';
}

/** @return array{html: string, styles: string[]} */
function cf7_preview(string $text): array
{
    $shortcode = cf7_preview_shortcode($text);

    if ($shortcode === '' || ! shortcode_exists('contact-form-7')) {
        return ['html' => '', 'styles' => []];
    }

    $html = do_shortcode($shortcode);
    $html = preg_replace('~<script\b.*?</script>~is', '', $html) ?? '';
    $html = preg_replace('~(<form\b[^>]*?)\saction="[^"]*"~i', '$1 action="#"', $html) ?? '';
    $html = preg_replace('~<div\b[^>]*\bcf-turnstile\b[^>]*>\s*</div>~is', '', $html) ?? '';
    $styles = function_exists('wpcf7_plugin_url') ? [add_query_arg('ver', defined('WPCF7_VERSION') ? WPCF7_VERSION : '1', wpcf7_plugin_url('includes/css/styles.css'))] : [];

    return ['html' => $html, 'styles' => $styles];
}

if (! function_exists('add_action')) {
    return; // loaded outside WordPress (the Pest tests)
}

add_action('rest_api_init', function () {
    register_rest_route('pkde/v1', '/form-preview', [
        'methods' => 'GET',
        'permission_callback' => fn() => current_user_can('edit_posts'),
        'args' => ['shortcode' => ['type' => 'string', 'required' => true]],
        'callback' => fn(\WP_REST_Request $request) => rest_ensure_response(cf7_preview((string) $request->get_param('shortcode'))),
    ]);
});
