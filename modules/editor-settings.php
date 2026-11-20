<?php

/**
 * Plugin Name: Block editor settings by role
 * Description: What a client's block editor offers (WP-100, the section-based editor). Administrators get the full colour picker and can lock and
 *              unlock blocks; everyone else works with the site's brand swatches and a curated set of blocks. By the site's own role, not a
 *              custom capability: `manage_options`.
 *               - custom colours and gradients: only Administrators (wp_theme_json_data_theme, and the editor settings, which are per user);
 *               - canLockBlocks: only Administrators (so an Editor cannot unlock a locked section);
 *               - no remote block patterns (core's pattern directory is switched off);
 *               - allowed blocks: a curated core list, plus every block the kit, the site and its plugins register, plus (Administrators only) shortcode,
 *                 custom HTML, the query family, cover and the WooCommerce cart/checkout blocks. A site extends the core list with the filter
 *                 `pkde_platform/editor_core_blocks`;
 *               - per-block palettes: a Button offers only the swatches that make a button pair, text blocks only the text-safe ones (the lists are
 *                 generated with the palette: the theme's resources/generated/editor-palettes.php).
 *              Switch the module off with PKDE_PLATFORM_DISABLE=editor-settings.
 */

namespace App\MuPlugins;

/** The core blocks every role may insert. Existing content with any other block keeps rendering and stays editable. */
const EDITOR_CORE_BLOCKS = [
    'core/paragraph', 'core/heading', 'core/group', 'core/columns', 'core/column', 'core/image', 'core/buttons', 'core/button', 'core/list', 'core/list-item',
    'core/spacer', 'core/separator', 'core/details', 'core/quote', 'core/table', 'core/social-links', 'core/social-link',
    'core/block', 'core/pattern', 'core/missing', // synced patterns and the placeholders the editor itself needs
];

/** Core blocks only Administrators may insert. */
const EDITOR_ADMIN_CORE_BLOCKS = [
    'core/shortcode', 'core/html', 'core/cover',
    'core/query', 'core/query-title', 'core/query-total', 'core/query-no-results', 'core/query-pagination', 'core/query-pagination-next', 'core/query-pagination-numbers', 'core/query-pagination-previous',
    'core/post-template', 'core/post-title', 'core/post-date', 'core/post-excerpt', 'core/post-featured-image', 'core/post-terms', 'core/post-author',
];

/**
 * The block names a role may insert.
 *
 * @param  list<string>  $registered  every registered block name
 * @param  list<string>  $coreBlocks  the core list (after the site's filter)
 * @return list<string>
 */
function editor_allowed_blocks(array $registered, bool $isAdministrator, array $coreBlocks = EDITOR_CORE_BLOCKS): array
{
    // the kit's, the site's and every plugin's blocks (sage/*, bb/*, wpcf7/*, rank-math/*): anything that is neither core nor WooCommerce
    $own = array_filter($registered, fn($name) => ! str_starts_with($name, 'core/') && ! str_starts_with($name, 'woocommerce/'));
    $allowed = array_merge(array_intersect($coreBlocks, $registered), $own);

    if ($isAdministrator) {
        $cartAndCheckout = array_filter($registered, fn($name) => str_starts_with($name, 'woocommerce/') && (str_contains($name, 'cart') || str_contains($name, 'checkout')));
        $allowed = array_merge($allowed, array_intersect(EDITOR_ADMIN_CORE_BLOCKS, $registered), $cartAndCheckout);
    }

    return array_values(array_unique($allowed));
}

/** theme.json data with the full colour picker on for Administrators and off for everyone else. */
function editor_theme_json(array $data, bool $isAdministrator): array
{
    $data['settings']['color']['custom'] = $isAdministrator;
    $data['settings']['color']['customGradient'] = $isAdministrator;

    return $data;
}

/**
 * The editor settings for a role. The editor reads its features from these, which are computed per user on every load, so this holds even when
 * a persistent object cache shares the theme.json data between roles.
 */
