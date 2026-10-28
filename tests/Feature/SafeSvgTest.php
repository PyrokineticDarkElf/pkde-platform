<?php

// The module defines its pure helper and returns early outside WordPress, so it loads here without WordPress.
require_once dirname(__DIR__, 2) . '/modules/safe-svg.php';

use function App\MuPlugins\svg_upload_needs_sanitising;

it('sanitises an upload whose client type says SVG', function () {
    expect(svg_upload_needs_sanitising(['name' => 'logo.png', 'type' => 'image/svg+xml']))->toBeTrue()
        ->and(svg_upload_needs_sanitising(['type' => ' IMAGE/SVG+XML ']))->toBeTrue();
});

it('sanitises a .svg file whatever type the client sent, or none', function () {
    foreach (['text/plain', 'application/octet-stream', 'image/png', ''] as $type) {
        expect(svg_upload_needs_sanitising(['name' => 'logo.svg', 'type' => $type]))->toBeTrue();
    }

    expect(svg_upload_needs_sanitising(['name' => 'logo.svg']))->toBeTrue()
        ->and(svg_upload_needs_sanitising(['name' => 'LOGO.SVG', 'type' => 'text/html']))->toBeTrue()
        ->and(svg_upload_needs_sanitising(['name' => 'logo.min.svg', 'type' => 'text/xml']))->toBeTrue();
});

it('leaves other files alone', function () {
    expect(svg_upload_needs_sanitising(['name' => 'photo.jpg', 'type' => 'image/jpeg']))->toBeFalse()
        ->and(svg_upload_needs_sanitising(['name' => 'svg', 'type' => 'text/plain']))->toBeFalse()
        ->and(svg_upload_needs_sanitising(['name' => 'notes.svg.txt', 'type' => 'text/plain']))->toBeFalse()
        ->and(svg_upload_needs_sanitising([]))->toBeFalse();
});

it('hooks both the upload and the sideload prefilter (the REST API raw-body upload uses the sideload one)', function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/modules/safe-svg.php');

    expect($source)->toContain("add_filter('wp_handle_upload_prefilter'")
        ->and($source)->toContain("add_filter('wp_handle_sideload_prefilter'");
});
