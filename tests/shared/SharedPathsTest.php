<?php

require_once __DIR__ . '/site-root.php';

// config/shared-paths.php is pure, so it loads here without WordPress.
require_once pkde_site_root() . '/config/shared-paths.php';

function pkdeSharedLayout(bool $withLanguages = true, bool $symlinkEnv = true): string
{
    $base = sys_get_temp_dir() . '/pkde-shared-' . bin2hex(random_bytes(4));
    mkdir("$base/shared", 0777, true);
    mkdir("$base/releases/R1", 0777, true);
    file_put_contents("$base/shared/.env", "WP_ENV=production\n");
    if ($withLanguages) {
        mkdir("$base/shared/languages");
    }
    $symlinkEnv ? symlink("$base/shared/.env", "$base/releases/R1/.env") : file_put_contents("$base/releases/R1/.env", "WP_ENV=development\n");

    return $base;
}

it('finds shared/languages next to the symlinked .env of a release', function () {
    $base = pkdeSharedLayout();

    expect(pkde_shared_languages_dir("$base/releases/R1"))->toBe(realpath("$base/shared") . '/languages');
});

it('takes WP_LANG_DIR from the environment first, without a trailing slash', function () {
    $base = pkdeSharedLayout();

    expect(pkde_shared_languages_dir("$base/releases/R1", '/srv/shared/languages/'))->toBe('/srv/shared/languages');
});

it('defines nothing locally (a real .env) or without a shared languages directory', function () {
    expect(pkde_shared_languages_dir(pkdeSharedLayout(true, false) . '/releases/R1'))->toBeNull()
        ->and(pkde_shared_languages_dir(pkdeSharedLayout(false) . '/releases/R1'))->toBeNull()
        ->and(pkde_shared_languages_dir(sys_get_temp_dir() . '/pkde-no-such-root'))->toBeNull()
        ->and(pkde_shared_languages_dir(pkdeSharedLayout() . '/releases/R1', '  '))->not->toBeNull();
});

it('believes X-Forwarded-Proto from anyone unless TRUSTED_PROXIES is set, then only from those addresses', function () {
    $https = ['HTTP_X_FORWARDED_PROTO' => 'https', 'REMOTE_ADDR' => '203.0.113.9'];

    expect(pkde_proxy_https($https))->toBeTrue()
        ->and(pkde_proxy_https(['REMOTE_ADDR' => '203.0.113.9']))->toBeFalse()
        ->and(pkde_proxy_https(['HTTP_X_FORWARDED_PROTO' => 'http'], ''))->toBeFalse()
        ->and(pkde_proxy_https($https, '203.0.113.9'))->toBeTrue()
        ->and(pkde_proxy_https($https, '198.51.100.0/24, 203.0.113.0/24'))->toBeTrue()
        ->and(pkde_proxy_https($https, '198.51.100.0/24'))->toBeFalse()
        ->and(pkde_proxy_https(['HTTP_X_FORWARDED_PROTO' => 'https', 'REMOTE_ADDR' => '2001:db8::1'], '2001:db8::/32'))->toBeTrue()
        ->and(pkde_proxy_https(['HTTP_X_FORWARDED_PROTO' => 'https', 'REMOTE_ADDR' => '2001:db9::1'], '2001:db8::/32'))->toBeFalse()
        ->and(pkde_proxy_https($https, '203.0.113.128/25'))->toBeFalse()
        ->and(pkde_proxy_https($https, 'not-an-ip'))->toBeFalse();
});

it('skips a trusted proxy entry with a missing or non-numeric prefix instead of reading it as /0 (everybody)', function () {
    $https = ['HTTP_X_FORWARDED_PROTO' => 'https', 'REMOTE_ADDR' => '203.0.113.9'];

    expect(pkde_proxy_https($https, '10.0.0.0/'))->toBeFalse()
        ->and(pkde_proxy_https($https, '10.0.0.0/x'))->toBeFalse()
        ->and(pkde_proxy_https($https, '10.0.0.0/8x'))->toBeFalse()
        ->and(pkde_proxy_https($https, '10.0.0.0/, 203.0.113.0/24'))->toBeTrue()
        ->and(pkde_proxy_https($https, '203.0.113.0/0'))->toBeTrue(); // an explicit /0 is still what it says
});
