<?php

/**
 * Plugin Name: Shortcodes that keep stored content from going stale
 * Description: [years_since year="2014" suffix="+"] prints the years since a year ("12+"), recomputed from the current year on every render. It sits in stored page content (the
 *              homepages of bp and rp), so it belongs to the content and not to the theme: switching theme no longer prints the raw tag (phase 1). Tag and attributes
 *              (year, suffix) as the sites had them. Defines PKDE_PLATFORM_SHORTCODES; switch the module off with PKDE_PLATFORM_DISABLE=shortcodes.
 */

namespace App\MuPlugins;

if (! defined('PKDE_PLATFORM_SHORTCODES')) {
    define('PKDE_PLATFORM_SHORTCODES', true);
}

/** The text of [years_since]: the whole years since `year` (never below 0) with the suffix, '' for a year before 1900 or none. */
function years_since(int $year, string $suffix, int $currentYear): string
{
    if ($year < 1900) {
        return '';
    }

    return max(0, $currentYear - $year) . $suffix;
}

if (! function_exists('add_shortcode')) {
    return; // loaded outside WordPress (the Pest tests)
}

// A theme that still registers its own [years_since] (bp and rp until their site move) registers after this and wins, with the same output.
add_shortcode('years_since', function ($atts) {
    $atts = shortcode_atts(['year' => '', 'suffix' => ''], $atts, 'years_since');

    return esc_html(years_since((int) $atts['year'], (string) $atts['suffix'], (int) wp_date('Y')));
});
