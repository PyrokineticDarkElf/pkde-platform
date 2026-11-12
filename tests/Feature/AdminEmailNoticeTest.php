<?php

require_once dirname(__DIR__, 2) . '/modules/admin-email-notice.php';

use function App\MuPlugins\admin_email_differs_from_env;

it('warns only when WP_ADMIN_EMAIL is a valid address that differs from the option, ignoring case and spaces', function () {
    expect(admin_email_differs_from_env('owner@acme-widgets.co.uk', 'office@acme-widgets.co.uk'))->toBeTrue()
        ->and(admin_email_differs_from_env('owner@acme-widgets.co.uk', ''))->toBeTrue()
        ->and(admin_email_differs_from_env('Owner@Acme-Widgets.co.uk', ' owner@acme-widgets.co.uk '))->toBeFalse()
        ->and(admin_email_differs_from_env('', 'office@acme-widgets.co.uk'))->toBeFalse()
        ->and(admin_email_differs_from_env('not an address', 'office@acme-widgets.co.uk'))->toBeFalse();
});

it('prints no address and overrides nothing', function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/modules/admin-email-notice.php');

    expect($source)->not->toContain('pre_option_admin_email')
        ->and($source)->not->toContain('update_option')
        ->and($source)->not->toMatch('/printf\([^;]*get_option/s');
});
