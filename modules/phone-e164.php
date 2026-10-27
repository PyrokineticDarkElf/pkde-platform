<?php

/**
 * Plugin Name: Phone numbers in E.164 (Contact Form 7 and WooCommerce)
 * Description: OPT-IN. Google Ads Enhanced Conversions matches a conversion by the visitor's phone number, which must be E.164 (+447912345678).
 *              Neither Contact Form 7 nor WooCommerce checks more than the characters, so this module is the server-side guarantee: every
 *              phone number a visitor submits is parsed with Google's libphonenumber rules, rejected with a message that names the country when
 *              it is not a valid number, and stored and emailed as E.164. Browser side (the kit's phone.js, intl-tel-input) only helps the
 *              visitor pick the country; nothing here depends on it, so a script error or a bot posting directly gets the same result.
 *
 *              Switched on by PHONE_DEFAULT_REGION in .env (an ISO 3166-1 alpha-2 code such as GB; config/application.php defines the
 *              constant). Unset or invalid = nothing happens. PHONE_PREFERRED_COUNTRIES (comma separated, optional) lists the countries the
 *              browser's picker shows first. Contact Form 7 hooks are added only with Contact Form 7, WooCommerce hooks only with WooCommerce.
 *
 * Country used to read a number: the "+" and code the visitor typed (always wins); then the country the visitor picked (CF7: the hidden
 * `<field>-country` the picker sends; WooCommerce: the billing or shipping country of the address form); then PHONE_DEFAULT_REGION.
 */

namespace App\MuPlugins;

use libphonenumber\NumberParseException;
use libphonenumber\PhoneNumberFormat;
use libphonenumber\PhoneNumberUtil;

/** An ISO 3166-1 alpha-2 region code in upper case, or '' when it is not one libphonenumber knows. */
function phone_region(string $code): string
{
    $code = strtoupper(trim($code));

    return preg_match('/^[A-Z]{2}$/', $code) && in_array($code, PhoneNumberUtil::getInstance()->getSupportedRegions(), true) ? $code : '';
}

/** The first valid region of the candidates (a submitted country, then the site default), '' when none is. */
function phone_pick_region(string ...$candidates): string
{
    foreach ($candidates as $candidate) {
        if ($region = phone_region($candidate)) {
            return $region;
        }
    }

    return '';
}

/**
 * The number as E.164 (+447912345678), or null when it is empty or not a valid number for that region. A number typed with its own "+" and
 * country code is read as typed whatever the region.
 */
function phone_e164(string $raw, string $region): ?string
{
    $raw = trim($raw);

    if ($raw === '') {
        return null;
    }

    try {
        $util = PhoneNumberUtil::getInstance();
        $number = $util->parse($raw, phone_region($region) ?: null);

        return $util->isValidNumber($number) ? $util->format($number, PhoneNumberFormat::E164) : null;
    } catch (NumberParseException) {
        return null;
    }
}

/** The English name of a region ("United States"); the code itself when PHP's intl extension is missing. */
function phone_region_name(string $region): string
{
    return class_exists(\Locale::class) ? (\Locale::getDisplayRegion('-' . $region, 'en') ?: $region) : $region;
}

/** The text of the error: it names the country it tried, so a visitor sees what to fix. */
function phone_error_message(string $region): string
{
    $name = phone_region_name($region);

    return $region === ''
        ? __('That is not a valid phone number. Check it, or start with + and your country code.', 'pkde-text')
        /* translators: %s is a country name such as United States */
        : sprintf(__('That is not a valid phone number for %s. Check it, or start with + and your country code.', 'pkde-text'), $name);
}

/**
 * Check and normalise one submitted value. Returns ['value' => E.164 or the untouched empty string, 'error' => message or null]. Empty passes
 * (a required field is the form's own rule).
 *
 * @return array{value: string, error: ?string}
 */
