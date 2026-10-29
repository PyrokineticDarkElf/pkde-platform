<?php

/**
 * Plugin Name: Safe SVG Uploads
 * Description: Allows SVG uploads to the media library, sanitized against XSS via enshrined/svg-sanitize.
 */

namespace App\MuPlugins;

use enshrined\svgSanitize\Sanitizer;

/**
 * True when an upload is (or will be stored as) an SVG: the client-sent type says so, or the file name ends in .svg (any case, also after
 * other dots: logo.min.svg). Pure, so a test can load this file without WordPress.
 *
 * @param  array{name?: string, type?: string}  $file
 */
function svg_upload_needs_sanitising(array $file): bool
{
    $type = strtolower(trim((string) ($file['type'] ?? '')));
    $extension = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));

    return $type === 'image/svg+xml' || $extension === 'svg';
}

if (! function_exists('add_filter')) {
    return;
}

// WordPress blocks SVG uploads by default (XSS precaution — an unsanitised
// SVG can carry <script>/event-handler attributes). A real client logo and
// icon set is routinely SVG, so this is near-universal friction rather than
// a project-specific need — allow the mime type, but sanitize every upload
// through enshrined/svg-sanitize before it ever lands on disk.
add_filter('upload_mimes', function (array $mimes): array {
    // Not over XML-RPC: wp.uploadFile writes the bytes with wp_upload_bits(), which has no prefilter to sanitise them.
    if (defined('XMLRPC_REQUEST') && XMLRPC_REQUEST) {
        return $mimes;
    }

    $mimes['svg'] = 'image/svg+xml';

    return $mimes;
});

// wp_check_filetype_and_ext() re-derives the real mime type from the file's
// own content (via fileinfo), which doesn't recognise SVG — without this,
// uploads still get rejected even with upload_mimes allowing the extension.
add_filter('wp_check_filetype_and_ext', function (array $data, string $file, string $filename): array {
    if (! empty($data['ext']) && ! empty($data['type'])) {
        return $data;
    }

    $filetype = wp_check_filetype($filename, ['svg' => 'image/svg+xml']);

    if ($filetype['ext'] === 'svg') {
        $data['ext'] = 'svg';
        $data['type'] = 'image/svg+xml';
    }

    return $data;
}, 10, 3);

// The actual XSS mitigation: sanitize the file's own contents before
// WordPress moves it into place. The two filters above only get SVG
// recognised/allowed in the first place — they don't make it safe.
//
// Runs for every upload path: the media library and the classic uploader (wp_handle_upload_prefilter) AND the REST API and
// any raw-body or programmatic sideload (wp_handle_sideload_prefilter, which WordPress core uses for POST /wp/v2/media with a
// raw body). The decision is by the file name's extension as well as the client-sent type: the type is the visitor's word, so a
// file named x.svg sent as text/plain, or with no type at all, is still sanitised (WordPress stores it as image/svg+xml, from
// its extension).
$sanitizeSvg = function (array $file): array {
    if (empty($file['tmp_name']) || ! svg_upload_needs_sanitising($file)) {
        return $file;
    }

    $dirty = file_get_contents($file['tmp_name']);

    if ($dirty === false) {
        $file['error'] = __('Could not read the uploaded SVG file.', 'sage');

        return $file;
    }

    // Remote references (external <use href>, xlink:href, CSS url()) are removed too: an SVG that pulls in another origin can track whoever views it
    // and can carry the other file's script on browsers that load it.
    $sanitizer = new Sanitizer();
    $sanitizer->removeRemoteReferences(true);
    $clean = $sanitizer->sanitize($dirty);

    if ($clean === false) {
        $file['error'] = __('This SVG file could not be sanitised and was rejected.', 'sage');

        return $file;
    }

    file_put_contents($file['tmp_name'], $clean);

    return $file;
};

add_filter('wp_handle_upload_prefilter', $sanitizeSvg);
add_filter('wp_handle_sideload_prefilter', $sanitizeSvg);