function editor_settings(array $settings, bool $isAdministrator): array
{
    $settings['canLockBlocks'] = $isAdministrator;
    $settings['disableCustomColors'] = ! $isAdministrator;
    $settings['disableCustomGradients'] = ! $isAdministrator;
    $settings['__experimentalFeatures']['color']['custom'] = $isAdministrator;
    $settings['__experimentalFeatures']['color']['customGradient'] = $isAdministrator;

    return $settings;
}

/**
 * Per-block palettes into theme.json data: settings.blocks.<block>.color.palette keeps only the listed swatches (in the theme's order).
 *
 * @param  array{button?: list<string>, text?: list<string>}  $palettes  swatch slugs, from the theme's generated editor-palettes.php
 */
function editor_block_palettes(array $data, array $palettes): array
{
    $palette = $data['settings']['color']['palette'] ?? [];
    // WordPress keys presets by origin inside the data a filter receives (palette => ['theme' => [...]]); a bare list is accepted too.
    $byOrigin = is_array($palette) && isset($palette['theme']) && is_array($palette['theme']);
    $swatches = $byOrigin ? $palette['theme'] : $palette;

    if (! is_array($swatches) || $swatches === []) {
        return $data;
    }

    $only = fn(array $slugs) => array_values(array_filter($swatches, fn($swatch) => in_array($swatch['slug'] ?? '', $slugs, true)));
    $targets = [
        'core/button' => $palettes['button'] ?? [],
        'core/paragraph' => $palettes['text'] ?? [],
        'core/heading' => $palettes['text'] ?? [],
        'core/list' => $palettes['text'] ?? [],
        'core/quote' => $palettes['text'] ?? [],
        'core/details' => $palettes['text'] ?? [],
        'core/table' => $palettes['text'] ?? [],
    ];

    foreach ($targets as $block => $slugs) {
        $list = $only($slugs);
        if ($list !== []) {
            $data['settings']['blocks'][$block]['color']['palette'] = $byOrigin ? ['theme' => $list] : $list;
        }
    }

    return $data;
}

/** The settings of this module as the live filters apply them, for a given role: what the drift check compares. */
function editor_settings_state(bool $isAdministrator, array $registered): array
{
    $settings = editor_settings(['canLockBlocks' => true], $isAdministrator);

    return [
        'canLockBlocks' => $settings['canLockBlocks'],
        'customColors' => $settings['__experimentalFeatures']['color']['custom'],
        'allowsShortcode' => in_array('core/shortcode', editor_allowed_blocks($registered, $isAdministrator), true),
        'allowsParagraph' => in_array('core/paragraph', editor_allowed_blocks($registered, $isAdministrator), true),
    ];
}

if (! function_exists('add_filter')) {
    return;
}

$isAdministrator = fn(): bool => function_exists('current_user_can') && current_user_can('manage_options');

add_filter('wp_theme_json_data_theme', function ($data) use ($isAdministrator) {
    $new = editor_theme_json($data->get_data(), $isAdministrator());
    $file = function_exists('get_theme_file_path') ? get_theme_file_path('resources/generated/editor-palettes.php') : '';

    if ($file !== '' && is_file($file)) {
        $new = editor_block_palettes($new, (array) require $file);
    }

    return $data->update_with($new);
});

add_filter('block_editor_settings_all', fn($settings) => editor_settings((array) $settings, $isAdministrator()), 20);

add_filter('should_load_remote_block_patterns', '__return_false');

add_filter('allowed_block_types_all', function ($allowed, $context) use ($isAdministrator) {
    // the post editor only: the site editor and widgets screens keep whatever WordPress allows
    if (! is_object($context) || ($context->name ?? '') !== 'core/edit-post' || ! class_exists('WP_Block_Type_Registry')) {
        return $allowed;
    }

    $registered = array_keys(\WP_Block_Type_Registry::get_instance()->get_all_registered());
    $core = (array) apply_filters('pkde_platform/editor_core_blocks', EDITOR_CORE_BLOCKS);

    return editor_allowed_blocks($registered, $isAdministrator(), array_values($core));
}, 20, 2);
