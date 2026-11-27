<?php

/**
 * Routes outgoing mail through the SMTP host/port set in .env (Mailpit in
 * local dev — see docker-compose.yml) instead of PHP's unconfigured default
 * mail transport, which has nowhere to actually deliver to inside the
 * container. No-ops if MAIL_HOST isn't set, e.g. a production .env relying
 * on a real transactional-mail service instead.
 */

if (! getenv('MAIL_HOST')) {
    return;
}

add_action('phpmailer_init', function ($phpmailer) {
    $phpmailer->isSMTP();
    $phpmailer->Host = getenv('MAIL_HOST');
    $phpmailer->Port = getenv('MAIL_PORT') ?: 1025;
    $phpmailer->SMTPAuth = false;
    $phpmailer->SMTPAutoTLS = false;
});

// WordPress's default From address is "wordpress@{home_url's host}" — on a
// bare "localhost" WP_HOME (this template's default) that host has no dot,
// so PHPMailer's own address validation rejects it and wp_mail() fails
// silently before ever reaching the SMTP step above. The site's own admin
// email is already guaranteed to look like a real address.
add_filter('wp_mail_from', function ($from_email) {
    return str_contains($from_email, '@localhost') ? get_option('admin_email') : $from_email;
});
