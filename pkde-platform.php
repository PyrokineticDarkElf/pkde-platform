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
 * variable), a comma-separated list of module names, for example `google-tag-manager,safe-svg`. Unknown names are ignored. A module made of parts
 * (mail, user-privacy, schema, cf7) is switched off whole by its own name, and one part at a time by the part's name, the name it had before the
 * parts were merged: `post-smtp-env` still switches off only that part of `mail`.
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
        'admin-email-notice',
        'cf7',
        'coming-soon',
        'content-signal',
        'disable-comments',
        'disable-emoji',
        'disable-xmlrpc',
        'editor-settings',
        'faq',
        'google-tag-manager',
        'hide-wp-version',
        'legal',
        'login-slug',
        'mail',
        'media',
        'phone-e164',
        'relevanssi-woocommerce',
        'restrict-unfiltered-html',
        'safe-svg',
        'schema',
        'shortcodes',
        'site-details',
        'staging-safety-net',
        'user-privacy',
        'woocommerce-import-slug',
        'wordfence-local-dns',
    ];
}

/**
 * The parts of the modules made of several files, in load order. A part is modules/<module>/<part>.php or modules/<module>/<part>/<part>.php, and its name is the
 * name it had as a module of its own, which PKDE_PLATFORM_DISABLE still accepts.
 *
 * @return array<string, list<string>>
 */
function module_parts(): array
{
    return [
        'cf7' => ['cf7-assets-on-demand', 'cf7-editor-preview', 'cf7-form-key'],
        'mail' => ['local-mail-guard', 'mailpit-smtp', 'post-smtp-env', 'staging-mail-guard'],
        'schema' => ['local-business-schema', 'product-schema', 'schema-absolute-urls'],
        'user-privacy' => ['author-archives', 'private-usernames', 'rest-users-private'],
    ];
}

/** The PKDE_PLATFORM_DISABLE list as it stands: the constant, else the environment. */
function disabled_setting(): string
{
    return defined('PKDE_PLATFORM_DISABLE') ? (string) PKDE_PLATFORM_DISABLE : (string) getenv('PKDE_PLATFORM_DISABLE');
}

/**
 * The parts of a module that stay on: all of them, less those named in the disabled list.
 *
 * @return list<string>
 */
function enabled_parts(string $module, string $disabled): array
{
    return enabled_modules(module_parts()[$module] ?? [], $disabled);
}

/** The file of one part of a module, or null. */
function part_file(string $module, string $part, string $dir = __DIR__ . '/modules'): ?string
{
    foreach (["$dir/$module/$part.php", "$dir/$module/$part/$part.php"] as $file) {
        if (is_file($file)) {
            return $file;
        }
    }

    return null;
}

/** Loads the parts of a module that are not switched off. Called by the module's own file. */
function load_parts(string $module): void
{
    foreach (enabled_parts($module, disabled_setting()) as $part) {
        if ($file = part_file($module, $part)) {
            require_once $file;
        }
    }
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

/**
 * A site setting by name: the constant when config/application.php defined it, else the .env value (Bedrock's dotenv fills $_ENV; getenv() and
 * Bedrock's env() are tried after), '' when it is set nowhere. A package bump plus one .env line is enough: a site does not have to port
 * the template's Config::define lines for it.
 */
function setting(string $name): string
{
    if (defined($name)) {
        return (string) constant($name);
    }

    $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

    if ($value === false && function_exists('env')) {
        $value = env($name);
    }

    return is_string($value) ? $value : '';
}

function boot(): void
{
    foreach (enabled_modules(modules(), disabled_setting()) as $name) {
        if ($file = module_file($name)) {
            require_once $file;
        }
    }
}

// WordPress defines ABSPATH before it loads mu-plugins; the Pest tests load this file without it, for the functions only.
if (defined('ABSPATH')) {
    boot();
}
