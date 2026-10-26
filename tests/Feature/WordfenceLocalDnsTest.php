<?php

// The module defines its pure helpers and returns early outside WordPress, so it loads here without WordPress.
require_once dirname(__DIR__, 2) . '/modules/wordfence-local-dns.php';

use function App\MuPlugins\wordfence_local_dns_stale;
use function App\MuPlugins\wordfence_local_dns_value;

it('builds the entry in Wordfence\'s own format with a timestamp that does not expire for ten years', function () {
    $value = wordfence_local_dns_value(1_000_000);

    expect($value)->toBe('316360000;86400;127.0.0.1')
        ->and(preg_match('/^(\d+);(\d+);(.+)$/', $value))->toBe(1);
});

it('treats a missing, malformed or expired entry as stale and its own fresh entry as current', function () {
    $now = 2_000_000_000;

    expect(wordfence_local_dns_stale(false, $now))->toBeTrue()
        ->and(wordfence_local_dns_stale(null, $now))->toBeTrue()
        ->and(wordfence_local_dns_stale('', $now))->toBeTrue()
        ->and(wordfence_local_dns_stale('not an entry', $now))->toBeTrue()
        ->and(wordfence_local_dns_stale('1000000000;3600;127.0.0.9', $now))->toBeTrue()
        ->and(wordfence_local_dns_stale(wordfence_local_dns_value($now), $now))->toBeFalse()
        ->and(wordfence_local_dns_stale(wordfence_local_dns_value($now), $now + 86400 * 365))->toBeFalse();
});

it('applies Wordfence\'s rule that the larger of the ttl and one day counts', function () {
    expect(wordfence_local_dns_stale('1000;10;127.0.0.9', 1000 + 86399))->toBeFalse()
        ->and(wordfence_local_dns_stale('1000;10;127.0.0.9', 1000 + 86400))->toBeTrue();
});
