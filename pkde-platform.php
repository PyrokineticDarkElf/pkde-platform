<?php

/**
 * Plugin Name:  PKDE Platform
 * Description:  The shared must-use plugins of every PKDE site, loaded as modules from one place. Installed by Composer into
 *               web/app/mu-plugins/pkde-platform/ and picked up by the Bedrock autoloader, which includes exactly this file
 *               (it only looks at the root of each mu-plugin directory). Modules live in modules/ and are required here.
 * Author:       PKDE
 * License:      GPL-2.0-or-later
 *
 * Switch a module off for one site with PKDE_PLATFORM_DISABLE (a constant in config/application.php or an environment
 * variable), a comma-separated list of module names, for example `google-tag-manager,safe-svg`. Unknown names are ignored.
 */

namespace Pkde\Platform;

/**
 * Every module, in load order (alphabetical, as WordPress loaded them when they were single-file mu-plugins). A module is
 * modules/<name>.php, or modules/<name>/<name>.php for one with its own files. Add a new module here: the test fails if a
 * file in modules/ is missing from this list, or this list names a file that does not exist.
 *
 * @return list<string>
 */
function modules(): array
{
    return [
        'cf7-assets-on-demand',
        'cf7-test-copy',
        'coming-soon',
        'content-signal',
        'disable-comments',
        'google-tag-manager',
        'hide-wp-version',
        'local-business-schema',
        'login-slug',
        'mailpit-smtp',
        'post-smtp-env',
        'private-usernames',
        'safe-svg',
        'staging-mail-guard',
        'wordfence-local-dns',
    ];
}

/**
 * @param  list<string>  $all
 * @param  string  $disabled  comma-separated module names (PKDE_PLATFORM_DISABLE)
 * @return list<string> the modules that stay on
 */
function enabled_modules(array $all, string $disabled): array
{
    $off = array_filter(array_map('trim', explode(',', $disabled)), fn($name) => $name !== '');

    return array_values(array_diff($all, $off));
}

/** The file that loads a module: modules/<name>.php, else modules/<name>/<name>.php. */
function module_file(string $name, string $dir = __DIR__ . '/modules'): ?string
{
    foreach (["$dir/$name.php", "$dir/$name/$name.php"] as $file) {
        if (is_file($file)) {
            return $file;
        }
    }

    return null;
}

function boot(): void
{
    $disabled = defined('PKDE_PLATFORM_DISABLE') ? (string) PKDE_PLATFORM_DISABLE : (string) getenv('PKDE_PLATFORM_DISABLE');

    foreach (enabled_modules(modules(), $disabled) as $name) {
        if ($file = module_file($name)) {
            require_once $file;
        }
    }
}

// WordPress defines ABSPATH before it loads mu-plugins; the Pest tests load this file without it, for the functions only.
if (defined('ABSPATH')) {
    boot();
}
