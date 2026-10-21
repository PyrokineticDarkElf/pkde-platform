<?php

/**
 * Plugin Name: Safe SVG Uploads
 * Description: Allows SVG uploads to the media library, sanitized against XSS via enshrined/svg-sanitize.
 */

namespace App\MuPlugins;

use enshrined\svgSanitize\Sanitizer;

// WordPress blocks SVG uploads by default (XSS precaution — an unsanitised
// SVG can carry <script>/event-handler attributes). A real client logo and
// icon set is routinely SVG, so this is near-universal friction rather than
// a project-specific need — allow the mime type, but sanitize every upload
// through enshrined/svg-sanitize before it ever lands on disk.
add_filter('upload_mimes', function (array $mimes): array {
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
add_filter('wp_handle_upload_prefilter', function (array $file): array {
    if (($file['type'] ?? '') !== 'image/svg+xml' || empty($file['tmp_name'])) {
        return $file;
    }

    $dirty = file_get_contents($file['tmp_name']);

    if ($dirty === false) {
        $file['error'] = __('Could not read the uploaded SVG file.', 'sage');

        return $file;
    }

    $clean = (new Sanitizer())->sanitize($dirty);

    if ($clean === false) {
        $file['error'] = __('This SVG file could not be sanitised and was rejected.', 'sage');

        return $file;
    }

    file_put_contents($file['tmp_name'], $clean);

    return $file;
});
