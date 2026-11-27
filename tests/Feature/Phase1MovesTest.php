<?php

//phase 1: the behaviours moved out of the theme kit into modules. The pure functions are tested here (the kit's own tests cover the copies it still has); the hooks are
// proved on a real WordPress. WordPress is stood in for by two global functions where a module asks for it.
$modules = dirname(__DIR__, 2) . '/modules';

// a module's file, or a part of a merged module (modules/<module>/<part>.php)
$moduleFile = fn(string $name) => match ($name) {
    'cf7-form-key', 'cf7-editor-preview' => "$modules/cf7/$name.php",
    'product-schema' => "$modules/schema/$name.php",
    default => "$modules/$name.php",
};

foreach (['media', 'disable-emoji', 'woocommerce-import-slug', 'relevanssi-woocommerce', 'shortcodes', 'cf7-form-key', 'cf7-editor-preview', 'product-schema'] as $name) {
    require_once $moduleFile($name);
}

use function App\MuPlugins\cf7_form_id;
use function App\MuPlugins\cf7_form_shortcode;
use function App\MuPlugins\cf7_preview_shortcode;
use function App\MuPlugins\import_slug_default_columns;
use function App\MuPlugins\import_slug_options;
use function App\MuPlugins\media_absolute_upload_urls;
use function App\MuPlugins\media_png_output_formats;
use function App\MuPlugins\media_webp_quality;
use function App\MuPlugins\product_schema_offer_prices;
use function App\MuPlugins\product_schema_shipping_details;
use function App\MuPlugins\relevanssi_sort_field;
use function App\MuPlugins\years_since;

if (! function_exists('get_posts')) {
    function get_posts(array $args): array
    {
        return ($args['meta_key'] ?? '') === '_pkde_form_key' ? (array) ($GLOBALS['phase1Forms'][$args['meta_value']] ?? []) : [];
    }
}

if (! function_exists('shortcode_exists')) {
    function shortcode_exists(string $tag): bool
    {
        return $tag === 'contact-form-7';
    }
}

it('defines the constant the kit looks for in each module, and only there', function () use ($modules, $moduleFile) {
    foreach (['media' => 'PKDE_PLATFORM_MEDIA', 'disable-emoji' => 'PKDE_PLATFORM_DISABLE_EMOJI', 'woocommerce-import-slug' => 'PKDE_PLATFORM_WOOCOMMERCE_IMPORT_SLUG', 'relevanssi-woocommerce' => 'PKDE_PLATFORM_RELEVANSSI_WOOCOMMERCE',
        'shortcodes' => 'PKDE_PLATFORM_SHORTCODES', 'cf7-form-key' => 'PKDE_PLATFORM_CF7_FORM_KEY', 'cf7-editor-preview' => 'PKDE_PLATFORM_CF7_EDITOR_PREVIEW', 'product-schema' => 'PKDE_PLATFORM_PRODUCT_SCHEMA'] as $name => $constant) {
        expect(defined($constant))->toBeTrue("$name defines $constant");
        expect(file_get_contents($moduleFile($name)))->toContain("define('$constant', true);");
    }

    expect(file_get_contents("$modules/faq/faq.php"))->toContain("define('PKDE_PLATFORM_FAQ_SCHEMA', true);")
        ->and(file_get_contents("$modules/phone-e164.php"))->toContain("define('PKDE_PLATFORM_PHONE_LEAD', true);");
});

it('saves PNG sub-sizes as WebP at quality 80 and makes upload URLs in content absolute', function () {
    expect(media_png_output_formats(['image/jpeg' => 'image/webp']))->toBe(['image/png' => 'image/webp', 'image/jpeg' => 'image/webp'])
        ->and(media_webp_quality(82, 'image/webp'))->toBe(80)
        ->and(media_webp_quality(82, 'image/jpeg'))->toBe(82);

    $html = '<p><img class="a" src="/app/uploads/2026/01/x.png" alt=""><img src="https://cdn.example.test/y.png"> <img src="/app/uploads/z.jpg"></p>';

    expect(media_absolute_upload_urls($html, 'https://example.test/'))->toBe('<p><img class="a" src="https://example.test/app/uploads/2026/01/x.png" alt=""><img src="https://cdn.example.test/y.png"> <img src="https://example.test/app/uploads/z.jpg"></p>');
});

it('adds the Slug field and maps a Slug column to it', function () {
    expect(import_slug_options(['sku' => 'SKU']))->toHaveKey('slug')->and(import_slug_default_columns(['Name' => 'name']))->toBe(['Name' => 'name', 'Slug' => 'slug']);
});

it('maps the shop sort choices to the meta fields Relevanssi sorts by', function () {
    expect(relevanssi_sort_field('price'))->toBe('_price')
        ->and(relevanssi_sort_field('popularity'))->toBe('total_sales')
        ->and(relevanssi_sort_field('rating'))->toBe('_wc_average_rating')
        ->and(relevanssi_sort_field('date'))->toBeNull()
        ->and(relevanssi_sort_field(null))->toBeNull();
});

it('counts the years since a year for [years_since]', function () {
    expect(years_since(2014, '+', 2026))->toBe('12+')
        ->and(years_since(2026, '', 2026))->toBe('0')
        ->and(years_since(2030, ' years', 2026))->toBe('0 years')
        ->and(years_since(1800, '+', 2026))->toBe('')
        ->and(years_since(0, '', 2026))->toBe('');
});

it('finds a Contact Form 7 form by its key and prints its shortcode', function () {
    $GLOBALS['phase1Forms'] = ['contact' => [12], 'quote' => [30]];

    expect(cf7_form_id())->toBe(12)->and(cf7_form_id('quote'))->toBe(30)->and(cf7_form_id('nope'))->toBe(0)
        ->and(cf7_form_shortcode())->toBe('[contact-form-7 id="12"]')
        ->and(cf7_form_shortcode('nope'))->toBe('');
});

it('answers the editor preview for a contact-form-7 shortcode only', function () {
    expect(cf7_preview_shortcode('<p>[contact-form-7 id="5" title="Contact"]</p>'))->toBe('[contact-form-7 id="5" title="Contact"]')
        ->and(cf7_preview_shortcode('[gallery ids="1,2"]'))->toBe('')
        ->and(cf7_preview_shortcode('hello [contact-form-7 id="5"]'))->toBe('');
});

it('corrects the Product schema prices and writes shipping details only with a rate', function () {
    $offer = ['@type' => 'Offer', 'price' => '45.00', 'priceSpecification' => ['price' => '45.00', 'valueAddedTaxIncluded' => 'false']];

    expect(product_schema_offer_prices($offer, '54.00', null, null, true))->toBe(['@type' => 'Offer', 'price' => '54.00', 'priceSpecification' => ['price' => '54.00', 'valueAddedTaxIncluded' => 'true']])
        ->and(product_schema_offer_prices(['@type' => 'AggregateOffer', 'lowPrice' => '1', 'highPrice' => '2'], null, '3.00', '4.00', true))->toBe(['@type' => 'AggregateOffer', 'lowPrice' => '3.00', 'highPrice' => '4.00'])
        ->and(product_schema_shipping_details(null, 'GBP', ['GB']))->toBe([])
        ->and(product_schema_shipping_details('4.99', 'GBP', ['GB'])['shippingDestination'][0]['addressCountry'])->toBe('GB');
});
