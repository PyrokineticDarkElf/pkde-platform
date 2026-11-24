<?php

/**
 * The legal module (WP-104): the pure parts. The post type, the address, the roles, core and WooCommerce are proved in a real WordPress on the template stack.
 */
require_once dirname(__DIR__, 2) . '/modules/legal/legal.php';

use function App\MuPlugins\legal_caps_without_privacy_gate;
use function App\MuPlugins\legal_dropdown_pages;
use function App\MuPlugins\legal_menu_documents;
use function App\MuPlugins\legal_menu_html;
use function App\MuPlugins\legal_post_caps;
use function App\MuPlugins\legal_post_type_args;
use function App\MuPlugins\legal_resolve_request;
use function App\MuPlugins\legal_should_trash;
use function App\MuPlugins\legal_sanitize_date;
use function App\MuPlugins\legal_sanitize_type;
use function App\MuPlugins\legal_slug_clash;

it('is public and indexable, out of the search, with no archive and no rewrite base of its own', function () {
    $args = legal_post_type_args();

    expect($args['public'])->toBeTrue()
        ->and($args['exclude_from_search'])->toBeTrue()
        ->and($args['has_archive'])->toBeFalse()
        ->and($args['rewrite'])->toBeFalse() // the address is /<slug>/, resolved in legal_resolve_request()
        ->and($args['show_in_rest'])->toBeTrue()
        ->and($args['supports'])->toContain('title', 'editor', 'revisions', 'custom-fields')
        ->and($args['map_meta_cap'])->toBeTrue();
});

it('has its own edit_pkde_legals family of capabilities, one name per primitive', function () {
    $caps = legal_post_caps();

    expect($caps['edit_posts'])->toBe('edit_pkde_legals')
        ->and($caps['publish_posts'])->toBe('publish_pkde_legals')
        ->and($caps['edit_others_posts'])->toBe('edit_others_pkde_legals')
        ->and(array_unique($caps))->toHaveCount(count($caps)); // no two primitives share a name
});

it('looks a missing page or post up as a document, and leaves everything else alone', function () {
    $none = fn() => false;
    $document = fn(string $slug) => $slug === 'privacy-policy';

    // /privacy-policy/ with page permalinks (pagename) and with /%postname%/ (name)
    expect(legal_resolve_request(['pagename' => 'privacy-policy'], $none, $none, $document))->toBe(['post_type' => 'pkde_legal', 'name' => 'privacy-policy', 'pkde_legal' => 'privacy-policy'])
        ->and(legal_resolve_request(['name' => 'privacy-policy'], $none, $none, $document)['post_type'])->toBe('pkde_legal')
        // a Page with that slug wins, and so does a post
        ->and(legal_resolve_request(['pagename' => 'privacy-policy'], fn() => true, $none, $document))->toBeNull()
        ->and(legal_resolve_request(['name' => 'privacy-policy'], $none, fn() => true, $document))->toBeNull()
        // no document: not ours
        ->and(legal_resolve_request(['pagename' => 'about-us'], $none, $none, $document))->toBeNull()
        // a child path, a feed, an attachment, a product, a product category, the shop base and a post type request carry other variables or a path
        ->and(legal_resolve_request(['pagename' => 'about/privacy-policy'], $none, $none, $document))->toBeNull()
        ->and(legal_resolve_request(['pagename' => 'privacy-policy', 'feed' => 'feed'], $none, $none, $document))->toBeNull()
        ->and(legal_resolve_request(['product' => 'privacy-policy', 'post_type' => 'product', 'name' => 'privacy-policy'], $none, $none, $document))->toBeNull()
        ->and(legal_resolve_request(['product_cat' => 'privacy-policy'], $none, $none, $document))->toBeNull()
        ->and(legal_resolve_request(['post_type' => 'product'], $none, $none, $document))->toBeNull()
        ->and(legal_resolve_request([], $none, $none, $document))->toBeNull();

    // paging variables survive
    expect(legal_resolve_request(['pagename' => 'privacy-policy', 'page' => '2'], $none, $none, $document))->toMatchArray(['page' => '2', 'post_type' => 'pkde_legal']);
});

it('says an address clashes when the other kind of content already has it, the Page winning', function () {
    $taken = fn(string $type, string $slug) => [$type, $slug] === ['page', 'terms'] || [$type, $slug] === ['pkde_legal', 'cookies'];

    expect(legal_slug_clash('pkde_legal', 'terms', $taken))->toBeTrue()   // a document where a Page is
        ->and(legal_slug_clash('page', 'cookies', $taken))->toBeTrue()     // a Page where a document is
        ->and(legal_slug_clash('pkde_legal', 'privacy', $taken))->toBeFalse()
        ->and(legal_slug_clash('post', 'terms', $taken))->toBeFalse()
        ->and(legal_slug_clash('pkde_legal', '', $taken))->toBeFalse();
});

