<?php

/**
 * Plugin Name: Post SMTP settings from .env
 * Description: Post SMTP keeps hostname, port, encryption and sender in the `postman_options` option, and only
 *              the login can come from constants (POST_SMTP_AUTH_USERNAME / _PASSWORD, defined from
 *              SMTP_USERNAME / SMTP_PASSWORD in config/application.php). This filter overlays the rest from
 *              .env so moving a site to another mail provider is an .env edit. Does nothing unless SMTP_HOST
 *              is set, so local Mailpit (MAIL_HOST, mailpit-smtp.php) is untouched. The Post SMTP settings
 *              screen will show these values and they always win over what is saved in the database.
 *
 * Post SMTP 4.0.2 marks its transport "not ready" when the From address is empty, so the sender is the first valid address of
 * SMTP_FROM, SMTP_USERNAME, POST_SMTP_AUTH_USERNAME. A site that cannot send is not left to find out from a bounced contact
 * form: an admin notice (and one error_log line a day) says so when SMTP_HOST is set without any usable From, and on production
 * when SMTP_HOST is not set at all (mail would then go out unauthenticated, from the web server).
 */

namespace App\MuPlugins;

/** The first valid email address among the candidates ('' when none is). */
function post_smtp_sender(array $candidates): string
{
    foreach ($candidates as $candidate) {
        $candidate = trim((string) $candidate);

        if ($candidate !== '' && filter_var($candidate, FILTER_VALIDATE_EMAIL)) {
            return $candidate;
        }
    }

    return '';
}

/** The wanted values that the stored option does not hold yet (compared as strings, so 465 equals '465'); [] when it is up to date. */
function post_smtp_env_stale(array $wanted, mixed $stored): array
{
    $stored = is_array($stored) ? $stored : [];

    return array_filter($wanted, fn($value, $key) => ! array_key_exists($key, $stored) || (string) $stored[$key] !== (string) $value, ARRAY_FILTER_USE_BOTH);
}

/** 'no-from' (host set, no usable From), 'no-host' (production without SMTP_HOST) or null (nothing to report). */
function post_smtp_env_problem(string $host, string $from, string $environment): ?string
{
    if ($host !== '') {
        return $from === '' ? 'no-from' : null;
    }

    return $environment === 'production' ? 'no-host' : null;
}

if (! function_exists('add_filter')) {
    return;
}

// Constants come from .env via config/application.php (Bedrock's dotenv does not use getenv()); the environment arrays are a fallback
// for the name config/application.php does not define as a constant (SMTP_USERNAME).
$setting = function (string $name): string {
    if (defined($name)) {
        return (string) constant($name);
    }

    return (string) ($_ENV[$name] ?? $_SERVER[$name] ?? '');
};

$sender = fn() => post_smtp_sender([$setting('SMTP_FROM'), $setting('SMTP_USERNAME'), $setting('POST_SMTP_AUTH_USERNAME')]);

$overlay = function ($options) use ($setting, $sender) {
    if (! $host = $setting('SMTP_HOST')) {
        return $options;
    }

    $encryption = strtolower($setting('SMTP_ENCRYPTION'));
    $values = array_filter([
        'transport_type' => 'smtp',
        'auth_type' => 'login',
        'hostname' => $host,
        'port' => (int) ($setting('SMTP_PORT') ?: 465),
        'enc_type' => in_array($encryption, ['ssl', 'tls', 'none'], true) ? $encryption : 'ssl',
        'sender_email' => $sender(),
        // Post SMTP 4 also wants the envelope sender ("Envelope-From Email Address can not be empty") before it calls the SMTP
        // transport ready; without it the active transport falls back to Default (the web server's local mail).
        'envelope_sender' => $sender(),
        // Post SMTP's "prevent plugins and themes changing this": 20i refuses mail whose From is
        // WordPress's or Contact Form 7's default (wordpress@host) instead of the mailbox.
        'prevent_sender_email_override' => 1,
        'sender_name' => $setting('SMTP_FROM_NAME'),
    ]);

    return array_merge(is_array($options) ? $options : [], $values);
};

// `option_` runs only when the option is stored; `default_option_` covers a site that has never saved one.
add_filter('option_postman_options', $overlay);
add_filter('default_option_postman_options', $overlay);

// Belt and braces: also keep the non-secret values in the stored option, so anything that reads the raw row (or runs before this
// file) sees them, and the Post SMTP screen shows what is in force. Written only when the row differs, never on every request. The
// password is NOT here: it stays a constant (POST_SMTP_AUTH_PASSWORD) and never reaches the database.
add_action('plugins_loaded', function () use ($overlay) {
    remove_filter('option_postman_options', $overlay);
    remove_filter('default_option_postman_options', $overlay);

    $wanted = $overlay([]);
    $stored = get_option('postman_options');

    if ($wanted && post_smtp_env_stale($wanted, $stored)) {
        update_option('postman_options', array_merge(is_array($stored) ? $stored : [], $wanted));
    }

    add_filter('option_postman_options', $overlay);
    add_filter('default_option_postman_options', $overlay);
}, 0);

$problem = fn() => post_smtp_env_problem($setting('SMTP_HOST'), $sender(), defined('WP_ENV') ? (string) WP_ENV : (string) wp_get_environment_type());

$message = fn(string $kind): string => $kind === 'no-from'
    ? __('Post SMTP is not configured: SMTP_HOST is set but there is no usable sender address. Set SMTP_FROM (a full email address) in the .env file.', 'pkde-text')
    : __('Mail is not authenticated: SMTP_HOST is not set in the .env file of this production site, so mail leaves the web server unauthenticated and may be rejected or marked as spam. Set SMTP_HOST, SMTP_USERNAME, SMTP_PASSWORD and SMTP_FROM.', 'pkde-text');

add_action('admin_notices', function () use ($problem, $message) {
    if (current_user_can('manage_options') && $kind = $problem()) {
        printf('<div class="notice notice-error"><p>%s</p></div>', esc_html($message($kind)));
    }
});

// Once a day, whoever looks at the admin: the log says it too.
add_action('init', function () use ($problem, $message) {
    if (($kind = $problem()) && ! get_transient('pkde_post_smtp_env_logged')) {
        set_transient('pkde_post_smtp_env_logged', 1, DAY_IN_SECONDS);
        error_log('post-smtp-env: ' . $message($kind));
    }
});
