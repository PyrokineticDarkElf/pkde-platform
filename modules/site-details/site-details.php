<?php

/**
 * Plugin Name: Site details
 * Description: One place for the business's global details: who it is, how to reach it, where it is, when it is open and where it is on social media. A site declares and seeds them in
 *              `config/pkde-site-details.php` (in git), people with the capability edit them in wp-admin (Settings > Site details), and the kit's footer, contact template, blocks, components
 *              and shortcodes read them, so the same phone number is never in three places.
 *               - storage: ONE option array, `pkde_site_details`: business (trading name, legal name, company number, VAT number, registered office), phones (label, number in E.164, how it is shown),
 *                 emails (label, address), WhatsApp, locations (label, street, town, county, postcode, country, map link, optional hours), opening hours (a weekly table and exceptions), socials (network, URL, label; in drag order);
 *               - the declaration: `config/pkde-site-details.php` returns ['type' => 'local-business'|'shop'|'organization', 'values' => [...same shape...], 'locked' => ['business.legal_name', 'business.company_number', ...]].
 *                 It seeds every field that has no stored value; a LOCKED field always shows the declared value and is read-only in wp-admin (for what only we should change);
 *               - who: the capability `manage_pkde_site_details`, given to Administrators and the SEO role;
 *               - phones are checked and stored as E.164 (the phone-e164 module's rules); what a person typed is kept for display;
 *               - it FEEDS Rank Math: the declaration's type makes the site an Organization (or a LocalBusiness) and the name, address, phone, email, opening hours and `sameAs` come from here (a value Site details does not have is left to Rank Math);
 *               - the old `client_phone`, `client_email`, `client_address` and `client_<network>` options answer with Site details for one release (a read shim: a view that still reads them is right);
 *               - for the theme: `site_details()` returns the merged array, `site_detail('business.trading_name')` one value.
 *              Switch the module off with PKDE_PLATFORM_DISABLE=site-details.
 */

namespace App\MuPlugins;

const SITE_DETAILS_OPTION = 'pkde_site_details';
const SITE_DETAILS_CAP = 'manage_pkde_site_details';
const SITE_DETAILS_VERSION = '1';

/** The known social networks (key => label); "other" carries its own label. */
const SITE_DETAILS_NETWORKS = [
    'instagram' => 'Instagram', 'facebook' => 'Facebook', 'linkedin' => 'LinkedIn', 'youtube' => 'YouTube', 'tiktok' => 'TikTok', 'x' => 'X',
    'pinterest' => 'Pinterest', 'threads' => 'Threads', 'google-business' => 'Google Business Profile', 'other' => 'Other',
];

const SITE_DETAILS_DAYS = ['mon' => 'Monday', 'tue' => 'Tuesday', 'wed' => 'Wednesday', 'thu' => 'Thursday', 'fri' => 'Friday', 'sat' => 'Saturday', 'sun' => 'Sunday'];

/** The business fields (key => label). */
const SITE_DETAILS_BUSINESS = [
    'trading_name' => 'Trading name', 'legal_name' => 'Legal name', 'company_number' => 'Company number', 'vat_number' => 'VAT number', 'registered_office' => 'Registered office',
];

/** An empty set of details, with every key present. */
function site_details_defaults(): array
{
    return [
        'business' => array_fill_keys(array_keys(SITE_DETAILS_BUSINESS), ''),
        'phones' => [],
        'emails' => [],
        'whatsapp' => '',
        'locations' => [],
        'hours' => ['weekly' => array_fill_keys(array_keys(SITE_DETAILS_DAYS), ['open' => '', 'close' => '']), 'exceptions' => []],
        'socials' => [],
    ];
}

/** A time as `HH:MM`, or ''. */
function site_details_time(mixed $value): string
{
    $value = is_string($value) ? trim($value) : '';

    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1 ? $value : '';
}

/** A date as `Y-m-d`, or ''. */
function site_details_date(mixed $value): string
{
    $value = is_string($value) ? trim($value) : '';
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
}

/**
 * A telephone number: E.164 (kept) and as typed (shown), or null when it is not a number.
 *
 * @param  callable(string, string): ?string  $e164  the checker, (raw, region) => '+44...' or null
 * @return ?array{number: string, display: string}
 */
