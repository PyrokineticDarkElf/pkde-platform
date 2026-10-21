<?php

/**
 * Plugin Name: Coming soon gate
 * Description: With COMING_SOON=true in .env, anyone not logged in gets a 503 "coming soon" page (Retry-After,
 *              X-Robots-Tag: noindex) instead of the site. Off by default. Uses the theme view
 *              resources/views/coming-soon.blade.php when it exists, otherwise a plain page with the site name
 *              and tagline. template_redirect only fires for front-end page requests, so wp-login.php, wp-admin,
 *              admin-ajax.php, wp-cron.php and the REST API never reach it; robots.txt, favicon and the
 *              sitemaps do, so they are let through here. The holding page is marked uncacheable (DONOTCACHEPAGE) so a
 *              page cache never stores it. At launch: remove COMING_SOON, flush the page cache, check the home page is 200.
 */

namespace App\MuPlugins;

add_action('template_redirect', function () {
    if (! (defined('COMING_SOON') && COMING_SOON) || is_user_logged_in()) {
        return;
    }

    if (is_robots() || is_favicon() || get_query_var('sitemap') || get_query_var('sitemap-stylesheet')) {
        return;
    }

    // W3 Total Cache's advanced-cache.php runs BEFORE mu-plugins and starts its output buffer, so without these the
    // first rendered holding page is stored and every later anonymous visitor gets it back as a plain 200 (no 503,
    // Retry-After or noindex). W3TC (and the other page-cache plugins) honour DONOTCACHEPAGE when the buffer ends.
    foreach (['DONOTCACHEPAGE', 'DONOTCACHEOBJECT', 'DONOTCACHEDB'] as $constant) {
        defined($constant) || define($constant, true);
    }

    status_header(503);
    nocache_headers();
    header('Retry-After: 86400');
    header('X-Robots-Tag: noindex');

    if (function_exists('\Roots\view') && \Roots\view()->exists('coming-soon')) {
        echo \Roots\view('coming-soon')->render();
        exit;
    }

    printf(
        '<!doctype html><html %s><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex"><title>%s</title></head><body style="font-family:system-ui,sans-serif;display:grid;place-items:center;min-height:100vh;margin:0;text-align:center"><main><h1>%s</h1><p>%s</p></main></body></html>',
        get_language_attributes(),
        esc_html(get_bloginfo('name')),
        esc_html(get_bloginfo('name')),
        esc_html(get_bloginfo('description') ?: __('Coming soon', 'sage')),
    );
    exit;
});
