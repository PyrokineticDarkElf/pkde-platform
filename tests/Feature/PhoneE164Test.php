<?php

// gettext is WordPress's: without it the messages are the English source text.
if (! function_exists('__')) {
    function __(string $text, string $domain = 'default'): string
    {
        return $text;
    }
}

// The module defines its pure helpers and returns early outside WordPress, so it loads here without WordPress (libphonenumber is a dependency).
require_once dirname(__DIR__, 2) . '/modules/phone-e164.php';

use function App\MuPlugins\phone_check;
use function App\MuPlugins\phone_e164;
use function App\MuPlugins\phone_error_message;
use function App\MuPlugins\phone_pick_region;
use function App\MuPlugins\phone_preferred_countries;
use function App\MuPlugins\phone_region;

// Note: the brief's 07700 900123 is in Ofcom's range reserved for TV drama, which libphonenumber treats as invalid; real-looking numbers are used.

it('turns a valid UK number typed nationally or internationally into E.164', function () {
    expect(phone_e164('07912 345678', 'GB'))->toBe('+447912345678')
        ->and(phone_e164('07912345678', 'GB'))->toBe('+447912345678')
        ->and(phone_e164('+44 7912 345678', 'GB'))->toBe('+447912345678')
        ->and(phone_e164('+44 (0)7912 345678', 'US'))->toBe('+447912345678')
        ->and(phone_e164('020 7946 0018', 'GB'))->toBe('+442079460018');
});

it('reads a number with its own plus and country code whatever region is given', function () {
    expect(phone_e164('+33 6 12 34 56 78', 'GB'))->toBe('+33612345678')
        ->and(phone_e164('+1 202 555 0143', ''))->toBe('+12025550143')
        ->and(phone_e164('+33 6 12 34 56 78', ''))->toBe('+33612345678');
});

it('uses the submitted country for a national number', function () {
    expect(phone_e164('202 555 0143', 'US'))->toBe('+12025550143')
        ->and(phone_e164('06 12 34 56 78', 'FR'))->toBe('+33612345678')
        ->and(phone_e164('(202) 555-0143', 'us'))->toBe('+12025550143');
});

it('rejects a number that is not valid for the region, too short, or not a number', function () {
    expect(phone_e164('202 555 0143', 'GB'))->toBeNull()
        ->and(phone_e164('123', 'GB'))->toBeNull()
        ->and(phone_e164('07912 34', 'GB'))->toBeNull()
        ->and(phone_e164('hello', 'GB'))->toBeNull()
        ->and(phone_e164('+999 123456', 'GB'))->toBeNull()
        ->and(phone_e164('', 'GB'))->toBeNull();
});

it('lets an empty value pass untouched and names the country in the error', function () {
    expect(phone_check('', 'US', 'GB'))->toBe(['value' => '', 'error' => null])
        ->and(phone_check('   ', '', 'GB'))->toBe(['value' => '', 'error' => null]);

    $bad = phone_check('123', 'US', 'GB');
    expect($bad['error'])->toContain('United States')->toContain('+ and your country code')
        ->and($bad['value'])->toBe('123');
    expect(phone_error_message('GB'))->toContain('United Kingdom');
});

it('picks the submitted country when it is a real region, otherwise the site default', function () {
    expect(phone_pick_region('fr', 'GB'))->toBe('FR')
        ->and(phone_pick_region('', 'GB'))->toBe('GB')
        ->and(phone_pick_region('zz', 'GB'))->toBe('GB')
        ->and(phone_pick_region('not a code', 'gb'))->toBe('GB')
        ->and(phone_pick_region('', ''))->toBe('')
        ->and(phone_region('gb'))->toBe('GB')
        ->and(phone_region('XX'))->toBe('');
});

it('uses the site default for a national number when no country was submitted (no JavaScript)', function () {
    expect(phone_check('07912 345678', '', 'GB'))->toBe(['value' => '+447912345678', 'error' => null])
        ->and(phone_check('+33 6 12 34 56 78', '', 'GB')['value'])->toBe('+33612345678')
        ->and(phone_check('06 12 34 56 78', '', 'GB')['error'])->toContain('United Kingdom');
});

it('lists the preferred countries, valid codes only, the default first-class, lower case, without duplicates', function () {
    expect(phone_preferred_countries('ie, us, nope, GB', 'GB'))->toBe(['ie', 'us', 'gb'])
        ->and(phone_preferred_countries('', 'GB'))->toBe(['gb']);
});

it('wires the Contact Form 7 and WooCommerce hooks, only when the module is switched on', function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/modules/phone-e164.php');

    foreach (['wpcf7_validate_tel', 'wpcf7_validate_tel*', 'wpcf7_posted_data', 'wpcf7_feedback_response', 'woocommerce_after_checkout_validation', 'woocommerce_checkout_posted_data', 'woocommerce_store_api_checkout_update_order_from_request', 'woocommerce_after_save_address_validation'] as $hook) {
        expect($source)->toContain("'$hook'");
    }

    expect($source)->toContain("defined('PHONE_DEFAULT_REGION')")
        ->and($source)->toContain("defined('WPCF7_VERSION')")
        ->and($source)->toContain("class_exists('WooCommerce')")
        ->and(file_get_contents(dirname(__DIR__, 2) . '/composer.json'))->toContain('giggsey/libphonenumber-for-php-lite');
});