function phone_check(string $raw, string $submittedRegion, string $defaultRegion): array
{
    if (trim($raw) === '') {
        return ['value' => '', 'error' => null];
    }

    $region = phone_pick_region($submittedRegion, $defaultRegion);
    $e164 = phone_e164($raw, $region);

    return $e164 === null
        ? ['value' => $raw, 'error' => phone_error_message($region)]
        : ['value' => $e164, 'error' => null];
}

/** The list of preferred countries from PHONE_PREFERRED_COUNTRIES, valid codes only, lower case (what intl-tel-input wants). */
function phone_preferred_countries(string $list, string $default): array
{
    $codes = array_filter(array_map(fn($code) => phone_region($code), explode(',', $list . ',' . $default)));

    return array_values(array_unique(array_map('strtolower', $codes)));
}

if (! function_exists('add_filter')) {
    return;
}

$defaultRegion = class_exists(PhoneNumberUtil::class) && defined('PHONE_DEFAULT_REGION') ? phone_region((string) PHONE_DEFAULT_REGION) : '';

if ($defaultRegion === '') {
    return; // opt-in: PHONE_DEFAULT_REGION is not set (or not a region)
}

// Contact Form 7 ------------------------------------------------------------------------------------------------------------------------

add_action('plugins_loaded', function () use ($defaultRegion) {
    if (! defined('WPCF7_VERSION')) {
        return;
    }

    // Validation: after CF7's own tel check (priority 10), only for a field that has no error yet.
    $validate = function ($result, $tag) use ($defaultRegion) {
        $name = (string) $tag->name;
        $raw = isset($_POST[$name]) ? trim(wp_unslash((string) $_POST[$name])) : '';

        if ($raw === '' || ! $result->is_valid($name)) {
            return $result;
        }

        $check = phone_check($raw, (string) ($_POST[$name . '-country'] ?? ''), $defaultRegion);

        if ($check['error'] !== null) {
            $result->invalidate($tag, $check['error']);
        }

        return $result;
    };
    add_filter('wpcf7_validate_tel', $validate, 20, 2);
    add_filter('wpcf7_validate_tel*', $validate, 20, 2);

    // The mail and the stored data show E.164.
    add_filter('wpcf7_posted_data', function ($data) use ($defaultRegion) {
        $form = function_exists('wpcf7_get_current_contact_form') ? wpcf7_get_current_contact_form() : null;

        if (! $form) {
            return $data;
        }

        foreach ($form->scan_form_tags(['basetype' => 'tel']) as $tag) {
            $name = (string) $tag->name;

            if (isset($data[$name]) && is_string($data[$name])) {
                $e164 = phone_e164($data[$name], phone_pick_region((string) ($_POST[$name . '-country'] ?? ''), $defaultRegion));
                $data[$name] = $e164 ?? $data[$name];
            }
        }

        return $data;
    });

    // The browser (kit phone.js) pushes the normalised phone and email into the data layer on wpcf7mailsent, for Google Ads Enhanced
    // Conversions. The response only goes back to the visitor who typed them.
    add_filter('wpcf7_feedback_response', function ($response, $result) {
        $submission = class_exists('WPCF7_Submission') ? \WPCF7_Submission::get_instance() : null;

        if (! $submission || ($response['status'] ?? '') !== 'mail_sent') {
            return $response;
        }

        $data = $submission->get_posted_data();
        $form = $submission->get_contact_form();
        $user = [];

        foreach ($form->scan_form_tags(['basetype' => 'tel']) as $tag) {
            if (! empty($data[$tag->name]) && is_string($data[$tag->name]) && str_starts_with($data[$tag->name], '+')) {
                $user['phone_number'] = $data[$tag->name];
                break;
            }
        }
        foreach ($form->scan_form_tags(['basetype' => 'email']) as $tag) {
            if (! empty($data[$tag->name]) && is_string($data[$tag->name])) {
                $user['email'] = $data[$tag->name];
                break;
            }
        }

        return $user ? $response + ['pkde_user_data' => $user] : $response;
    }, 10, 2);
});

