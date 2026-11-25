<?php

/**
 * Plugin Name: Media pipeline
 * Description: Two behaviours of how a site's images are made and printed, moved out of the theme kit so a theme switch no longer changes them (WP-105, phase 1):
 *              - PNG sub-sizes WordPress generates are saved as WebP at quality 80 (an uploaded PNG's medium_large size is hundreds of KB as PNG, a fraction as WebP);
 *                the uploaded original is never touched; opt out with add_filter('pkde_png_to_webp', '__return_false'); applies to sub-sizes generated after it runs
 *                (regenerate older ones with `wp media regenerate --only-missing`);
 *              - stored content keeps root-relative upload URLs (/app/uploads/...); the <img> sources are made absolute on output (the_content, priority 20) so EWWW's
 *                WebP rewriter, which ignores root-relative URLs, can serve WebP for them too.
 *              Defines PKDE_PLATFORM_MEDIA, which the kit looks for: while it is defined the kit skips its own copy. Switch the module off with PKDE_PLATFORM_DISABLE=media
 *              and the kit's copy runs again. Same filter names and priorities as the kit.
 */

namespace App\MuPlugins;

if (! defined('PKDE_PLATFORM_MEDIA')) {
    define('PKDE_PLATFORM_MEDIA', true);
}

/** Output formats for generated image sub-sizes: PNG sub-sizes are saved as WebP. */
function media_png_output_formats(array $formats): array
{
    return ['image/png' => 'image/webp'] + $formats;
}

/** Quality for the WebP sub-sizes WordPress generates. */
function media_webp_quality(int $quality, string $mime): int
{
    return $mime === 'image/webp' ? 80 : $quality;
}

/** The <img> sources of stored content made absolute: `/app/uploads/` becomes `<home>/app/uploads/`. */
function media_absolute_upload_urls(string $content, string $home): string
{
    return preg_replace('#(<img\b[^>]*\ssrc=")(/app/uploads/)#', '$1' . rtrim($home, '/') . '/app/uploads/', $content) ?? $content;
}

if (! function_exists('add_filter')) {
    return; // loaded outside WordPress (the Pest tests): the functions above are all there is
}

add_filter('image_editor_output_format', static fn(array $formats): array => apply_filters('pkde_png_to_webp', true) ? media_png_output_formats($formats) : $formats);
add_filter('wp_editor_set_quality', static fn($quality, $mime) => apply_filters('pkde_png_to_webp', true) ? media_webp_quality((int) $quality, (string) $mime) : $quality, 10, 2);
add_filter('the_content', static fn(string $content): string => media_absolute_upload_urls($content, home_url('/')), 20);