function site_details_phone(string $raw, string $region, callable $e164): ?array
{
    $raw = trim(preg_replace('/\s+/', ' ', $raw) ?? '');

    if ($raw === '') {
        return null;
    }

    $number = $e164($raw, $region);

    return $number === null ? null : ['number' => $number, 'display' => $raw];
}

/**
 * Cleans what the screen submitted into the stored shape; anything invalid is dropped and named in `$errors`.
 *
 * @param  array<string, mixed>  $in
 * @param  callable(string, string): ?string  $e164
 * @param  list<string>  $errors  filled with plain sentences
 * @return array<string, mixed>
 */
function site_details_sanitize(array $in, callable $e164, string $region, array &$errors = []): array
{
    $out = site_details_defaults();
    $text = fn(mixed $v): string => is_string($v) ? trim(preg_replace('/\s+/', ' ', strip_tags($v)) ?? '') : '';
    $url = fn(mixed $v): string => (is_string($v) && preg_match('~^https?://[^\s<>"]+$~i', trim($v)) === 1) ? trim($v) : '';

    foreach (array_keys(SITE_DETAILS_BUSINESS) as $key) {
        $out['business'][$key] = $text($in['business'][$key] ?? '');
    }

    foreach ((array) ($in['phones'] ?? []) as $row) {
        $raw = $text($row['number'] ?? '');

        if ($raw === '') {
            continue;
        }

        $phone = site_details_phone($raw, $region, $e164);

        if ($phone === null) {
            $errors[] = "\"$raw\" is not a telephone number I can read; it was left out.";

            continue;
        }

        $out['phones'][] = ['label' => $text($row['label'] ?? ''), 'number' => $phone['number'], 'display' => $phone['display']];
    }

    $whatsapp = $text($in['whatsapp'] ?? '');

    if ($whatsapp !== '') {
        $phone = site_details_phone($whatsapp, $region, $e164);
        $phone === null ? $errors[] = "The WhatsApp number \"$whatsapp\" is not a telephone number I can read; it was left out." : $out['whatsapp'] = $phone['number'];
    }

    foreach ((array) ($in['emails'] ?? []) as $row) {
        $address = $text($row['address'] ?? '');

        if ($address === '') {
            continue;
        }

        if (filter_var($address, FILTER_VALIDATE_EMAIL) === false) {
            $errors[] = "\"$address\" is not an email address; it was left out.";

            continue;
        }

        $out['emails'][] = ['label' => $text($row['label'] ?? ''), 'address' => $address];
    }

    foreach ((array) ($in['locations'] ?? []) as $row) {
        $location = ['label' => $text($row['label'] ?? ''), 'street' => $text($row['street'] ?? ''), 'town' => $text($row['town'] ?? ''), 'county' => $text($row['county'] ?? ''),
            'postcode' => $text($row['postcode'] ?? ''), 'country' => $text($row['country'] ?? ''), 'map_url' => $url($row['map_url'] ?? '')];

        if (implode('', array_diff_key($location, ['map_url' => 1, 'label' => 1])) === '') {
            continue;
        }

        $out['locations'][] = $location;
    }

    foreach (array_keys(SITE_DETAILS_DAYS) as $day) {
        $open = site_details_time($in['hours']['weekly'][$day]['open'] ?? '');
        $close = site_details_time($in['hours']['weekly'][$day]['close'] ?? '');
        $out['hours']['weekly'][$day] = $open !== '' && $close !== '' ? ['open' => $open, 'close' => $close] : ['open' => '', 'close' => ''];
    }

    foreach ((array) ($in['hours']['exceptions'] ?? []) as $row) {
        $date = site_details_date($row['date'] ?? '');

        if ($date === '') {
            continue;
        }

        $open = site_details_time($row['open'] ?? '');
        $close = site_details_time($row['close'] ?? '');
        $out['hours']['exceptions'][] = ['date' => $date, 'label' => $text($row['label'] ?? ''), 'open' => $open !== '' && $close !== '' ? $open : '', 'close' => $open !== '' && $close !== '' ? $close : ''];
    }

    foreach ((array) ($in['socials'] ?? []) as $row) {
        $address = $url($row['url'] ?? '');
        $network = (string) ($row['network'] ?? '');

        if (($row['url'] ?? '') !== '' && $address === '') {
            $errors[] = 'A social link was not a full web address (https://...); it was left out.';
        }

        if ($address === '' || ! isset(SITE_DETAILS_NETWORKS[$network])) {
            continue;
        }

        $out['socials'][] = ['network' => $network, 'url' => $address, 'label' => $network === 'other' ? $text($row['label'] ?? '') : ''];
    }

    return $out;
}

