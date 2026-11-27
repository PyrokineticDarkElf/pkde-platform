<?php

/**
 * Plugin Name: Author archives only for real authors
 * Description: WordPress gives every user who has published a post a public archive at /author/<login>/ (and ?author=<id> redirects to it), which
 *              exposes the login name of administrators and editors who only wrote the odd post (user enumeration). Only users with an allowed
 *              role keep an author archive; for every other user the archive answers with the theme's normal 404 (not cached by the page cache),
 *              the author link (bylines, schema, the REST `link` field) points at the home page instead, and the sitemaps list only allowed authors.
 *
 * The allowed roles, one source for all of it: ['author'] by default, `AUTHOR_ARCHIVE_ROLES` in .env (comma separated role slugs) or the filter
 * `pkde_platform/author_archive_roles` (array of slugs). A site whose blog authors are editors or administrators sets them there.
 * Rank Math: its author sitemap and its HTML author sitemap exclude every other role (Rank Math's own sitemap.exclude_roles setting, read through the
 * option, so the setting screen shows the same); WordPress' own sitemap (users provider) is limited to the allowed roles too.
 * Not covered here: the REST users list (/wp-json/wp/v2/users) and oEmbed still expose a user's name and slug (a separate decision).
 */

namespace App\MuPlugins;

/** The allowed role slugs from a configured list: strings only, trimmed, lower case, each once. Pure. @return list<string> */
function author_archive_roles(array $configured): array
{
    $roles = [];

    foreach ($configured as $role) {
        $role = strtolower(trim((string) $role));

        if ($role !== '' && ! in_array($role, $roles, true)) {
            $roles[] = $role;
        }
    }

    return $roles;
}

/** The roles from the .env value (comma separated); the default when it is empty. Pure. @return list<string> */
function author_archive_roles_from_setting(string $setting, array $default = ['author']): array
{
    $roles = author_archive_roles(explode(',', $setting));

    return $roles === [] ? $default : $roles;
}

/** Does this user (its role slugs) hold an allowed role? Pure. */
function author_has_allowed_role(array $userRoles, array $allowed): bool
{
    return array_intersect($allowed, array_map('strtolower', array_map('strval', $userRoles))) !== [];
}

/** The role slugs Rank Math must exclude from its author sitemaps: every existing role that is not allowed. Pure. @return list<string> */
function author_sitemap_excluded_roles(array $existingRoles, array $allowed): array
{
    return array_values(array_diff(array_map('strval', $existingRoles), $allowed));
}

if (! function_exists('add_action')) {
    return;
}

/** The allowed roles for this request: default, then .env, then the filter. @return list<string> */
function author_archive_allowed_roles(): array
{
    static $cache = null;

    if ($cache === null) {
        $roles = author_archive_roles_from_setting(\Pkde\Platform\setting('AUTHOR_ARCHIVE_ROLES'));
        $cache = author_archive_roles((array) apply_filters('pkde_platform/author_archive_roles', $roles));
    }

    return $cache;
}

function author_archive_user_allowed(\WP_User|false|null $user): bool
{
    return $user instanceof \WP_User && author_has_allowed_role((array) $user->roles, author_archive_allowed_roles());
}

// The archive: the theme's normal 404. It is decided on `wp` (right after the query, before Rank Math caches the page type for its title, robots and schema,
// which otherwise still describe the author) and again on template_redirect at priority 1 (before redirect_canonical turns ?author=N into
// /author/<name>/). Never cached: the 404 flags are set, W3 Total Cache is told not to store the page, and the headers say so.
function author_archive_close(): void
{
    if (! is_author() || author_archive_user_allowed(get_queried_object())) {
        return;
    }

    global $wp_query;
    $wp_query->set_404();
    status_header(404);
    nocache_headers();

    if (! defined('DONOTCACHEPAGE')) {
        define('DONOTCACHEPAGE', true);
    }
}

add_action('wp', __NAMESPACE__ . '\\author_archive_close', 0);
add_action('template_redirect', __NAMESPACE__ . '\\author_archive_close', 1);

// Bylines, schema and the REST `link` field must not point at the dead URL: the home page instead. wp-admin keeps the real link (its Users list).
add_filter('author_link', function ($link, $authorId) {
    if (is_admin()) {
        return $link;
    }

    return author_archive_user_allowed(get_userdata((int) $authorId)) ? $link : home_url('/');
}, 10, 2);

// Rank Math: its author sitemap, its HTML sitemap and the sitemap index counts all read sitemap.exclude_roles.
add_filter('option_rank-math-options-sitemap', function ($options) {
    if (! is_array($options)) {
        return $options;
    }

    $options['exclude_roles'] = author_sitemap_excluded_roles(array_keys((array) (function_exists('wp_roles') ? wp_roles()->roles : [])), author_archive_allowed_roles());

    return $options;
});

// WordPress' own sitemap (used when Rank Math's is off) lists every user with a published post: only the allowed roles.
add_filter('wp_sitemaps_users_query_args', function ($args) {
    $args = (array) $args;
    $args['role__in'] = author_archive_allowed_roles() ?: ['pkde-no-allowed-role']; // an empty list would mean every role

    return $args;
});
