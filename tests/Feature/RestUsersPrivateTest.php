<?php

require_once dirname(__DIR__, 2) . '/modules/rest-users-private.php';

use function App\MuPlugins\rest_endpoints_without_users;
use function App\MuPlugins\rest_route_is_users;
use function App\MuPlugins\rest_users_visible;

it('recognises the user routes and nothing that merely starts with the same letters', function () {
    expect(rest_route_is_users('/wp/v2/users'))->toBeTrue()
        ->and(rest_route_is_users('/wp/v2/users/me'))->toBeTrue()
        ->and(rest_route_is_users('/wp/v2/users/(?P<id>[\d]+)'))->toBeTrue()
        ->and(rest_route_is_users('/wp/v2/users/(?P<user_id>(?:[\d]+|me))/application-passwords'))->toBeTrue()
        ->and(rest_route_is_users('/wp/v2/users-extra'))->toBeFalse()
        ->and(rest_route_is_users('/wp/v2/posts'))->toBeFalse()
        ->and(rest_route_is_users('/wc/v3/customers'))->toBeFalse();
});

it('shows the user routes only to a logged-in user who can list users or edit posts', function () {
    expect(rest_users_visible(true, true, false))->toBeTrue()
        ->and(rest_users_visible(true, false, true))->toBeTrue()
        ->and(rest_users_visible(true, false, false))->toBeFalse() // a logged-in subscriber or customer
        ->and(rest_users_visible(false, true, true))->toBeFalse(); // never anonymous
});

it('removes only the user routes from the endpoint list', function () {
    $endpoints = ['/wp/v2/posts' => [1], '/wp/v2/users' => [2], '/wp/v2/users/me' => [3], '/wp/v2/users/(?P<id>[\d]+)' => [4], '/wp/v2/pages' => [5]];

    expect(array_keys(rest_endpoints_without_users($endpoints)))->toBe(['/wp/v2/posts', '/wp/v2/pages']);
});

it('is applied through the rest_endpoints filter, for everyone, in every environment', function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/modules/rest-users-private.php');

    expect($source)->toContain("add_filter('rest_endpoints'")
        ->and($source)->toContain('is_user_logged_in()')
        ->and($source)->toContain("current_user_can('list_users')")
        ->and($source)->toContain("current_user_can('edit_posts')")
        ->and($source)->not->toContain('wordfence')
        ->and($source)->not->toContain('WP_ENV');
});
