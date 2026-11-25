<?php

/**
 * Plugin Name: WooCommerce CSV import: the Slug column
 * Description: Makes the product slug a first-class field of WooCommerce's CSV importer: the mapping screen offers "Slug", and a column headed "Slug" is mapped to it without any
 *              admin step. An import that loses its slug creates new product URLs, so this is catalogue data handling, not looks (WP-105, phase 1, moved out of the theme kit).
 *              Does nothing without WooCommerce (the filters never fire). Defines PKDE_PLATFORM_WOOCOMMERCE_IMPORT_SLUG, which the kit looks for; switch the module off with
 *              PKDE_PLATFORM_DISABLE=woocommerce-import-slug and the kit's copy runs again.
 */

namespace App\MuPlugins;

if (! defined('PKDE_PLATFORM_WOOCOMMERCE_IMPORT_SLUG')) {
    define('PKDE_PLATFORM_WOOCOMMERCE_IMPORT_SLUG', true);
}

/** The importer's mapping options with the Slug field added. */
function import_slug_options(array $options): array
{
    // The label keeps the theme's text domain so an existing translation of "Slug" still applies.
    $options['slug'] = function_exists('__') ? __('Slug', 'sage') : 'Slug';

    return $options;
}

/** The importer's default column mapping with a "Slug" column mapped to the slug field. */
function import_slug_default_columns(array $columns): array
{
    $columns['Slug'] = 'slug';

    return $columns;
}

if (! function_exists('add_filter')) {
    return; // loaded outside WordPress (the Pest tests)
}

add_filter('woocommerce_csv_product_import_mapping_options', __NAMESPACE__ . '\\import_slug_options');
add_filter('woocommerce_csv_product_import_mapping_default_columns', __NAMESPACE__ . '\\import_slug_default_columns');
