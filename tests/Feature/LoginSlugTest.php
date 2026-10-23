<?php

// The module defines its pure helper and returns early outside WordPress, so it loads here without WordPress.
require_once dirname(__DIR__, 2) . '/modules/login-slug.php';

use function App\MuPlugins\login_slug_clean;

it('turns a LOGIN_SLUG value into a clean slug', function () {
    expect(login_slug_clean('staff-entrance'))->toBe('staff-entrance')
        ->and(login_slug_clean('  Staff Entrance  '))->toBe('staff-entrance')
        ->and(login_slug_clean('Team_Login!2026'))->toBe('team-login-2026')
        ->and(login_slug_clean('/back-door/'))->toBe('back-door');
});

it('uses the sanitizer it is given (WordPress runtime)', function () {
    expect(login_slug_clean('Foo Bar', fn($v) => 'sanitized-' . strlen($v)))->toBe('sanitized-7');
});

it('ignores a value that is empty, only symbols, or contains wp-login', function () {
    expect(login_slug_clean(''))->toBe('')
        ->and(login_slug_clean('   '))->toBe('')
        ->and(login_slug_clean('///'))->toBe('')
        ->and(login_slug_clean('wp-login'))->toBe('')
        ->and(login_slug_clean('my-wp-login-page'))->toBe('');
});

it('is listed as a module', function () {
    expect(Pkde\Platform\modules())->toContain('login-slug');
});
