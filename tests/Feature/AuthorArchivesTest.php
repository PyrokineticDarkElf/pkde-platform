<?php

require_once dirname(__DIR__, 2) . '/pkde-platform.php';
require_once dirname(__DIR__, 2) . '/modules/author-archives.php';

use function App\MuPlugins\author_archive_roles;
use function App\MuPlugins\author_archive_roles_from_setting;
use function App\MuPlugins\author_has_allowed_role;
use function App\MuPlugins\author_sitemap_excluded_roles;

it('normalises a configured role list: trimmed, lower case, each once, strings only', function () {
    expect(author_archive_roles([' Author ', 'author', 'team_member', '', 7]))->toBe(['author', 'team_member', '7']);
});

it('reads the roles from the .env value and falls back to author when it is empty', function () {
    expect(author_archive_roles_from_setting('author, editor'))->toBe(['author', 'editor'])
        ->and(author_archive_roles_from_setting(''))->toBe(['author'])
        ->and(author_archive_roles_from_setting(' , ,'))->toBe(['author']);
});

it('allows a user who holds at least one allowed role, and nobody else', function () {
    expect(author_has_allowed_role(['author'], ['author']))->toBeTrue()
        ->and(author_has_allowed_role(['subscriber', 'Author'], ['author']))->toBeTrue()
        ->and(author_has_allowed_role(['administrator', 'author'], ['author']))->toBeTrue() // an administrator who also holds author (multi-role) keeps the archive
        ->and(author_has_allowed_role(['administrator'], ['author']))->toBeFalse()
        ->and(author_has_allowed_role([], ['author']))->toBeFalse()
        ->and(author_has_allowed_role(['author'], []))->toBeFalse();
});

it('excludes from the author sitemaps every existing role that is not allowed', function () {
    expect(author_sitemap_excluded_roles(['administrator', 'editor', 'author', 'subscriber'], ['author']))->toBe(['administrator', 'editor', 'subscriber'])
        ->and(author_sitemap_excluded_roles(['administrator', 'author'], []))->toBe(['administrator', 'author']);
});

it('answers the archive of a user without an allowed role with the theme 404, early and uncached, and points the author link home', function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/modules/author-archives.php');

    expect($source)->toContain("add_action('wp', __NAMESPACE__ . '\\\\author_archive_close', 0)")
        ->and($source)->toContain("add_action('template_redirect', __NAMESPACE__ . '\\\\author_archive_close', 1)")
        ->and($source)->toContain('set_404()')
        ->and($source)->toContain('status_header(404)')
        ->and($source)->toContain('nocache_headers()')
        ->and($source)->toContain("define('DONOTCACHEPAGE', true)")
        ->and($source)->toContain("add_filter('author_link'")
        ->and($source)->toContain("home_url('/')")
        ->and($source)->toContain('option_rank-math-options-sitemap')
        ->and($source)->toContain('wp_sitemaps_users_query_args');
});

it('has no role hard-coded besides the author default', function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/modules/author-archives.php');

    expect($source)->not->toContain('team_member')
        ->and($source)->toContain("['author']");
});