/**
 * What may be stored: a locked path is never kept (the declaration is its only source), so a value a person forced through a read-only field is dropped.
 *
 * @param  array<string, mixed>  $clean  from site_details_sanitize()
 * @param  list<string>  $locked
 * @return array<string, mixed>
 */
function site_details_strip_locked(array $clean, array $locked): array
{
    $empty = site_details_defaults();

    foreach ($locked as $path) {
        [$group, $key] = array_pad(explode('.', $path, 2), 2, null);

        if (! array_key_exists((string) $group, $empty)) {
            continue;
        }

        if ($key !== null && is_array($empty[$group]) && array_key_exists($key, $empty[$group])) {
            $clean[$group][$key] = $empty[$group][$key];
        } elseif ($key === null) {
            $clean[$group] = $empty[$group];
        }
    }

    return $clean;
}

/**
 * The details the site shows: the declaration seeds, the stored values win, and a locked path always shows the declared value.
 *
 * @param  array<string, mixed>  $stored  the option
 * @param  array<string, mixed>  $declared  the declaration's `values`
 * @param  list<string>  $locked  paths: `business.company_number`, or a whole group such as `locations`
 * @return array<string, mixed>
 */
function site_details_merge(array $stored, array $declared, array $locked = []): array
{
    $out = site_details_defaults();
    $empty = fn($v) => $v === '' || $v === [] || $v === null;

    foreach (array_keys(SITE_DETAILS_BUSINESS) as $key) {
        $declaredValue = (string) ($declared['business'][$key] ?? '');
        $storedValue = (string) ($stored['business'][$key] ?? '');
        $out['business'][$key] = in_array("business.$key", $locked, true) || in_array('business', $locked, true) || $storedValue === '' ? $declaredValue : $storedValue;
    }

    foreach (['phones', 'emails', 'locations', 'socials', 'whatsapp'] as $group) {
        $out[$group] = in_array($group, $locked, true) || $empty($stored[$group] ?? null) ? ($declared[$group] ?? $out[$group]) : $stored[$group];
    }

    $weekly = fn(array $source) => array_filter((array) ($source['hours']['weekly'] ?? []), fn($d) => ($d['open'] ?? '') !== '');
    $useStored = ! in_array('hours', $locked, true) && ($weekly($stored) !== [] || ! $empty($stored['hours']['exceptions'] ?? null));
    $hours = $useStored ? ($stored['hours'] ?? []) : ($declared['hours'] ?? []);
    $out['hours'] = ['weekly' => array_replace($out['hours']['weekly'], (array) ($hours['weekly'] ?? [])), 'exceptions' => array_values((array) ($hours['exceptions'] ?? []))];

    return $out;
}

/** The weekly hours as rows: [day name, '09:00 to 17:00' or 'Closed'], Monday first. */
function site_details_hours_rows(array $hours): array
{
    $rows = [];

    foreach (SITE_DETAILS_DAYS as $key => $name) {
        $day = $hours['weekly'][$key] ?? [];
        $rows[] = [$name, ($day['open'] ?? '') !== '' && ($day['close'] ?? '') !== '' ? $day['open'] . ' to ' . $day['close'] : 'Closed'];
    }

    return $rows;
}

/** True when any day has opening hours. */
function site_details_has_hours(array $hours): bool
{
    foreach ((array) ($hours['weekly'] ?? []) as $day) {
        if (($day['open'] ?? '') !== '') {
            return true;
        }
    }

    return false;
}

/**
 * Rank Math's title settings with the Site details values put in. A value Site details does not have leaves Rank Math's own as it is.
 *
 * @param  array<string, mixed>  $titles  the `rank-math-options-titles` option
 * @param  array<string, mixed>  $details  the merged details
 * @param  string  $type  the declaration's type
 * @return array<string, mixed>
 */
