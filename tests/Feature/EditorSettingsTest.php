<?php

require_once dirname(__DIR__, 2) . '/modules/editor-settings.php';

use function App\MuPlugins\editor_allowed_blocks;
use function App\MuPlugins\editor_block_palettes;
use function App\MuPlugins\editor_settings;
use function App\MuPlugins\editor_settings_state;
use function App\MuPlugins\editor_theme_json;

$registered = [
    'core/paragraph', 'core/heading', 'core/group', 'core/image', 'core/buttons', 'core/button', 'core/list', 'core/spacer', 'core/block',
    'core/shortcode', 'core/html', 'core/cover', 'core/query', 'core/post-template', 'core/verse', 'core/calendar', 'core/embed',
    'sage/hero', 'sage/section', 'bb/latest-reviews', 'wpcf7/contact-form-selector', 'rank-math/faq-block',
    'woocommerce/cart', 'woocommerce/checkout', 'woocommerce/cart-items-block', 'woocommerce/product-collection',
];

/** WP-100 P1-5, run as an Editor and as an Administrator. */
it('gives an Editor the brand swatches only, no unlocking, and no custom colours or gradients', function () use ($registered) {
    $settings = editor_settings(['canLockBlocks' => true, '__experimentalFeatures' => ['color' => ['custom' => true, 'customGradient' => true, 'palette' => ['theme' => []]]]], false);

    expect($settings['canLockBlocks'])->toBeFalse()
        ->and($settings['disableCustomColors'])->toBeTrue()
        ->and($settings['disableCustomGradients'])->toBeTrue()
        ->and($settings['__experimentalFeatures']['color']['custom'])->toBeFalse()
        ->and($settings['__experimentalFeatures']['color']['customGradient'])->toBeFalse()
        ->and($settings['__experimentalFeatures']['color']['palette'])->toBe(['theme' => []]) // everything else in the settings is kept
        ->and(editor_theme_json(['settings' => ['color' => ['custom' => true]]], false)['settings']['color'])->toBe(['custom' => false, 'customGradient' => false]);
});

it('gives an Administrator the full picker and the lock', function () {
    $settings = editor_settings(['canLockBlocks' => false], true);

    expect($settings['canLockBlocks'])->toBeTrue()
        ->and($settings['disableCustomColors'])->toBeFalse()
        ->and($settings['__experimentalFeatures']['color']['custom'])->toBeTrue()
        ->and(editor_theme_json([], true)['settings']['color'])->toBe(['custom' => true, 'customGradient' => true]);
});

it('limits an Editor to the curated core blocks plus every kit, site and plugin block', function () use ($registered) {
    $allowed = editor_allowed_blocks($registered, false);

    expect($allowed)->toContain('core/paragraph', 'core/heading', 'core/group', 'core/image', 'core/buttons', 'core/button', 'core/list', 'core/spacer', 'core/block')
        ->and($allowed)->toContain('sage/hero', 'sage/section', 'bb/latest-reviews', 'wpcf7/contact-form-selector', 'rank-math/faq-block')
        ->and($allowed)->not->toContain('core/shortcode', 'core/html', 'core/cover', 'core/query', 'core/post-template', 'core/verse', 'core/calendar', 'core/embed')
        ->and($allowed)->not->toContain('woocommerce/cart', 'woocommerce/checkout', 'woocommerce/product-collection');
});

it('gives an Administrator shortcode, HTML, the query family, cover and the cart and checkout blocks on top', function () use ($registered) {
    $allowed = editor_allowed_blocks($registered, true);

    expect($allowed)->toContain('core/shortcode', 'core/html', 'core/cover', 'core/query', 'core/post-template', 'woocommerce/cart', 'woocommerce/checkout', 'woocommerce/cart-items-block', 'core/paragraph', 'sage/hero')
        ->and($allowed)->not->toContain('core/verse', 'core/calendar', 'core/embed', 'woocommerce/product-collection'); // curated for everyone
});

it('lets a site extend the core list and never lists a block that is not registered', function () use ($registered) {
    expect(editor_allowed_blocks($registered, false, ['core/paragraph', 'core/embed', 'core/not-a-block']))->toContain('core/embed')->not->toContain('core/not-a-block')->not->toContain('core/heading');
});

it('restricts the Button block to the button swatches and text blocks to the text-safe ones, and leaves a data set without swatches alone', function () {
    $palette = array_map(fn($slug) => ['slug' => $slug, 'name' => $slug, 'color' => '#000'], ['white', 'black', 'sage-50', 'sage-200', 'sage-600', 'sage-800']);
    $data = editor_block_palettes(['settings' => ['color' => ['palette' => $palette]]], ['button' => ['white', 'black', 'sage-600'], 'text' => ['black', 'sage-800', 'white']]);
    $slugs = fn($block) => array_column($data['settings']['blocks'][$block]['color']['palette'], 'slug');

    expect($slugs('core/button'))->toBe(['white', 'black', 'sage-600'])
        ->and($slugs('core/paragraph'))->toBe(['white', 'black', 'sage-800']) // the theme's order, not the list's
        ->and($slugs('core/heading'))->toBe(['white', 'black', 'sage-800'])
        ->and(count($data['settings']['color']['palette']))->toBe(6); // the global palette is untouched
    expect(editor_block_palettes(['settings' => []], ['button' => ['white']]))->toBe(['settings' => []]);
});

it('reads the palette keyed by origin, as the filter receives it, and writes the block palettes the same way', function () {
    $palette = array_map(fn($slug) => ['slug' => $slug, 'name' => $slug, 'color' => '#000'], ['white', 'sage-600', 'sage-200']);
    $data = editor_block_palettes(['settings' => ['color' => ['palette' => ['theme' => $palette]]]], ['button' => ['white', 'sage-600'], 'text' => ['white']]);

    expect(array_column($data['settings']['blocks']['core/button']['color']['palette']['theme'], 'slug'))->toBe(['white', 'sage-600'])
        ->and(array_column($data['settings']['blocks']['core/paragraph']['color']['palette']['theme'], 'slug'))->toBe(['white']);
});

it('reports what each role gets, for the drift check', function () use ($registered) {
    expect(editor_settings_state(false, $registered))->toBe(['canLockBlocks' => false, 'customColors' => false, 'allowsShortcode' => false, 'allowsParagraph' => true])
        ->and(editor_settings_state(true, $registered))->toBe(['canLockBlocks' => true, 'customColors' => true, 'allowsShortcode' => true, 'allowsParagraph' => true]);
});

it('hooks the right filters at the right places and only judges by the manage_options capability', function () {
    $php = file_get_contents(dirname(__DIR__, 2) . '/modules/editor-settings.php');

    expect($php)->toContain("add_filter('wp_theme_json_data_theme'", "add_filter('block_editor_settings_all'", "add_filter('should_load_remote_block_patterns', '__return_false')", "add_filter('allowed_block_types_all'", "current_user_can('manage_options')")
        ->and($php)->not->toContain('pkde_design_admin') // no custom capability
        ->and($php)->toContain("'core/edit-post'"); // the site editor and widget screens are left alone
});
