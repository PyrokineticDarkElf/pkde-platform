<?php

require_once dirname(__DIR__, 2) . '/modules/local-mail-guard.php';

use function App\MuPlugins\local_mail_options;

/** A postman_options row as it arrives with a live database (fictional values). */
const LIVE_POSTMAN_OPTIONS = [
    'transport_type' => 'smtp',
    'auth_type' => 'login',
    'enc_type' => 'ssl',
    'hostname' => 'smtp.live-host.test',
    'port' => 465,
    'basic_auth_username' => 'owner@live-host.test',
    'basic_auth_password' => 'live-password-value',
    'sender_email' => 'owner@live-host.test',
    'envelope_sender' => 'owner@live-host.test',
    'oauth_client_secret' => 'live-oauth-value',
    'sendgrid_api_key' => 'live-api-value',
];

/**
 * Runs the module in a fresh PHP process as WordPress would: WP_ENV, a stored live postman_options row, the filter and action registry
 * stubbed. Returns what get_option('postman_options') answers and which hooks were added.
 */
function run_local_mail_guard(array $constants, array $env = []): array
{
    $script = tempnam(sys_get_temp_dir(), 'lmg');
    file_put_contents($script, '<?php
        $hooks = [];
        function add_filter($name, $callback, $priority = 10) { global $hooks; $hooks[$name][$priority][] = $callback; }
        function add_action($name, $callback, $priority = 10) { add_filter($name, $callback, $priority); }
        function get_option($name) { return $name === "admin_email" ? "dev@example.test" : false; }
        foreach (json_decode($argv[1], true) as $k => $v) { define($k, $v); }
        require ' . var_export(dirname(__DIR__, 2) . '/pkde-platform.php', true) . ';
        require ' . var_export(dirname(__DIR__, 2) . '/modules/local-mail-guard.php', true) . ';
        $answer = isset($hooks["pre_option_postman_options"]) ? $hooks["pre_option_postman_options"][10][0]() : null;
        $mail = isset($hooks["pre_wp_mail"]) ? $hooks["pre_wp_mail"][0][0]() : "not hooked";
        echo json_encode(["options" => $answer, "mail" => $mail, "hooks" => array_keys($hooks)]);
    ');

    $prefix = implode(' ', array_map(fn($k, $v) => $k . '=' . escapeshellarg($v), array_keys($env), $env));
    $output = shell_exec($prefix . ' ' . escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg(json_encode($constants)) . ' 2>/dev/null');
    unlink($script);

    return json_decode((string) $output, true);
}

it('builds Mailpit options that carry nothing from a stored live row', function () {
    $options = local_mail_options('mailpit', 1025, 'dev@example.test');
    $flat = json_encode($options);

    expect($options['hostname'])->toBe('mailpit')
        ->and($options['port'])->toBe(1025)
        ->and($options['auth_type'])->toBe('none')
        ->and($options['enc_type'])->toBe('none')
        ->and($options['envelope_sender'])->toBe('dev@example.test')
        ->and($options)->not->toHaveKeys(['basic_auth_username', 'basic_auth_password', 'oauth_client_secret', 'sendgrid_api_key']);

    foreach (LIVE_POSTMAN_OPTIONS as $key => $value) {
        if (str_contains($key, 'pass') || str_contains($key, 'secret') || str_contains($key, 'key') || str_contains($key, 'username')) {
            expect($flat)->not->toContain((string) $value);
        }
    }
    expect($flat)->not->toContain('live-host.test');
});

it('answers every read of postman_options with Mailpit in development, whatever is stored', function () {
    $result = run_local_mail_guard(['WP_ENV' => 'development', 'MAIL_HOST' => 'mailpit', 'MAIL_PORT' => '1025', 'LIVE_ROW' => 'present']);

    expect($result['options'])->toBe(local_mail_options('mailpit', 1025, 'dev@example.test'))
        ->and(json_encode($result['options']))->not->toContain('live-host.test')
        ->and($result['hooks'])->toContain('pre_option_postman_options', 'phpmailer_init');
});

it('drops all mail in development when there is no Mailpit host', function () {
    $result = run_local_mail_guard(['WP_ENV' => 'development'], ['MAIL_HOST' => '']);

    expect($result['mail'])->toBeFalse()
        ->and($result['options'])->toBeNull();
});

it('does nothing on staging, production or outside WordPress', function () {
    foreach (['staging', 'production'] as $environment) {
        $result = run_local_mail_guard(['WP_ENV' => $environment, 'MAIL_HOST' => 'mailpit']);

        expect($result['hooks'])->toBe([]);
    }

    expect(run_local_mail_guard(['MAIL_HOST' => 'mailpit'])['hooks'])->toBe([]);
});