function site_details_rank_math_titles(array $titles, array $details, string $type = ''): array
{
    // the declaration says what the site is: a business or an organisation (Rank Math's "company"), and a local business where it has an address people visit
    if (in_array($type, ['local-business', 'shop', 'organization'], true)) {
        $titles['knowledgegraph_type'] = 'company';

        if ($type === 'local-business' && in_array($titles['local_business_type'] ?? 'Organization', ['', 'Organization'], true)) {
            $titles['local_business_type'] = 'LocalBusiness';
        }
    }

    if (($details['business']['trading_name'] ?? '') !== '') {
        $titles['knowledgegraph_name'] = $details['business']['trading_name'];
    }

    if (($details['phones'][0]['number'] ?? '') !== '') {
        $titles['phone'] = $details['phones'][0]['number'];
        $titles['phone_numbers'] = array_map(fn($p) => ['type' => 'customer support', 'number' => $p['number']], $details['phones']);
    }

    if (($details['emails'][0]['address'] ?? '') !== '') {
        $titles['email'] = $details['emails'][0]['address'];
    }

    $place = $details['locations'][0] ?? [];

    if (($place['street'] ?? '') !== '' || ($place['town'] ?? '') !== '') {
        $titles['local_address'] = array_filter([
            'streetAddress' => $place['street'] ?? '', 'addressLocality' => $place['town'] ?? '', 'addressRegion' => $place['county'] ?? '',
            'postalCode' => $place['postcode'] ?? '', 'addressCountry' => $place['country'] ?? '',
        ], fn($v) => $v !== '');
    }

    if (site_details_has_hours($details['hours'] ?? [])) {
        $titles['opening_hours'] = [];

        foreach (SITE_DETAILS_DAYS as $key => $name) {
            $day = $details['hours']['weekly'][$key] ?? [];

            if (($day['open'] ?? '') !== '' && ($day['close'] ?? '') !== '') {
                $titles['opening_hours'][] = ['day' => $name, 'time' => $day['open'] . '-' . $day['close']];
            }
        }
    }

    $more = [];

    foreach ((array) ($details['socials'] ?? []) as $social) {
        match ($social['network']) {
            'facebook' => $titles['social_url_facebook'] = $social['url'],
            'x' => $titles['twitter_author_names'] = trim((string) parse_url($social['url'], PHP_URL_PATH), '/'),
            default => $more[] = $social['url'],
        };
    }

    if ($more !== []) {
        $titles['social_additional_profiles'] = implode("\n", $more);
    }

    return $titles;
}

/** The old `client_*` option names the theme and the sites' views read, and the Site details value each stands for. */
const SITE_DETAILS_CLIENT_OPTIONS = ['phone', 'email', 'address', 'facebook', 'instagram', 'linkedin', 'x', 'youtube'];

/**
 * What the old option `client_<key>` should answer now that Site details holds the value (the read shim, kept for one release so a view that still reads the option is right),
 * or null when Site details has nothing for it (the option's own value is then used).
 */
function site_details_client_value(string $key, array $details): ?string
{
    $value = match ($key) {
        'phone' => (string) ($details['phones'][0]['display'] ?? '') !== '' ? (string) $details['phones'][0]['display'] : (string) ($details['phones'][0]['number'] ?? ''),
        'email' => (string) ($details['emails'][0]['address'] ?? ''),
        'address' => implode(', ', array_filter([$details['locations'][0]['street'] ?? '', $details['locations'][0]['town'] ?? '', $details['locations'][0]['county'] ?? '', $details['locations'][0]['postcode'] ?? ''], fn($v) => $v !== '')),
        default => (function () use ($key, $details): string {
            foreach ((array) ($details['socials'] ?? []) as $social) {
                if (($social['network'] ?? '') === $key) {
                    return (string) $social['url'];
                }
            }

            return '';
        })(),
    };

    return $value === '' ? null : $value;
}

/**
 * What a site of this type must have, as sentences (for the drift check); empty when complete.
 *
 * @param  string  $type  `local-business`, `shop` or `organization`
 * @return list<string>
 */
function site_details_required(array $details, string $type): array
{
    $missing = [];

    if (($details['business']['trading_name'] ?? '') === '') {
        $missing[] = 'the trading name';
    }

    if (($details['phones'][0]['number'] ?? '') === '' && ($details['emails'][0]['address'] ?? '') === '') {
        $missing[] = 'a phone number or an email address';
    }

    if ($type === 'local-business' && ($details['locations'][0]['street'] ?? '') === '' && ($details['locations'][0]['town'] ?? '') === '') {
        $missing[] = 'an address';
    }

    if ($type === 'shop' && ($details['business']['legal_name'] ?? '') === '') {
        $missing[] = 'the legal name';
    }

    return $missing;
}

if (! function_exists('add_action')) {
    return; // outside WordPress (the unit tests load this file for the functions above)
}

