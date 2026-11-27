<?php

/**
 * Plugin Name: REST users endpoints for editors only
 * Description: WordPress answers an anonymous GET /wp-json/wp/v2/users (and /users/<id>) with the slug and name of every user who has published a post, which is
 *              user enumeration. Wordfence's "Prevent discovery of usernames" closes it, but only where Wordfence is active (not on staging) and only while
 *              the option is on. This module removes the two routes for everyone who cannot use them: the routes exist only for a logged-in user who can
 *              `list_users` or `edit_posts` (the block editor's author picker, Application Passwords of an editor, wp-admin). For anyone else the endpoint is
 *              not found (404 rest_no_route), in every environment, independently of Wordfence. Nothing else changes: post responses still carry the author's
 *              numeric ID and `_links`, which name nobody and lead nowhere once the route is gone.
 */

namespace App\MuPlugins;

/** Is this REST route one of the user routes (/wp/v2/users, /wp/v2/users/me, /wp/v2/users/<id>, with or without a regex)? Pure. */
function rest_route_is_users(string $route): bool
{
    return $route === '/wp/v2/users' || str_starts_with($route, '/wp/v2/users/');
}

/** May this visitor see the user routes at all: a logged-in user who can list users or edit posts. Pure. */
function rest_users_visible(bool $loggedIn, bool $canListUsers, bool $canEditPosts): bool
{
    return $loggedIn && ($canListUsers || $canEditPosts);
}

/** The endpoints without the user routes. Pure. */
function rest_endpoints_without_users(array $endpoints): array
{
    return array_filter($endpoints, fn($route) => ! rest_route_is_users((string) $route), ARRAY_FILTER_USE_KEY);
}

if (! function_exists('add_filter')) {
    return;
}

add_filter('rest_endpoints', function ($endpoints) {
    if (! is_array($endpoints)) {
        return $endpoints;
    }

    if (rest_users_visible(is_user_logged_in(), current_user_can('list_users'), current_user_can('edit_posts'))) {
        return $endpoints;
    }

    return rest_endpoints_without_users($endpoints);
});
