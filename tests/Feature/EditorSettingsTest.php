<?php

require_once dirname(__DIR__, 2) . '/modules/editor-settings/editor-settings.php';

use function App\MuPlugins\editor_allowed_blocks;
use function App\MuPlugins\editor_block_palettes;
use function App\MuPlugins\editor_hide_rank_math;
use function App\MuPlugins\editor_patterns_to_remove;
use function App\MuPlugins\editor_unused_pattern_categories;
use function App\MuPlugins\editor_settings;
use function App\MuPlugins\editor_settings_state;
use function App\MuPlugins\editor_theme_json;

$registered = [
    'core/paragraph', 'core/heading', 'core/group', 'core/image', 'core/buttons', 'core/button', 'core/list', 'core/spacer', 'core/block',
    'core/shortcode', 'core/html', 'core/cover', 'core/query', 'core/post-template', 'core/verse', 'core/calendar', 'core/embed',
    'sage/hero', 'sage/section', 'bb/latest-reviews', 'wpcf7/contact-form-selector', 'contact-form-7/contact-form-selector', 'relevanssi/live-search', 'relevanssi/search-form', 'rank-math/faq-block',
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
        ->and($allowed)->toContain('sage/hero', 'sage/section', 'bb/latest-reviews', 'wpcf7/contact-form-selector')
        ->and($allowed)->not->toContain('rank-math/faq-block') // hidden from the inserter for everyone
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
    $php = file_get_contents(dirname(__DIR__, 2) . '/modules/editor-settings/editor-settings.php');

    expect($php)->toContain("add_filter('wp_theme_json_data_theme'", "add_filter('block_editor_settings_all'", "add_filter('should_load_remote_block_patterns', '__return_false')", "add_filter('allowed_block_types_all'", "current_user_can('manage_options')")
        ->and($php)->not->toContain('pkde_design_admin') // no custom capability
        ->and($php)->toContain("'core/edit-post'"); // the site editor and widget screens are left alone
});

it('hides Rank Math\'s blocks from the inserter and leaves every other block\'s arguments alone', function () {
    $args = editor_hide_rank_math(['supports' => ['html' => false, 'inserter' => true]], 'rank-math/faq-block');

    expect($args['supports'])->toBe(['html' => false, 'inserter' => false])
        ->and(editor_hide_rank_math([], 'rank-math/toc-block')['supports']['inserter'])->toBeFalse()
        ->and(editor_hide_rank_math(['supports' => ['html' => false]], 'sage/hero'))->toBe(['supports' => ['html' => false]])
        ->and(editor_hide_rank_math(['title' => 'x'], 'core/paragraph'))->toBe(['title' => 'x']);
});

it('removes WordPress\'s and WooCommerce\'s bundled patterns and keeps the kit\'s and a site\'s', function () {
    $names = ['core/text-two-columns', 'core/query-large-title-posts', 'woocommerce/product-hero', 'woocommerce-blocks/featured-products-5-item-grid', 'woocommerce-blocks/hero-product-3-split', 'pkde/hero-split', 'pkde/page-about', 'bb/own-section'];

    expect(editor_patterns_to_remove($names))->toBe(['core/text-two-columns', 'core/query-large-title-posts', 'woocommerce/product-hero', 'woocommerce-blocks/featured-products-5-item-grid', 'woocommerce-blocks/hero-product-3-split'])
        ->and(editor_patterns_to_remove(['pkde/cta']))->toBe([]);
});

it('drops the pattern categories nothing uses any more, and keeps the ones the remaining patterns are in', function () {
    expect(editor_unused_pattern_categories(['featured', 'banner', 'woo-commerce', 'pkde', 'pkde-pages'], [['pkde'], ['pkde-pages', 'pkde']]))->toBe(['featured', 'banner', 'woo-commerce'])
        ->and(editor_unused_pattern_categories(['featured'], []))->toBe(['featured']);
});

it('locks Sections for non-Administrators with the block lock, not templateLock all, and takes duplicate, copy, group and ungroup out of the menu', function () {
    $dir = dirname(__DIR__, 2) . '/modules/editor-settings';
    $js = file_get_contents("$dir/editor-lock.js");
    $php = file_get_contents("$dir/editor-settings.php");

    expect($js)->toContain("lock: { move: true, remove: true }", "'sage/section'", 'is-style-brand-', "__( 'Duplicate' )", "__( 'Copy' )", "__( 'Group' )", "__( 'Ungroup' )")
        ->and($js)->not->toMatch('/templateLock\s*[:=]/')
        ->and($php)->toContain("add_action('enqueue_block_editor_assets'", 'editor-lock.js')
        ->and($php)->toMatch('/if \(\$isAdministrator\(\) \|\| ! is_file\(\$file\)\) \{\s+return;/'); // an Administrator never gets the script
});

it('hides the Contact Form 7 and Relevanssi blocks from an Editor and keeps them for an Administrator', function () use ($registered) {
    $editor = editor_allowed_blocks($registered, false);
    $admin = editor_allowed_blocks($registered, true);

    expect($editor)->not->toContain('contact-form-7/contact-form-selector', 'relevanssi/live-search', 'relevanssi/search-form')
        ->and($editor)->toContain('wpcf7/contact-form-selector', 'sage/hero') // other plugin and kit blocks stay
        ->and($admin)->toContain('contact-form-7/contact-form-selector', 'relevanssi/live-search', 'relevanssi/search-form')
        ->and(editor_allowed_blocks($registered, false, ['core/paragraph'], []))->toContain('contact-form-7/contact-form-selector'); // a site can empty the list
});

it('blocks duplicating a Section at the action, so the menu, the shortcut and anything else cannot', function () {
    $js = file_get_contents(dirname(__DIR__, 2) . '/modules/editor-settings/editor-lock.js');

    expect($js)->toContain('wp.data.use(', "actions.duplicateBlocks = ( clientIds, ...rest )", "namespace === 'core/block-editor'", 'isSection( store.getBlock( id ) )')
        // the shortcut is stopped where it is pressed too, in the page and in the editor canvas
        ->and($js)->toContain("window.addEventListener( 'keydown', stopDuplicateShortcut, true )", 'doc.addEventListener( \'keydown\', stopDuplicateShortcut, true )', 'event.stopImmediatePropagation()');
});