// ---------------------------------------------------------------------------------------------------------------------------------------------------------------------
// the declaration and the stored values

/** The site's declaration, from config/pkde-site-details.php (or the file PKDE_SITE_DETAILS_FILE names); an empty one when there is none. */
function site_details_declaration(): array
{
    static $declaration = null;

    if ($declaration !== null) {
        return $declaration;
    }

    $file = defined('PKDE_SITE_DETAILS_FILE') ? (string) constant('PKDE_SITE_DETAILS_FILE') : (defined('ABSPATH') ? dirname(rtrim(ABSPATH, '/'), 2) . '/config/pkde-site-details.php' : '');
    $loaded = $file !== '' && is_file($file) ? require $file : [];

    return $declaration = ['type' => (string) ($loaded['type'] ?? 'organization'), 'values' => (array) ($loaded['values'] ?? []), 'locked' => array_values((array) ($loaded['locked'] ?? []))];
}

/** The merged details the site shows. */
function site_details(): array
{
    $declaration = site_details_declaration();

    return site_details_merge((array) get_option(SITE_DETAILS_OPTION, []), $declaration['values'], $declaration['locked']);
}

/** One value by path, `business.trading_name` or `phones.0.number`; the default when it is not there or empty. */
function site_detail(string $path, mixed $default = ''): mixed
{
    $value = site_details();

    foreach (explode('.', $path) as $step) {
        if (! is_array($value) || ! array_key_exists($step, $value)) {
            return $default;
        }

        $value = $value[$step];
    }

    return $value === '' || $value === [] || $value === null ? $default : $value;
}

// ---------------------------------------------------------------------------------------------------------------------------------------------------------------------
// who may edit it

function site_details_install(): void
{
    foreach (['administrator', 'pkde_seo'] as $name) {
        if ($role = get_role($name)) {
            $role->add_cap(SITE_DETAILS_CAP);
        }
    }
}

add_action('init', function () {
    if (get_option('pkde_site_details_installed') !== SITE_DETAILS_VERSION) {
        site_details_install();
        update_option('pkde_site_details_installed', SITE_DETAILS_VERSION);
    }
}, 7);

// ---------------------------------------------------------------------------------------------------------------------------------------------------------------------
// the read shim: get_option('client_phone') and the others answer with Site details (one release), so a view that still reads them is right

foreach (SITE_DETAILS_CLIENT_OPTIONS as $clientKey) {
    add_filter("pre_option_client_$clientKey", function ($pre) use ($clientKey) {
        static $busy = false;

        if ($busy) {
            return $pre;
        }

        $busy = true;
        $value = site_details_client_value($clientKey, site_details());
        $busy = false;

        return $value ?? $pre;
    });
}

// ---------------------------------------------------------------------------------------------------------------------------------------------------------------------
// Rank Math: Site details feeds it

add_filter('option_rank-math-options-titles', function ($titles) {
    return is_array($titles) ? site_details_rank_math_titles($titles, site_details(), site_details_declaration()['type']) : $titles;
});

// ---------------------------------------------------------------------------------------------------------------------------------------------------------------------
// the wp-admin screen

/** The region the phone checker assumes for a number typed without a country code. */
function site_details_region(): string
{
    return (string) apply_filters('pkde_site_details/phone_region', 'GB');
}

/** The telephone checker: the phone-e164 module's rules when it is loaded, a plain E.164 shape check otherwise. */
function site_details_e164(): callable
{
    return function (string $raw, string $region): ?string {
        if (function_exists(__NAMESPACE__ . '\\phone_e164')) {
            return phone_e164($raw, $region);
        }

        $digits = preg_replace('/[^\d+]/', '', $raw) ?? '';

        return preg_match('/^\+[1-9]\d{7,14}$/', $digits) === 1 ? $digits : null;
    };
}

add_action('admin_menu', function () {
    add_options_page('Site details', 'Site details', SITE_DETAILS_CAP, 'pkde-site-details', __NAMESPACE__ . '\\site_details_screen');
});

add_action('admin_post_pkde_site_details_save', function () {
    if (! current_user_can(SITE_DETAILS_CAP)) {
        wp_die('You may not edit the site details.', 403);
    }

    check_admin_referer('pkde_site_details_save');
    $errors = [];
    $clean = site_details_sanitize((array) wp_unslash($_POST['details'] ?? []), site_details_e164(), site_details_region(), $errors);
    update_option(SITE_DETAILS_OPTION, site_details_strip_locked($clean, site_details_declaration()['locked']), false);
    set_transient('pkde_site_details_notice_' . get_current_user_id(), $errors, 60);
    wp_safe_redirect(add_query_arg(['page' => 'pkde-site-details', 'saved' => 1], admin_url('options-general.php')));
    exit;
});

