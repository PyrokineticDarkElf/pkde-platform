<?php

/**
 * Plugin Name: Schema Absolute URLs
 * Description: Rank Math writes a logo or image URL as given in its settings, and a path such as /app/uploads/logo.png is not a valid schema.org URL
 *              (Rich Results and crawlers want an absolute one;B-06 / T-4). This turns every site-relative or protocol-relative url, contentUrl,
 *              thumbnailUrl, logo and image in the JSON-LD into an absolute one on the site's own origin. Absolute URLs are never touched.
 */

namespace App\MuPlugins;

/**
 * @param  mixed  $data  the JSON-LD (or a part of it)
 * @param  string  $origin  scheme and host of the site, no trailing slash, e.g. https://example.com
 * @return mixed
 */
function schema_absolute_urls($data, string $origin)
{
    if (! is_array($data)) {
        return $data;
    }

    foreach ($data as $key => $value) {
        if (is_array($value)) {
            $data[$key] = schema_absolute_urls($value, $origin);
        } elseif (is_string($value) && in_array($key, ['url', 'contentUrl', 'thumbnailUrl', 'logo', 'image'], true)) {
            if (str_starts_with($value, '//')) {
                $data[$key] = (string) parse_url($origin, PHP_URL_SCHEME) . ':' . $value;
            } elseif (str_starts_with($value, '/')) {
                $data[$key] = $origin . $value;
            }
        }
    }

    return $data;
}

if (! function_exists('add_filter')) {
    return; // loaded outside WordPress (the Pest tests): the function above is all there is
}

// After local-business-schema (99), which can add an image of its own.
add_filter('rank_math/json_ld', function ($data) {
    $parts = parse_url(home_url('/'));

    return empty($parts['host']) ? $data : schema_absolute_urls($data, ($parts['scheme'] ?? 'https') . '://' . $parts['host'] . (isset($parts['port']) ? ':' . $parts['port'] : ''));
}, 100);
