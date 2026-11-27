<?php

/**
 * Plugin Name: Product structured data that matches the shop
 * Description: Rank Math writes the Product schema; this corrects what it gets wrong for a shop and adds what it leaves out, from the store's own settings, never invented (
 *moved out of the theme kit, so a theme switch no longer makes the Merchant Center price differ from the page):
 *               - Price: the price a shopper sees (the "Display prices in the shop" setting, including or excluding tax) instead of the stored price;
 *               - Brand: when Rank Math has none, the product's own `product_brand` term; a store that sells under one name returns it from the filter `pkde_kit/product_brand`;
 *               - shippingDetails: only when the store's shipping zone for its base country has a free-shipping or numeric flat-rate method and the product is physical with no
 *                 shipping class; replace or drop it with `pkde_kit/schema_shipping_details`;
 *               - hasMerchantReturnPolicy: only when a site returns one from `pkde_kit/schema_return_policy`.
 *              The three `pkde_kit/*` filter names and the Rank Math filter priority (20) are the kit's. Needs WooCommerce and Rank Math (the filter never fires without Rank Math).
 *              Defines PKDE_PLATFORM_PRODUCT_SCHEMA, which the kit looks for, so the markup is never corrected twice; switch the module off with
 *              PKDE_PLATFORM_DISABLE=product-schema and the kit's copy runs again.
 */

namespace App\MuPlugins;

if (! defined('PKDE_PLATFORM_PRODUCT_SCHEMA')) {
    define('PKDE_PLATFORM_PRODUCT_SCHEMA', true);
}

/**
 * The Product schema offers with the prices a shopper SEES (S-1): Rank Math prints the stored price, so a shop that displays prices with tax
 * (or without) while it stores them the other way round showed one price on the page and another in the markup. Offer: price, and the price and
 * valueAddedTaxIncluded of its priceSpecification. AggregateOffer: lowPrice and highPrice. A price that is null is left as Rank Math printed it.
 *
 * @param  array<string, mixed>  $offers  the entity's `offers`
 * @param  bool  $vatIncluded  the shop displays prices including tax
 * @return array<string, mixed>
 */
function product_schema_offer_prices(array $offers, ?string $price, ?string $low, ?string $high, bool $vatIncluded): array
{
    if (($offers['@type'] ?? '') === 'AggregateOffer') {
        return array_replace($offers, array_filter(['lowPrice' => $low, 'highPrice' => $high], fn($v) => $v !== null));
    }

    if ($price !== null) {
        $offers['price'] = $price;
    }

    if (isset($offers['priceSpecification']) && is_array($offers['priceSpecification'])) {
        $offers['priceSpecification'] = array_replace($offers['priceSpecification'], array_filter(['price' => $price], fn($v) => $v !== null), ['valueAddedTaxIncluded' => $vatIncluded ? 'true' : 'false']);
    }

    return $offers;
}

/**
 * schema.org OfferShippingDetails from what the store has set: a rate and the countries it applies to. Nothing when there is no rate.
 *
 * @param  list<string>  $countries  ISO country codes
 * @return array<string, mixed>
 */
function product_schema_shipping_details(?string $rate, string $currency, array $countries): array
{
    if ($rate === null || $currency === '' || $countries === []) {
        return [];
    }

    return [
        '@type' => 'OfferShippingDetails',
        'shippingRate' => ['@type' => 'MonetaryAmount', 'value' => $rate, 'currency' => $currency],
        'shippingDestination' => array_map(fn($c) => ['@type' => 'DefinedRegion', 'addressCountry' => $c], array_values($countries)),
    ];
}

