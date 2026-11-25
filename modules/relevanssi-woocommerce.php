<?php

/**
 * Plugin Name: Relevanssi and WooCommerce: product search sorting
 * Description: With Relevanssi answering a product search, WooCommerce's own SQL sorting never runs, so the shop's sort choices (price, popularity, rating) are handed to Relevanssi
 *              as the meta fields they sort by, and WooCommerce's "date ID" ordering, which Relevanssi does not read, becomes post_date. Without this a product search sorts by
 *              relevance only (tcs, bb). Moved out of the theme kit (WP-105, phase 1); the live-search styling, template and routing stay in the kit. Does nothing without
 *              Relevanssi (the filter never fires). Defines PKDE_PLATFORM_RELEVANSSI_WOOCOMMERCE, which the kit looks for; switch the module off with
 *              PKDE_PLATFORM_DISABLE=relevanssi-woocommerce and the kit's copy runs again.
 */

namespace App\MuPlugins;

if (! defined('PKDE_PLATFORM_RELEVANSSI_WOOCOMMERCE')) {
    define('PKDE_PLATFORM_RELEVANSSI_WOOCOMMERCE', true);
}

/** The meta field a WooCommerce sort choice orders by when Relevanssi answers the query (price, popularity, rating), or null. */
function relevanssi_sort_field(?string $orderby): ?string
{
    return ['price' => '_price', 'popularity' => 'total_sales', 'rating' => '_wc_average_rating'][$orderby ?? ''] ?? null;
}

if (! function_exists('add_filter')) {
    return; // loaded outside WordPress (the Pest tests)
}

add_filter('relevanssi_modify_wp_query', function (\WP_Query $query): \WP_Query {
    $orderby = $query->get('orderby');
    $first = is_string($orderby) ? strtok($orderby, ' ') : null;
    $field = relevanssi_sort_field($first);

    if ($field) {
        $query->set('meta_key', $field);
        $query->set('orderby', 'meta_value_num');
    } elseif ($first === 'date') {
        $query->set('orderby', 'post_date'); // WooCommerce's "date ID", which Relevanssi does not read
    }

    return $query;
});