// WooCommerce ---------------------------------------------------------------------------------------------------------------------------

add_action('plugins_loaded', function () use ($defaultRegion) {
    if (! class_exists('WooCommerce')) {
        return;
    }

    $fields = ['billing_phone' => 'billing_country', 'shipping_phone' => 'shipping_country'];

    // Classic checkout: block the order with a message, and rewrite the posted phone to E.164.
    add_action('woocommerce_after_checkout_validation', function ($data, $errors) use ($fields, $defaultRegion) {
        foreach ($fields as $phone => $country) {
            if (($phone === 'shipping_phone') && empty($data['ship_to_different_address'])) {
                continue;
            }

            $check = phone_check((string) ($data[$phone] ?? ''), (string) ($data[$country] ?? ''), $defaultRegion);

            if ($check['error'] !== null) {
                $errors->add($phone . '_invalid', $check['error'], ['id' => $phone]);
            }
        }
    }, 10, 2);
    add_filter('woocommerce_checkout_posted_data', function ($data) use ($fields, $defaultRegion) {
        foreach ($fields as $phone => $country) {
            if (! empty($data[$phone])) {
                $data[$phone] = phone_check((string) $data[$phone], (string) ($data[$country] ?? ''), $defaultRegion)['value'];
            }
        }

        return $data;
    });

    // Checkout block (Store API): the order carries E.164, or the request fails with the message.
    add_action('woocommerce_store_api_checkout_update_order_from_request', function ($order) use ($defaultRegion) {
        foreach (['billing', 'shipping'] as $type) {
            $phone = (string) $order->{"get_{$type}_phone"}();
            $check = phone_check($phone, (string) $order->{"get_{$type}_country"}(), $defaultRegion);

            if ($check['error'] !== null && class_exists(\Automattic\WooCommerce\StoreApi\Exceptions\RouteException::class)) {
                throw new \Automattic\WooCommerce\StoreApi\Exceptions\RouteException('pkde_invalid_phone', esc_html($check['error']), 400);
            }

            if ($phone !== '' && $check['value'] !== $phone) {
                $order->{"set_{$type}_phone"}($check['value']);
            }
        }
    });

    // My Account > Addresses: validate and store E.164.
    add_action('woocommerce_after_save_address_validation', function ($userId, $addressType) use ($defaultRegion) {
        $key = $addressType . '_phone';
        $check = phone_check(isset($_POST[$key]) ? wp_unslash((string) $_POST[$key]) : '', (string) ($_POST[$addressType . '_country'] ?? ''), $defaultRegion);

        if ($check['error'] !== null) {
            wc_add_notice($check['error'], 'error');
        }
    }, 10, 2);
    foreach (['billing', 'shipping'] as $type) {
        add_filter("woocommerce_process_myaccount_field_{$type}_phone", fn($value) => phone_check((string) $value, (string) ($_POST[$type . '_country'] ?? ''), $defaultRegion)['value']);
    }
});

// The browser half ------------------------------------------------------------------------------------------------------------------

// The kit's phone.js does nothing without this: the site default country and the countries the picker shows first. Printed only where Contact
// Form 7's script is, which is the existing on-demand rule (cf7-assets-on-demand): a page without a form carries nothing.
add_action('wp_footer', function () use ($defaultRegion) {
    if (! wp_script_is('contact-form-7', 'enqueued')) {
        return;
    }

    $config = [
        'region' => strtolower($defaultRegion),
        'preferred' => phone_preferred_countries(defined('PHONE_PREFERRED_COUNTRIES') ? (string) PHONE_PREFERRED_COUNTRIES : '', $defaultRegion),
    ];

    printf('<script>window.pkdePhone=%s;</script>' . "\n", wp_json_encode($config));
}, 5);
