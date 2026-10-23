<?php

// The module defines its pure helpers and returns early outside WordPress, so it loads here without WordPress.
require_once dirname(__DIR__, 2) . '/modules/post-smtp-env.php';

use function App\MuPlugins\post_smtp_env_problem;
use function App\MuPlugins\post_smtp_env_stale;
use function App\MuPlugins\post_smtp_sender;

it('takes the first valid address: SMTP_FROM, then SMTP_USERNAME, then POST_SMTP_AUTH_USERNAME', function () {
    expect(post_smtp_sender(['from@x.test', 'user@x.test', 'auth@x.test']))->toBe('from@x.test')
        ->and(post_smtp_sender(['', 'user@x.test', 'auth@x.test']))->toBe('user@x.test')
        ->and(post_smtp_sender(['', '', 'auth@x.test']))->toBe('auth@x.test')
        ->and(post_smtp_sender([' from@x.test ', 'user@x.test']))->toBe('from@x.test');
});

it('skips a candidate that is not an email address, and returns nothing when none is', function () {
    expect(post_smtp_sender(['not-an-address', 'user@x.test']))->toBe('user@x.test')
        ->and(post_smtp_sender(['mailbox', '', 'also bad']))->toBe('')
        ->and(post_smtp_sender(['', '', '']))->toBe('')
        ->and(post_smtp_sender([]))->toBe('');
});

it('reports a host without a usable From on every environment', function () {
    foreach (['production', 'staging', 'development'] as $environment) {
        expect(post_smtp_env_problem('smtp.example.test', '', $environment))->toBe('no-from');
    }
});

it('reports production without SMTP_HOST, and only production', function () {
    expect(post_smtp_env_problem('', '', 'production'))->toBe('no-host')
        ->and(post_smtp_env_problem('', 'a@x.test', 'production'))->toBe('no-host')
        ->and(post_smtp_env_problem('', '', 'staging'))->toBeNull()
        ->and(post_smtp_env_problem('', '', 'development'))->toBeNull();
});

it('reports nothing for a configured site', function () {
    expect(post_smtp_env_problem('smtp.example.test', 'from@x.test', 'production'))->toBeNull();
});

it('finds the wanted values that the stored option lacks or holds differently, comparing as strings', function () {
    $wanted = ['transport_type' => 'smtp', 'hostname' => 'smtp.example.test', 'port' => 465, 'sender_email' => 'a@x.test', 'envelope_sender' => 'a@x.test'];

    expect(post_smtp_env_stale($wanted, false))->toBe($wanted)
        ->and(post_smtp_env_stale($wanted, []))->toBe($wanted)
        ->and(post_smtp_env_stale($wanted, ['transport_type' => 'smtp', 'hostname' => 'smtp.example.test', 'port' => '465', 'sender_email' => 'a@x.test', 'envelope_sender' => 'a@x.test', 'other' => 'kept']))->toBe([])
        ->and(post_smtp_env_stale($wanted, ['transport_type' => 'default', 'hostname' => 'smtp.example.test', 'port' => 465, 'sender_email' => 'a@x.test', 'envelope_sender' => '']))->toBe(['transport_type' => 'smtp', 'envelope_sender' => 'a@x.test']);
});

it('never lists a password as a value to store', function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/modules/post-smtp-env.php');

    expect($source)->not->toMatch("/'(basic_auth_password|password|smtp_password)'\\s*=>/");
});
