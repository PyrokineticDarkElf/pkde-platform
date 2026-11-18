<?php

// The module defines its pure helpers and returns before registering any hook when WordPress is not loaded.
require_once dirname(__DIR__, 2) . '/modules/staging-safety-net.php';

use function App\MuPlugins\staging_label_html;
use function App\MuPlugins\staging_robots_txt;
use function App\MuPlugins\staging_safety_net_on;

it('is on for every environment except production, and an unset one is production', function () {
    expect(staging_safety_net_on('staging'))->toBeTrue()
        ->and(staging_safety_net_on('development'))->toBeTrue()
        ->and(staging_safety_net_on('local'))->toBeTrue()
        ->and(staging_safety_net_on('production'))->toBeFalse()
        ->and(staging_safety_net_on(''))->toBeFalse();
});

it('serves a robots.txt that disallows everything and names no sitemap', function () {
    expect(staging_robots_txt())->toBe("User-agent: *\nDisallow: /\n")
        ->and(strtolower(staging_robots_txt()))->not->toContain('sitemap');
});

it('draws the label as a small bottom-right pill that taps pass through', function () {
    expect(staging_label_html())->toContain('position:fixed')->toContain('right:.5rem')->toContain('bottom:.5rem')
        ->toContain('pointer-events:none')->toContain('max-width:9.5rem')->not->toContain('left:0');
});

it('sets the header, both robots tags, robots.txt and the payment lock, last, and the label on staging only', function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/modules/staging-safety-net.php');

    expect($source)->toContain("header('X-Robots-Tag: noindex, nofollow', true);\n}, 9999);")
        ->and($source)->toContain("add_filter('wp_robots'")
        ->and($source)->toContain("add_filter('rank_math/frontend/robots'")
        ->and($source)->toContain("add_filter('robots_txt', fn() => staging_robots_txt(), 9999)")
        ->and($source)->toContain("add_filter('woocommerce_available_payment_gateways', '__return_empty_array', 9999)")
        ->and($source)->toContain("if (\\Pkde\\Platform\\setting('WP_ENV') === 'staging') {\n    add_action('wp_footer'");
});
