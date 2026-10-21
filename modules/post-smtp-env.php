<?php

/**
 * Plugin Name: Post SMTP settings from .env
 * Description: Post SMTP keeps hostname, port, encryption and sender in the `postman_options` option, and only
 *              the login can come from constants (POST_SMTP_AUTH_USERNAME / _PASSWORD, defined from
 *              SMTP_USERNAME / SMTP_PASSWORD in config/application.php). This filter overlays the rest from
 *              .env so moving a site to another mail provider is an .env edit. Does nothing unless SMTP_HOST
 *              is set, so local Mailpit (MAIL_HOST, mailpit-smtp.php) is untouched. The Post SMTP settings
 *              screen will show these values and they always win over what is saved in the database.
 */

namespace App\MuPlugins;

$overlay = function ($options) {
    // Constants come from .env via config/application.php (Bedrock's dotenv does not use getenv()).
    $setting = fn(string $name) => defined($name) ? (string) constant($name) : '';

    if (! $host = $setting('SMTP_HOST')) {
        return $options;
    }

    $encryption = strtolower($setting('SMTP_ENCRYPTION'));
    $from = $setting('SMTP_FROM') ?: $setting('POST_SMTP_AUTH_USERNAME');
    $values = array_filter([
        'transport_type' => 'smtp',
        'auth_type' => 'login',
        'hostname' => $host,
        'port' => (int) ($setting('SMTP_PORT') ?: 465),
        'enc_type' => in_array($encryption, ['ssl', 'tls', 'none'], true) ? $encryption : 'ssl',
        'sender_email' => $from,
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
