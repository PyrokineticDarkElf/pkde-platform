<?php

/**
 * Plugin Name: Content Signals (robots.txt)
 * Description: Adds a `Content-Signal:` line (Cloudflare's Content Signals Policy) to the virtual robots.txt,
 *              saying whether the site may be indexed for search, used as live input for AI answers, or used
 *              for AI training. A declared preference, not enforcement; some bots ignore it. Value comes from
 *              CONTENT_SIGNAL in .env (blank = the default below). Skipped when the site is not public, so
 *              staging and local robots.txt stay a plain disallow.
 *
 * Runs at priority 99 so it still applies when Rank Math's robots.txt editor has replaced the whole file
 * (Rank Math hooks the same filter at 10). A physical web/robots.txt would bypass WordPress and this entirely.
 */

namespace App\MuPlugins;

const CONTENT_SIGNAL_DEFAULT = 'search=yes, ai-input=yes, ai-train=no';

add_filter('robots_txt', function ($output, $public) {
    if (! $public) {
        return $output;
    }

    $value = trim(\Pkde\Platform\setting('CONTENT_SIGNAL'));
    // Anything that is not a comma list of search / ai-input / ai-train = yes|no falls back to the default.
    $signal = "(?:search|ai-input|ai-train)=(?:yes|no)";
    $line = 'Content-Signal: ' . (preg_match("/^$signal(?:\s*,\s*$signal)*$/", $value) ? $value : CONTENT_SIGNAL_DEFAULT);

    // Belongs inside the `User-agent: *` group; start one if the file has none.
    $output = preg_replace('/^User-agent: \*\R/mi', '${0}' . $line . "\n", $output, 1, $count);

    return $count ? $output : rtrim($output) . "\n\nUser-agent: *\n" . $line . "\n";
}, 99, 2);
