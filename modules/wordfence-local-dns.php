<?php

/**
 * Plugin Name: Wordfence local DNS cache
 * Description: On a LOCAL stack only. Wordfence looks up the site's own DNS on every request (wfUtils::serverIPs() calls dns_get_record()
 *              on the home host) and its host pattern keeps the port, so with WP_HOME=http://localhost:8092 it asks for "localhost:8092".
 *              That name never resolves, the lookup takes about a second, twice, and a failed lookup is never cached, so every page took
 *              about 2.2 s. Seeding its own cache entry (`serverDNS`, format `timestamp;ttl;ip`) with a far-future timestamp ends the
 *              lookups: pages answer in about 0.1 s. Does nothing outside WP_ENVIRONMENT_TYPE=local (staging and production have no port in
 *              their home URL and a real name), nothing when Wordfence is not active, and writes only when the entry is missing or expired.
 */

namespace App\MuPlugins;

/** The cache entry Wordfence reads: timestamp (far in the future, so it never expires), ttl and the loopback address. */
function wordfence_local_dns_value(int $now): string
{
    return ($now + 315360000) . ';86400;127.0.0.1';
}

/** True when the stored `serverDNS` is missing, malformed or expired by Wordfence's own rule (timestamp + max(ttl, 86400) <= now). */
function wordfence_local_dns_stale(mixed $stored, int $now): bool
{
    if (! is_string($stored) || ! preg_match('/^(\d+);(\d+);(.+)$/', $stored, $m)) {
        return true;
    }

    return (int) $m[1] + max((int) $m[2], 86400) <= $now;
}

if (! function_exists('add_action')) {
    return;
}

add_action('plugins_loaded', function () {
    if (wp_get_environment_type() !== 'local' || ! class_exists('wfConfig')) {
        return;
    }

    try {
        if (wordfence_local_dns_stale(\wfConfig::get('serverDNS'), time())) {
            \wfConfig::set('serverDNS', wordfence_local_dns_value(time()));
        }
    } catch (\Throwable $e) {
        // Wordfence's tables may not exist yet (first activation): try again on the next request.
    }
}, 20);
