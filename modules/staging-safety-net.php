<?php

/**
 * Plugin Name: Staging Safety Net
 * Description: Whenever WP_ENV is not production (staging, local), the site cannot be found, indexed or bought on, whatever wp-admin says.
 *              The rules are code, not options, so a plugin update or a click cannot undo them (WP-91, from tcs's staging-safeguards).
 *
 * - Search engines: `X-Robots-Tag: noindex, nofollow` on every response WordPress answers (images and files too, once the deploy has added
 *   its "Staging Noindex" block to the shared .htaccess: bin/deploy/activate-release.sh), `noindex, nofollow` in core's wp_robots and in
 *   Rank Math's robots meta, and a robots.txt that disallows everything and names no sitemap. The robots.txt filter runs after Rank Math's
 *   robots.txt editor and after content-signal.php, so neither can bring a rule or a sitemap line back. (WordPress's own "public" flag is not
 *   the guard: it is a wp-admin option, and a staging copy of a live site has it on.)
 * - Purchases: every WooCommerce payment gateway is removed and an order cannot be placed, so the basket and checkout can be reviewed but
 *   never completed.
 * - A label says this is staging. Staging only (a local site shows none, so local screenshots and tests are unchanged). A small pill in the
 *   bottom-right corner that taps pass through (pointer-events: none), not a bar: the kit's shop filter bar sticks to the bottom of the
 *   viewport on a phone with Apply and Reset, and a full-width label covered it (tcs, TCS-124). Fixed, so it never moves the page.
 *
 * Production is never touched: nothing is added when WP_ENV is 'production' or unset (Bedrock's default).
 */

namespace App\MuPlugins;

/** Is the safety net on for this WP_ENV? Everything except production; an unset WP_ENV is production. */
function staging_safety_net_on(string $env): bool
{
    return $env !== '' && $env !== 'production';
}

/** The whole robots.txt on a non-production site: nothing may be crawled, and no sitemap is named. */
function staging_robots_txt(): string
{
    return "User-agent: *\nDisallow: /\n";
}

/** The label's markup. A fixed pill, bottom-right, taps pass through. */
function staging_label_html(): string
{
    return '<div role="note" style="position:fixed;right:.5rem;bottom:.5rem;z-index:99999;max-width:9.5rem;padding:.3rem .6rem;border-radius:999px;'
        . 'background:#b5473a;color:#fff;font:600 10px/1.25 system-ui,sans-serif;letter-spacing:.06em;text-align:center;text-transform:uppercase;'
        . 'pointer-events:none;opacity:.92">Staging &ndash; not the shop</div>';
}

if (! function_exists('add_action') || ! staging_safety_net_on(\Pkde\Platform\setting('WP_ENV'))) {
    return;
}

// Priority 9999: the kit sends `noindex, follow` on a filtered shop URL, and on staging the stronger answer must be the last one.
add_action('send_headers', function () {
    header('X-Robots-Tag: noindex, nofollow', true);
}, 9999);

add_filter('wp_robots', fn() => ['noindex' => true, 'nofollow' => true], 9999);

// Rank Math removes every wp_robots filter and prints its own robots tag, so it is set here as well.
add_filter('rank_math/frontend/robots', fn() => ['index' => 'noindex', 'follow' => 'nofollow'], 9999);

add_filter('robots_txt', fn() => staging_robots_txt(), 9999);

// Nothing to pay with: the checkout can be viewed, never completed.
add_filter('woocommerce_available_payment_gateways', '__return_empty_array', 9999);
add_action('woocommerce_after_checkout_validation', function ($data, $errors) {
    $errors->add('staging', __('This is a staging site: orders cannot be placed.', 'sage'));
}, 9999, 2);
add_filter('woocommerce_order_button_html', '__return_empty_string', 9999);

if (\Pkde\Platform\setting('WP_ENV') === 'staging') {
    add_action('wp_footer', fn() => print staging_label_html(), 9999);
}
