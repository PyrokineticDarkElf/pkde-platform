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

add_filter('rank_math/json_ld', function ($data) {
    if (! class_exists('\RankMath\Helper') || ! is_array($data)) {
        return $data;
    }

    $setting = fn(string $key) => \RankMath\Helper::get_settings('titles.' . $key);

    $address = (array) $setting('local_address');
    $phones = array_values(array_filter(array_map(fn($p) => $p['number'] ?? '', (array) $setting('phone_numbers'))));
    $geo = array_map('trim', explode(',', (string) $setting('geo')));
    $businessType = (string) $setting('local_business_type');

    // Group days that share the same hours into one OpeningHoursSpecification.
    $byHours = [];

    foreach ((array) $setting('opening_hours') as $row) {
        if (! empty($row['day']) && preg_match('/^(\d{2}:\d{2})-(\d{2}:\d{2})$/', $row['time'] ?? '', $m)) {
            $byHours[$m[1] . '-' . $m[2]][] = $row['day'];
        }
    }

    $hours = [];

    foreach ($byHours as $range => $days) {
        [$opens, $closes] = explode('-', $range);
        $hours[] = ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => $days, 'opens' => $opens, 'closes' => $closes];
    }

    $extra = array_filter([
        'description' => (string) $setting('organization_description'),
        'telephone' => $phones[0] ?? '',
        'email' => (string) $setting('email'),
        'address' => array_filter($address) ? array_merge(['@type' => 'PostalAddress'], array_filter($address)) : null,
        'geo' => count($geo) === 2 && is_numeric($geo[0]) && is_numeric($geo[1]) ? ['@type' => 'GeoCoordinates', 'latitude' => (float) $geo[0], 'longitude' => (float) $geo[1]] : null,
        'openingHoursSpecification' => $hours ?: null,
    ]);

    foreach ($data as $key => $node) {
        if (! is_array($node) || ! in_array('Organization', (array) ($node['@type'] ?? []), true)) {
            continue;
        }

        $types = (array) $node['@type'];

        if ($businessType && $businessType !== 'Organization' && ! in_array($businessType, $types, true)) {
            $types[] = $businessType;
        }

        $data[$key] = array_merge($node, $extra, ['@type' => count($types) === 1 ? $types[0] : $types]);
    }

    return $data;
}, 99);
