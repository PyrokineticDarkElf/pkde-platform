<?php

/**
 * Plugin Name: Local Business Schema
 * Description: Rank Math (free) stores the Local SEO fields — business type, address, phone numbers,
 *              opening hours, geo coordinates — but only outputs a bare Organization (name + logo).
 *              This enriches that Organization node in the JSON-LD with those same settings, so they
 *              stay editable in wp-admin (Rank Math → Titles & Meta → Local SEO) and the markup is
 *              what search engines use for "<business> near <town>" results.
 */

namespace App\MuPlugins;

/**
 * The OpeningHoursSpecification list for the hours Rank Math stores, or [] when the hours should not be printed (P-1):
 *  - Rank Math seeds Monday to Sunday 09:00-17:00 on every install, so an untouched site would otherwise tell search engines it is open all week;
 *    those seven identical rows count as "not set". A business that really is open 09:00-17:00 every day returns true from the filter
 *    `pkde_platform/opening_hours_are_set`.
 *  - Hours belong on a LocalBusiness, not on a bare Organization (a shop's company).
 *
 * @param  list<array{day?: string, time?: string}>  $rows  Rank Math's titles.opening_hours
 * @param  bool  $localBusiness  the node has a business type besides Organization
 * @param  bool  $confirmed  the site says its hours are real even if they equal the seed
 * @return list<array<string, mixed>>
 */
function opening_hours_specification(array $rows, bool $localBusiness, bool $confirmed = false): array
{
    if (! $localBusiness) {
        return [];
    }

    $seed = array_map(fn($day) => ['day' => $day, 'time' => '09:00-17:00'], ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']);

    if (! $confirmed && array_map(fn($r) => ['day' => $r['day'] ?? '', 'time' => $r['time'] ?? ''], $rows) === $seed) {
        return [];
    }

    // Group days that share the same hours into one OpeningHoursSpecification.
    $byHours = [];

    foreach ($rows as $row) {
        if (! empty($row['day']) && preg_match('/^(\d{2}:\d{2})-(\d{2}:\d{2})$/', $row['time'] ?? '', $m)) {
            $byHours[$m[1] . '-' . $m[2]][] = $row['day'];
        }
    }

    $hours = [];

    foreach ($byHours as $range => $days) {
        [$opens, $closes] = explode('-', $range);
        $hours[] = ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $days, 'opens' => $opens, 'closes' => $closes];
    }

    return $hours;
}

if (! function_exists('add_filter')) {
    return; // loaded outside WordPress (the Pest tests): the function above is all there is
}

add_filter('rank_math/json_ld', function ($data) {
    if (! class_exists('\RankMath\Helper') || ! is_array($data)) {
        return $data;
    }

    $setting = fn(string $key) => \RankMath\Helper::get_settings('titles.' . $key);

    $address = (array) $setting('local_address');
    $phones = array_values(array_filter(array_map(fn($p) => $p['number'] ?? '', (array) $setting('phone_numbers'))));
    $geo = array_map('trim', explode(',', (string) $setting('geo')));
    $businessType = (string) $setting('local_business_type');

    $extra = array_filter([
        'description' => (string) $setting('organization_description'),
        'telephone' => $phones[0] ?? '',
        'email' => (string) $setting('email'),
        'address' => array_filter($address) ? array_merge(['@type' => 'PostalAddress'], array_filter($address)) : null,
        'geo' => count($geo) === 2 && is_numeric($geo[0]) && is_numeric($geo[1]) ? ['@type' => 'GeoCoordinates', 'latitude' => (float) $geo[0], 'longitude' => (float) $geo[1]] : null,
    ]);

    foreach ($data as $key => $node) {
        if (! is_array($node) || ! in_array('Organization', (array) ($node['@type'] ?? []), true)) {
            continue;
        }

        $types = (array) $node['@type'];

        if ($businessType && $businessType !== 'Organization' && ! in_array($businessType, $types, true)) {
            $types[] = $businessType;
        }

        $hours = opening_hours_specification((array) $setting('opening_hours'), count(array_diff($types, ['Organization'])) > 0, (bool) apply_filters('pkde_platform/opening_hours_are_set', false));

        $data[$key] = array_merge($node, $extra, $hours ? ['openingHoursSpecification' => $hours] : [], ['@type' => count($types) === 1 ? $types[0] : $types]);
    }

    return $data;
}, 99);
