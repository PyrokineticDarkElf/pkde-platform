<?php

// Both modules define their pure functions and return before registering a hook when WordPress is not loaded.
require_once dirname(__DIR__, 2) . '/modules/schema/local-business-schema.php';
require_once dirname(__DIR__, 2) . '/modules/schema/schema-absolute-urls.php';

use function App\MuPlugins\opening_hours_specification;
use function App\MuPlugins\schema_absolute_urls;

it('makes site-relative and protocol-relative schema URLs absolute and leaves absolute ones alone (B-06)', function () {
    $data = [
        'publisher' => ['@type' => 'Organization', 'logo' => ['@type' => 'ImageObject', 'url' => '/app/uploads/logo.png', 'contentUrl' => '//cdn.example.com/logo.png'], 'url' => 'https://example.com/'],
        'image' => '/app/uploads/hero.jpg',
        'description' => '/not-a-url-field',
        'sameAs' => ['/profile'],
    ];

    expect(schema_absolute_urls($data, 'https://example.com'))->toBe([
        'publisher' => ['@type' => 'Organization', 'logo' => ['@type' => 'ImageObject', 'url' => 'https://example.com/app/uploads/logo.png', 'contentUrl' => 'https://cdn.example.com/logo.png'], 'url' => 'https://example.com/'],
        'image' => 'https://example.com/app/uploads/hero.jpg',
        'description' => '/not-a-url-field',
        'sameAs' => ['/profile'],
    ])->and(schema_absolute_urls('/x', 'https://example.com'))->toBe('/x');
});

$week = fn(string $time) => array_map(fn($d) => ['day' => $d, 'time' => $time], ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday']);

it('prints opening hours only for a LocalBusiness and only when they are not Rank Math\'s untouched seed (P-1)', function () use ($week) {
    $real = $week('09:00-17:00');
    $real[5]['time'] = '10:00-13:00';
    $real[6]['time'] = 'closed';

    expect(opening_hours_specification($week('09:00-17:00'), true))->toBe([])
        ->and(opening_hours_specification($real, false))->toBe([])
        ->and(opening_hours_specification($real, true))->toBe([
            ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday'], 'opens' => '09:00', 'closes' => '17:00'],
            ['@type' => 'OpeningHoursSpecification', 'dayOfWeek' => ['Saturday'], 'opens' => '10:00', 'closes' => '13:00'],
        ])
        ->and(opening_hours_specification($week('09:00-17:00'), true, true))->toHaveCount(1)
        ->and(opening_hours_specification([], true))->toBe([]);
});
