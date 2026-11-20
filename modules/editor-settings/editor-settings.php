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
 *               - the Contact Form 7 and Relevanssi blocks are Administrators' only (a site changes the list with `pkde_platform/editor_admin_only_namespaces`);
 *               - Rank Math's blocks (FAQ, HowTo, Table of Contents, schema) are hidden from the inserter for everyone; content that already has one keeps rendering;
 *               - WordPress's and WooCommerce's bundled block patterns are removed; the kit's (`pkde`) and a site's own remain;
 *               - an Editor cannot move, duplicate or remove a Section (the kit's sage/section and any block with a Brand section theme): the block `lock`, not templateLock all,
 *                 so the writing inside stays editable (editor-lock.js, loaded for non-Administrators);
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

/** Plugin blocks only Administrators see (forms come from the kit's contact block and the form keys; the search blocks are the site's to place). */
const EDITOR_ADMIN_ONLY_NAMESPACES = ['contact-form-7/', 'relevanssi/'];

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
function editor_allowed_blocks(array $registered, bool $isAdministrator, array $coreBlocks = EDITOR_CORE_BLOCKS, array $adminOnlyPrefixes = EDITOR_ADMIN_ONLY_NAMESPACES): array
{
    // the kit's, the site's and every plugin's blocks (sage/*, bb/*, wpcf7/*, rank-math/*): anything that is neither core nor WooCommerce
    $hidden = $isAdministrator ? [] : $adminOnlyPrefixes;
    $own = array_filter($registered, fn($name) => ! str_starts_with($name, 'core/') && ! str_starts_with($name, 'woocommerce/') && ! str_starts_with($name, 'rank-math/') && ! array_filter($hidden, fn($prefix) => str_starts_with($name, $prefix)));
    $allowed = array_merge(array_intersect($coreBlocks, $registered), $own);

    if ($isAdministrator) {
        $cartAndCheckout = array_filter($registered, fn($name) => str_starts_with($name, 'woocommerce/') && (str_contains($name, 'cart') || str_contains($name, 'checkout')));
        $allowed = array_merge($allowed, array_intersect(EDITOR_ADMIN_CORE_BLOCKS, $registered), $cartAndCheckout);
    }

    return array_values(array_unique($allowed));
}

/** Block type arguments with the block hidden from the inserter when it is one of Rank Math's (existing content keeps rendering: only the inserter changes). */
function editor_hide_rank_math(array $args, string $name): array
{
    if (str_starts_with($name, 'rank-math/')) {
        $args['supports'] = array_merge((array) ($args['supports'] ?? []), ['inserter' => false]);
    }

    return $args;
}

/** The registered pattern names to remove: WordPress's own and WooCommerce's (`core/…`, `woocommerce/…`, `woocommerce-blocks/…`); the kit's and a site's stay. */
function editor_patterns_to_remove(array $names): array
{
    return array_values(array_filter($names, fn($name) => (bool) preg_match('~^(core|woocommerce|woocommerce-blocks|woocommerce-[a-z-]+)/~', (string) $name)));
}

/**
 * The pattern categories left with no pattern once the bundled ones are gone (WordPress's "Banners", "Gallery"... and WooCommerce's): they would list as empty
 * headings in the inserter.
 *
 * @param  list<string>  $categories  every registered category name
 * @param  list<list<string>>  $usedBy  the category names of each remaining pattern
 * @return list<string>
 */
function editor_unused_pattern_categories(array $categories, array $usedBy): array
{
    $used = array_unique(array_merge(...($usedBy ?: [[]])));

    return array_values(array_diff($categories, $used));
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
    $adminOnly = (array) apply_filters('pkde_platform/editor_admin_only_namespaces', EDITOR_ADMIN_ONLY_NAMESPACES);

    return editor_allowed_blocks($registered, $isAdministrator(), array_values($core), array_values($adminOnly));
}, 20, 2);

add_filter('register_block_type_args', fn($args, $name) => editor_hide_rank_math((array) $args, (string) $name), 10, 2);

// Bundled patterns: WordPress's (also off with remove_theme_support) and WooCommerce's. WooCommerce registers its patterns late and again when the editor asks for them
// (the REST request), so they are taken out at every point they can appear: after init, on a REST request and in the editor settings.
add_action('after_setup_theme', fn() => remove_theme_support('core-block-patterns'), 20);
$removeBundledPatterns = function () {
    if (! class_exists('WP_Block_Patterns_Registry')) {
        return;
    }

    $registry = \WP_Block_Patterns_Registry::get_instance();

    foreach (editor_patterns_to_remove(array_column($registry->get_all_registered(), 'name')) as $name) {
        unregister_block_pattern($name);
    }

    if (class_exists('WP_Block_Pattern_Categories_Registry')) {
        $categories = \WP_Block_Pattern_Categories_Registry::get_instance();
        $usedBy = array_map(fn($pattern) => (array) ($pattern['categories'] ?? []), $registry->get_all_registered());

        foreach (editor_unused_pattern_categories(array_column($categories->get_all_registered(), 'name'), $usedBy) as $name) {
            unregister_block_pattern_category($name);
        }
    }
};
foreach (['init', 'wp_loaded', 'rest_api_init', 'admin_init', 'current_screen'] as $hook) {
    add_action($hook, $removeBundledPatterns, 9999);
}
add_filter('rest_pre_dispatch', function ($result) use ($removeBundledPatterns) {
    $removeBundledPatterns();

    return $result;
}, 1);
add_filter('block_editor_settings_all', function ($settings) use ($removeBundledPatterns) {
    $removeBundledPatterns();

    return $settings;
}, 1);

// Editors cannot move, duplicate or remove a Section (editor-lock.js); Administrators keep every control.
add_action('enqueue_block_editor_assets', function () use ($isAdministrator) {
    $file = __DIR__ . '/editor-lock.js';

    if ($isAdministrator() || ! is_file($file)) {
        return;
    }

    wp_register_script('pkde-editor-lock', false, ['wp-data', 'wp-i18n', 'wp-block-editor'], null, true);
    wp_enqueue_script('pkde-editor-lock');
    wp_add_inline_script('pkde-editor-lock', (string) file_get_contents($file));
});
