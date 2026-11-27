<?php

/**
 * One entry point for the plugin settings scripts: the same options each of them always had, named by the plugin.
 *
 *   bash bin/deploy/wp.sh eval-file bin/deploy/settings.php rankmath [only=...]      Rank Math to the standard (rankmath-baseline.php)
 *   bash bin/deploy/wp.sh eval-file bin/deploy/settings.php w3tc                     W3 Total Cache to the standard (w3tc-settings.php)
 *   bash bin/deploy/wp.sh eval-file bin/deploy/settings.php wordfence                Wordfence's alert recipients and the standard (wordfence-standard.php)
 *   bash bin/deploy/wp.sh eval-file bin/deploy/settings.php relevanssi               Relevanssi for the shop search (relevanssi-settings.php)
 *   bash bin/deploy/wp.sh eval-file bin/deploy/settings.php cf7-key <key> ...        the form-key meta of Contact Form 7 forms (cf7-form-key.php)
 *   bash bin/deploy/wp.sh eval-file bin/deploy/settings.php sitemap                  the SEO sitemap exclusions (seo-sitemap-exclusions.php)
 *
 * The arguments after the name go to that script unchanged, so every option works as before; the scripts themselves (and their *-standard.php, which
 * drift-check.php reads) are still the old files and can still be run on their own. `bin/setup` of a site runs this file, and it can be run by hand.
 * Locally: docker compose run --rm -v "$PWD/../pkde-platform:/pkde-platform:ro" wpcli wp --allow-root eval-file /pkde-platform/bin/deploy/settings.php <name>
 * The file must start with <?php and is run with wp eval-file in ONE SSH session, like the scripts it starts.
 */

/** @return array<string, string> the name to the script, relative to this folder */
function pkde_settings_scripts(): array
{
    return [
        'rankmath' => 'rankmath-baseline.php',
        'w3tc' => 'w3tc-settings.php',
        'wordfence' => 'wordfence-standard.php',
        'relevanssi' => 'relevanssi-settings.php',
        'cf7-key' => 'cf7-form-key.php',
        'sitemap' => 'seo-sitemap-exclusions.php',
    ];
}

/** The script for a name, or null when there is none (an unknown name, or empty). */
function pkde_settings_script(?string $name): ?string
{
    return pkde_settings_scripts()[(string) $name] ?? null;
}

if (! defined('ABSPATH') || ! class_exists('WP_CLI')) {
    return; // loaded outside WP-CLI (the Pest test): the functions above are all there is
}

$pkdeSettingsName = array_shift($args);
$pkdeSettingsFile = pkde_settings_script($pkdeSettingsName);

if ($pkdeSettingsFile === null) {
    WP_CLI::error('Name the settings to apply: ' . implode(', ', array_keys(pkde_settings_scripts())) . '.');
}

require __DIR__ . '/' . $pkdeSettingsFile;