/** The offers with the displayed price, the shipping details and the return policy, where the store has them. */
function product_schema_offers(array $offers, \WC_Product $product): array
{
    $decimals = wc_get_price_decimals();
    $format = fn($value) => wc_format_decimal($value, $decimals);

    if (wc_tax_enabled()) {
        $low = $high = $price = null;

        if ($product->is_type('variable')) {
            $low = $format($product->get_variation_price('min', true));
            $high = $format($product->get_variation_price('max', true));
            // one price when the variations agree, or when the page selected one (Rank Math prints that variation's offer)
            $price = $low === $high ? $low : null;
            $selected = ($offers['@type'] ?? '') === 'Offer' && ! empty($offers['sku']) ? wc_get_product(wc_get_product_id_by_sku($offers['sku'])) : null;
            $price = $selected ? $format(wc_get_price_to_display($selected)) : $price;
        } else {
            $price = $format(wc_get_price_to_display($product));
        }

        $offers = product_schema_offer_prices($offers, $price, $low, $high, get_option('woocommerce_tax_display_shop') === 'incl');
    }

    $shipping = product_schema_shipping_details(...product_schema_store_shipping_rate($product));
    $shipping = apply_filters('pkde_kit/schema_shipping_details', $shipping, $product);

    if (! empty($shipping) && is_array($shipping)) {
        $offers['shippingDetails'] = $shipping;
    }

    $return = apply_filters('pkde_kit/schema_return_policy', [], $product);

    if (! empty($return) && is_array($return)) {
        $offers['hasMerchantReturnPolicy'] = ['@type' => 'MerchantReturnPolicy'] + $return;
    }

    return $offers;
}

/**
 * The cheapest enabled free-shipping or numeric flat-rate method of the first shipping zone that lists the store's base country, with that zone's
 * countries. [null, currency, []] when there is none or the product ships with a class, so no shippingDetails are written.
 *
 * @return array{0: ?string, 1: string, 2: list<string>}
 */
function product_schema_store_shipping_rate(\WC_Product $product): array
{
    $currency = (string) get_woocommerce_currency();
    $none = [null, $currency, []];

    if ($product->is_virtual() || $product->get_shipping_class_id() > 0 || ! class_exists('WC_Shipping_Zones')) {
        return $none;
    }

    $base = (string) WC()->countries->get_base_country();

    foreach (\WC_Shipping_Zones::get_zones() as $zone) {
        $countries = [];

        foreach ((array) $zone['zone_locations'] as $location) {
            if ($location->type === 'country') {
                $countries[] = (string) $location->code;
            }
        }

        if (! in_array($base, $countries, true)) {
            continue;
        }

        $rates = [];

        foreach ((array) $zone['shipping_methods'] as $method) {
            if ($method->enabled !== 'yes') {
                continue;
            }

            if ($method->id === 'free_shipping') {
                $rates[] = 0.0;
            } elseif ($method->id === 'flat_rate' && is_numeric(trim((string) $method->get_option('cost')))) {
                $rates[] = (float) trim((string) $method->get_option('cost'));
            }
        }

        return $rates === [] ? $none : [wc_format_decimal(min($rates), wc_get_price_decimals()), $currency, $countries];
    }

    return $none;
}

if (! function_exists('add_filter')) {
    return; // loaded outside WordPress (the Pest tests): the functions above are all there is
}

add_filter('rank_math/snippet/rich_snippet_product_entity', function ($entity) {
    if (! is_array($entity) || ! function_exists('wc_get_product') || ! is_singular('product')) {
        return $entity;
    }

    $product = wc_get_product(get_the_ID());

    if (! $product) {
        return $entity;
    }

    if (! empty($entity['offers']) && is_array($entity['offers'])) {
        $entity['offers'] = product_schema_offers($entity['offers'], $product);
    }

    if (empty($entity['brand'])) {
        $terms = taxonomy_exists('product_brand') ? get_the_terms($product->get_id(), 'product_brand') : [];
        $brand = (string) apply_filters('pkde_kit/product_brand', is_array($terms) && ! empty($terms[0]) ? $terms[0]->name : '', $product);

        if ($brand !== '') {
            $entity['brand'] = ['@type' => 'Brand', 'name' => $brand];
        }
    }

    return $entity;
}, 20);
