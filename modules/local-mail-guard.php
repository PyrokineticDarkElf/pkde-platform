<?php

/**
 * Plugin Name: Local mail guard
 * Description: A local site that was filled from a live database keeps Post SMTP's stored `postman_options` (the live SMTP host, login and
 *              sender) and the live Contact Form 7 recipients, so a local test form could send real mail to the real owner. When WP_ENV is
 *              'development' this module answers every read of `postman_options` with a fresh option that points Post SMTP at Mailpit
 *              (MAIL_HOST / MAIL_PORT): none of the stored values is used, so none of the live credentials is either. If MAIL_HOST is not
 *              set there is nowhere safe to deliver, so every message is dropped (wp_mail() returns false) and a line goes to the PHP error
 *              log. Staging (staging-mail-guard.php) and production are never touched: the whole file returns unless WP_ENV is 'development'.
 *
 * A live database also brings other things this cannot reach: see "After importing a live database" in docs/modules.md.
 */

namespace App\MuPlugins;

/**
 * The Post SMTP options for local development: Mailpit, no login, no encryption. Pure: it takes nothing from the stored option, so live
 * credentials, tokens and recipients in it cannot leak into a local send.
 *
 * @return array<string, string|int>
 */
function local_mail_options(string $host, int $port, string $from): array
{
    return [
        'transport_type' => 'smtp',
        'auth_type' => 'none',
        'enc_type' => 'none',
        'hostname' => $host,
        'port' => $port,
        'sender_email' => $from,
        'envelope_sender' => $from,
    ];
}

if (! function_exists('add_filter') || \Pkde\Platform\setting('WP_ENV') !== 'development') {
    return;
}

$mailSetting = fn(string $name): string => \Pkde\Platform\setting($name);

if ($host = $mailSetting('MAIL_HOST')) {
    // pre_option_: the stored row is never read, so its credentials never reach Post SMTP (or its settings screen).
    add_filter('pre_option_postman_options', function () use ($host, $mailSetting) {
        $admin = (string) get_option('admin_email');
        $from = filter_var($admin, FILTER_VALIDATE_EMAIL) ? $admin : 'wordpress@example.test';

        return local_mail_options($host, (int) ($mailSetting('MAIL_PORT') ?: 1025), $from);
    });

    // mailpit-smtp.php sets PHPMailer's own transport; assert it again last for any plugin that sends through PHPMailer directly.
    add_action('phpmailer_init', function ($phpmailer) use ($host, $mailSetting) {
        $phpmailer->isSMTP();
        $phpmailer->Host = $host;
        $phpmailer->Port = (int) ($mailSetting('MAIL_PORT') ?: 1025);
        $phpmailer->SMTPAuth = false;
        $phpmailer->SMTPAutoTLS = false;
        $phpmailer->SMTPSecure = '';
        $phpmailer->Username = '';
        $phpmailer->Password = '';
    }, 999);

    return;
}

// No Mailpit configured: send nothing.
add_filter('pre_wp_mail', function () {
    error_log('local-mail-guard: mail dropped, MAIL_HOST is not set (a local site never sends real mail).');

    return false;
}, 0);
