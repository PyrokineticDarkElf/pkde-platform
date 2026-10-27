<?php

require_once dirname(__DIR__, 2) . '/modules/local-mail-guard.php';

use function App\MuPlugins\local_blocked_host;
use function App\MuPlugins\local_gateway_allowed;
use function App\MuPlugins\local_mail_options;
use function App\MuPlugins\local_stripe_settings;

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
        class WP_Error { function __construct(public $code = "", public $message = "") {} }
        $live = ["testmode" => "no", "publishable_key" => "pk_live_x", "secret_key" => "sk_live_x", "webhook_secret" => "whsec_x", "test_secret_key" => "sk_test_x", "enabled" => "yes"];
        $extra = [
            "admin_email" => isset($hooks["pre_option_admin_email"]) ? $hooks["pre_option_admin_email"][10][0]() : null,
            "wc_recipient" => isset($hooks["option_woocommerce_new_order_settings"]) ? $hooks["option_woocommerce_new_order_settings"][10][0](["recipient" => "owner@live-host.test", "enabled" => "yes"]) : null,
            "cf7" => isset($hooks["wpcf7_mail_components"]) ? $hooks["wpcf7_mail_components"][999][0](["recipient" => "owner@live-host.test", "body" => "b"]) : null,
            "stripe" => isset($hooks["option_woocommerce_stripe_settings"]) ? $hooks["option_woocommerce_stripe_settings"][10][0]($live) : null,
            "sender_blocked" => isset($hooks["pre_http_request"]) ? $hooks["pre_http_request"][0][0](false, [], "https://api.sender.net/v2/subscribers") instanceof WP_Error : null,
            "stripe_http" => isset($hooks["pre_http_request"]) ? $hooks["pre_http_request"][0][0](false, [], "https://api.stripe.com/v1/charges") : null,
        ];
        class Gw { function __construct(public $testmode = "", public $opts = []) {} function get_option($k) { return $this->opts[$k] ?? ""; } }
        $gws = ["bacs" => new Gw(), "cod" => new Gw(), "stripe" => new Gw("yes"), "paypal" => new Gw("", ["testmode" => "no"]), "ppcp" => new Gw("", ["sandbox" => "yes"]), "woocommerce_payments" => new Gw(), "square" => new Gw("no")];
        $extra["gateways"] = isset($hooks["woocommerce_available_payment_gateways"]) ? array_keys($hooks["woocommerce_available_payment_gateways"][999][0]($gws)) : null;
        $mail = isset($hooks["pre_wp_mail"]) ? $hooks["pre_wp_mail"][0][0]() : "not hooked";
        echo json_encode(["extra" => $extra, "options" => $answer, "mail" => $mail, "hooks" => array_keys($hooks)]);
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

    expect($result['options'])->toBe(local_mail_options('mailpit', 1025, 'local-test@example.com'))
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

it('keeps live state out of a local site at runtime: admin email, recipients, Stripe and marketing APIs', function () {
    $extra = run_local_mail_guard(['WP_ENV' => 'development', 'MAIL_HOST' => 'mailpit'])['extra'];

    expect($extra['admin_email'])->toBe('local-test@example.com')
        ->and($extra['wc_recipient'])->toBe(['recipient' => 'local-test@example.com', 'enabled' => 'yes'])
        ->and($extra['cf7']['recipient'])->toBe('local-test@example.com')
        ->and($extra['stripe']['testmode'])->toBe('yes')
        ->and($extra['stripe']['secret_key'])->toBe('')
        ->and($extra['stripe']['publishable_key'])->toBe('')
        ->and($extra['stripe']['webhook_secret'])->toBe('')
        ->and($extra['stripe']['test_secret_key'])->toBe('sk_test_x')
        ->and($extra['stripe']['enabled'])->toBe('yes')
        ->and($extra['sender_blocked'])->toBeTrue()
        ->and($extra['stripe_http'])->toBeFalse();
});

it('guards the data even when there is no Mailpit host', function () {
    $extra = run_local_mail_guard(['WP_ENV' => 'development'], ['MAIL_HOST' => ''])['extra'];

    expect($extra['admin_email'])->toBe('local-test@example.com')
        ->and($extra['stripe']['testmode'])->toBe('yes');
});

it('recognises marketing and newsletter hosts and nothing else', function () {
    foreach (['https://api.sender.net/v2/x', 'https://us5.api.mailchimp.com/3.0/', 'https://x.list-manage.com/subscribe', 'https://api.brevo.com/v3', 'https://a.klaviyo.com/api'] as $url) {
        expect(local_blocked_host($url))->toBeTrue();
    }
    foreach (['https://api.stripe.com/v1', 'https://example.com/mailchimp.com', 'https://notmailchimp.com/', 'not a url', ''] as $url) {
        expect(local_blocked_host($url))->toBeFalse();
    }
});

it('blanks every Stripe key, secret, token and webhook except the test ones, and forces test mode', function () {
    $out = local_stripe_settings(['testmode' => 'no', 'publishable_key' => 'a', 'secret_key' => 'b', 'webhook_secret' => 'c', 'token' => 'd', 'test_publishable_key' => 'e', 'title' => 'Card']);

    expect($out)->toBe(['testmode' => 'yes', 'publishable_key' => '', 'secret_key' => '', 'webhook_secret' => '', 'token' => '', 'test_publishable_key' => 'e', 'title' => 'Card'])
        ->and(local_stripe_settings(false))->toBeFalse();
});

it('keeps only offline gateways and gateways in test mode at checkout', function () {
    expect(local_gateway_allowed('bacs', ''))->toBeTrue()
        ->and(local_gateway_allowed('cheque', 'no'))->toBeTrue()
        ->and(local_gateway_allowed('stripe', 'yes'))->toBeTrue()
        ->and(local_gateway_allowed('ppcp-gateway', '1'))->toBeTrue()
        ->and(local_gateway_allowed('paypal', 'no'))->toBeFalse()
        ->and(local_gateway_allowed('woocommerce_payments', ''))->toBeFalse();

    $extra = run_local_mail_guard(['WP_ENV' => 'development', 'MAIL_HOST' => 'mailpit'])['extra'];

    expect($extra['gateways'])->toBe(['bacs', 'cod', 'stripe', 'ppcp']);
});

it('does not touch gateways on staging or production', function () {
    expect(run_local_mail_guard(['WP_ENV' => 'production', 'MAIL_HOST' => 'mailpit'])['hooks'])->toBe([]);
});