add_action('admin_enqueue_scripts', function ($hook) {
    $file = __DIR__ . '/site-details.js';

    if ($hook !== 'settings_page_pkde-site-details' || ! is_file($file)) {
        return;
    }

    wp_register_script('pkde-site-details', false, ['jquery', 'jquery-ui-sortable'], null, true);
    wp_enqueue_script('pkde-site-details');
    wp_add_inline_script('pkde-site-details', (string) file_get_contents($file));
});

/** One input: name, value, label; read-only (and not submitted as an edit) when the declaration locks it. */
function site_details_field(string $name, string $value, string $label, bool $locked, string $type = 'text', string $class = 'regular-text'): string
{
    return sprintf(
        '<label class="pkde-sd-field"><span>%s</span><input type="%s" class="%s" name="details%s" value="%s"%s></label>',
        esc_html($label),
        esc_attr($type),
        esc_attr($class),
        $name,
        esc_attr($value),
        $locked ? ' readonly disabled' : '',
    );
}

function site_details_screen(): void
{
    if (! current_user_can(SITE_DETAILS_CAP)) {
        wp_die('You may not edit the site details.', 403);
    }

    $declaration = site_details_declaration();
    $d = site_details_merge((array) get_option(SITE_DETAILS_OPTION, []), $declaration['values'], $declaration['locked']);
    $locked = fn(string $path): bool => in_array($path, $declaration['locked'], true) || in_array(explode('.', $path)[0], $declaration['locked'], true);
    $messages = get_transient('pkde_site_details_notice_' . get_current_user_id());
    $row = fn(string $group, int $i, array $fields, bool $isLocked) => '<div class="pkde-sd-row" data-row>' . implode('', array_map(
        fn($f) => site_details_field("[$group][$i][$f[0]]", (string) ($f[1] ?? ''), $f[2], $isLocked, $f[3] ?? 'text', $f[4] ?? 'regular-text'),
        $fields,
    )) . ($isLocked ? '' : '<button type="button" class="button-link pkde-sd-remove" data-remove>Remove</button>') . '</div>';

    echo '<div class="wrap pkde-site-details"><h1>Site details</h1>';

    if (isset($_GET['saved'])) { // phpcs:ignore WordPress.Security.NonceVerification
        echo '<div class="notice notice-success is-dismissible"><p>Saved.</p></div>';
    }

    foreach ((array) $messages as $message) {
        echo '<div class="notice notice-warning"><p>' . esc_html((string) $message) . '</p></div>';
    }

    delete_transient('pkde_site_details_notice_' . get_current_user_id());
    echo '<p>These are the business details every part of the site uses: the footer, the contact page, the contact blocks and the search engines\' business data. Change one here and it changes everywhere. '
        . 'A field with a padlock is set by the developer and cannot be changed here.</p>';
    echo '<form method="post" action="' . esc_url(admin_url('admin-post.php')) . '">';
    wp_nonce_field('pkde_site_details_save');
    echo '<input type="hidden" name="action" value="pkde_site_details_save">';

    echo '<h2>Business</h2>';
    foreach (SITE_DETAILS_BUSINESS as $key => $label) {
        $isLocked = $locked("business.$key");
        echo site_details_field("[business][$key]", (string) $d['business'][$key], $label . ($isLocked ? ' (locked)' : ''), $isLocked);
    }

    echo '<h2>Phone numbers</h2><div data-list="phones">';
    foreach ($d['phones'] ?: [['label' => '', 'display' => '']] as $i => $p) {
        echo $row('phones', $i, [['label', $p['label'] ?? '', 'Label (such as Sales)'], ['number', $p['display'] ?? ($p['number'] ?? ''), 'Number']], $locked('phones'));
    }
    echo '</div>' . ($locked('phones') ? '' : '<button type="button" class="button" data-add="phones">Add a number</button>');
    echo site_details_field('[whatsapp]', (string) $d['whatsapp'], 'WhatsApp number', $locked('whatsapp'));

    echo '<h2>Email addresses</h2><div data-list="emails">';
    foreach ($d['emails'] ?: [['label' => '', 'address' => '']] as $i => $e) {
        echo $row('emails', $i, [['label', $e['label'] ?? '', 'Label (such as Orders)'], ['address', $e['address'] ?? '', 'Address', 'email']], $locked('emails'));
    }
    echo '</div>' . ($locked('emails') ? '' : '<button type="button" class="button" data-add="emails">Add an address</button>');

    echo '<h2>Addresses</h2><div data-list="locations">';
    foreach ($d['locations'] ?: [['label' => '']] as $i => $l) {
        echo $row('locations', $i, [['label', $l['label'] ?? '', 'Label (such as Showroom)'], ['street', $l['street'] ?? '', 'Street'], ['town', $l['town'] ?? '', 'Town'], ['county', $l['county'] ?? '', 'County'],
            ['postcode', $l['postcode'] ?? '', 'Postcode'], ['country', $l['country'] ?? '', 'Country'], ['map_url', $l['map_url'] ?? '', 'Map link (https://...)', 'url']], $locked('locations'));
    }
    echo '</div>' . ($locked('locations') ? '' : '<button type="button" class="button" data-add="locations">Add an address</button>');

    echo '<h2>Opening hours</h2><table class="widefat striped" style="max-width:34rem"><tbody>';
    foreach (SITE_DETAILS_DAYS as $key => $name) {
        $day = $d['hours']['weekly'][$key] ?? ['open' => '', 'close' => ''];
        echo '<tr><th>' . esc_html($name) . '</th><td>'
            . site_details_field("[hours][weekly][$key][open]", (string) $day['open'], 'Opens', $locked('hours'), 'time', '') . ' '
            . site_details_field("[hours][weekly][$key][close]", (string) $day['close'], 'Closes', $locked('hours'), 'time', '') . '</td></tr>';
    }
    echo '</tbody></table><p class="description">Leave a day empty for closed.</p>';
    echo '<div data-list="exceptions">';
    foreach ($d['hours']['exceptions'] ?: [['date' => '']] as $i => $x) {
        echo $row('hours][exceptions', $i, [['date', $x['date'] ?? '', 'Date', 'date', ''], ['label', $x['label'] ?? '', 'Reason (such as Christmas Day)'], ['open', $x['open'] ?? '', 'Opens (empty: closed)', 'time', ''], ['close', $x['close'] ?? '', 'Closes', 'time', '']], $locked('hours'));
    }
    echo '</div>' . ($locked('hours') ? '' : '<button type="button" class="button" data-add="exceptions">Add a holiday or exception</button>');

    echo '<h2>Social profiles</h2><p class="description">Drag to set the order they show in.</p><div data-list="socials" data-sortable>';
    foreach ($d['socials'] ?: [['network' => 'instagram', 'url' => '']] as $i => $s) {
        $options = '';
        foreach (SITE_DETAILS_NETWORKS as $key => $label) {
            $options .= sprintf('<option value="%s"%s>%s</option>', esc_attr($key), selected($s['network'] ?? '', $key, false), esc_html($label));
        }
        echo '<div class="pkde-sd-row" data-row><span class="pkde-sd-handle" aria-hidden="true">&#8597;</span><label class="pkde-sd-field"><span>Network</span><select name="details[socials][' . (int) $i . '][network]"'
            . ($locked('socials') ? ' disabled' : '') . '>' . $options . '</select></label>'
            . site_details_field("[socials][$i][url]", (string) ($s['url'] ?? ''), 'Web address (https://...)', $locked('socials'), 'url')
            . site_details_field("[socials][$i][label]", (string) ($s['label'] ?? ''), 'Name (for Other)', $locked('socials'))
            . ($locked('socials') ? '' : '<button type="button" class="button-link pkde-sd-remove" data-remove>Remove</button>') . '</div>';
    }
    echo '</div>' . ($locked('socials') ? '' : '<button type="button" class="button" data-add="socials">Add a profile</button>');

    submit_button('Save the site details');
    echo '</form><style>.pkde-sd-field{display:inline-block;margin:0 1rem .5rem 0;vertical-align:top}.pkde-sd-field span{display:block;font-size:12px;color:#50575e}.pkde-sd-row{margin-bottom:.25rem;padding:.25rem 0;border-bottom:1px solid #dcdcde}.pkde-sd-handle{cursor:grab;margin-right:.5rem}</style></div>';
}
