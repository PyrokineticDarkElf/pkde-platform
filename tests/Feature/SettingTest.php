<?php

require_once dirname(__DIR__, 2) . '/pkde-platform.php';

use function Pkde\Platform\setting;

it('reads a constant first, then the environment, and gives an empty string when it is set nowhere', function () {
    define('PKDE_TEST_SETTING_CONST', 'from-constant');
    $_ENV['PKDE_TEST_SETTING_CONST'] = 'from-env';
    $_ENV['PKDE_TEST_SETTING_ENV'] = 'GB';

    expect(setting('PKDE_TEST_SETTING_CONST'))->toBe('from-constant')
        ->and(setting('PKDE_TEST_SETTING_ENV'))->toBe('GB')
        ->and(setting('PKDE_TEST_SETTING_NOWHERE'))->toBe('');
});

it('is what the modules use instead of testing for a constant', function () {
    foreach (['phone-e164' => 'PHONE_DEFAULT_REGION', 'login-slug' => 'LOGIN_SLUG', 'post-smtp-env' => 'SMTP_HOST', 'content-signal' => 'CONTENT_SIGNAL', 'google-tag-manager' => 'GTM_CONTAINER_ID'] as $module => $name) {
        $source = file_get_contents(dirname(__DIR__, 2) . "/modules/$module.php");

        expect($source)->toContain("setting('$name')")
            ->and($source)->not->toContain("defined('$name')");
    }
});