it('lists the documents in document-type order and then by title', function () {
    $docs = [
        ['id' => 1, 'title' => 'Returns', 'url' => '/returns/', 'type' => 'returns'],
        ['id' => 2, 'title' => 'Privacy', 'url' => '/privacy/', 'type' => 'privacy'],
        ['id' => 3, 'title' => 'Terms', 'url' => '/terms/', 'type' => 'terms'],
        ['id' => 4, 'title' => 'Cookies', 'url' => '/cookies/', 'type' => 'cookies'],
        ['id' => 5, 'title' => 'Zed', 'url' => '/zed/', 'type' => 'other'],
        ['id' => 6, 'title' => 'Alpha', 'url' => '/alpha/', 'type' => 'other'],
        ['id' => 7, 'title' => 'Unknown type', 'url' => '/u/', 'type' => 'nonsense'],
    ];

    expect(array_column(legal_menu_documents($docs), 'id'))->toBe([2, 4, 3, 1, 6, 5, 7]);
});

it('prints the menu as a plain list with the current document marked, and nothing for no documents', function () {
    foreach (['esc_attr', 'esc_html', 'esc_url', 'untrailingslashit'] as $name) {
        if (! function_exists($name)) {
            eval(match ($name) {
                'untrailingslashit' => 'function untrailingslashit($v) { return rtrim($v, "/"); }',
                default => "function $name(\$v) { return htmlspecialchars((string) \$v); }",
            });
        }
    }

    $docs = [['id' => 1, 'title' => 'Privacy & you', 'url' => 'https://example.test/privacy/', 'type' => 'privacy'], ['id' => 2, 'title' => 'Terms', 'url' => 'https://example.test/terms/', 'type' => 'terms']];
    $html = legal_menu_html($docs, 'https://example.test/terms/');

    expect($html)->toStartWith('<ul id="menu-legal" class="menu menu-legal">')
        ->and($html)->toContain('<a href="https://example.test/privacy/">Privacy &amp; you</a>')
        ->and($html)->toContain('current-menu-item')->and($html)->toContain('aria-current="page"')
        ->and(substr_count($html, 'current-menu-item'))->toBe(1)
        ->and(legal_menu_html([], ''))->toBe('');
});

it('adds the documents to a page dropdown once each', function () {
    $page = fn(int $id) => (object) ['ID' => $id, 'post_title' => "P$id"];

    expect(array_map(fn($p) => $p->ID, legal_dropdown_pages([$page(1), $page(2)], [$page(9), $page(2)])))->toBe([1, 2, 9]);
});

it('keeps dates as Y-m-d only and types from the list', function () {
    expect(legal_sanitize_date('2026-11-20'))->toBe('2026-11-20')
        ->and(legal_sanitize_date('20/11/2026'))->toBe('')
        ->and(legal_sanitize_date('2026-02-30'))->toBe('')
        ->and(legal_sanitize_date(null))->toBe('')
        ->and(legal_sanitize_type('privacy'))->toBe('privacy')
        ->and(legal_sanitize_type('modern-slavery'))->toBe('modern-slavery')
        ->and(legal_sanitize_type('<script>'))->toBe('other')
        ->and(legal_sanitize_type(null))->toBe('other');
});

it('takes core\'s administrator-only gate off the privacy document, so an Editor edits it like the others', function () {
    expect(legal_caps_without_privacy_gate(['edit_pkde_legals', 'edit_published_pkde_legals', 'manage_options']))->toBe(['edit_pkde_legals', 'edit_published_pkde_legals'])
        ->and(legal_caps_without_privacy_gate(['edit_pkde_legals']))->toBe(['edit_pkde_legals']);
});

it('moves a document to the trash on a delete without force, and only then', function () {
    expect(legal_should_trash('pkde_legal', 'draft', false, 30))->toBeTrue()
        ->and(legal_should_trash('pkde_legal', 'publish', false, 30))->toBeTrue()
        ->and(legal_should_trash('pkde_legal', 'draft', true, 30))->toBeFalse()   // an explicit force deletes for good
        ->and(legal_should_trash('pkde_legal', 'trash', false, 30))->toBeFalse()  // already in the trash: this delete empties it
        ->and(legal_should_trash('pkde_legal', 'draft', false, 0))->toBeFalse()   // the site has no trash
        ->and(legal_should_trash('page', 'draft', false, 30))->toBeFalse()        // core handles pages and posts itself
        ->and(file_get_contents(dirname(__DIR__, 2) . '/modules/legal/legal.php'))->toContain("add_filter('pre_delete_post'");
});
