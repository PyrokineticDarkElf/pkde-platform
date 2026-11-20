<?php

/**
 * The faq module (WP-102): the pure parts. The post type, roles, ordering and cache purge are proved in a real WordPress on the template stack.
 */
require_once dirname(__DIR__, 2) . '/modules/faq/faq.php';

use function App\MuPlugins\faq_groups_in_blocks;
use function App\MuPlugins\faq_ordered_ids;
use function App\MuPlugins\faq_post_caps;
use function App\MuPlugins\faq_post_type_args;
use function App\MuPlugins\faq_seo_caps;
use function App\MuPlugins\faq_taxonomy_args;

it('has no URL, archive, search or sitemap, but an admin screen and REST', function () {
    $args = faq_post_type_args();

    expect($args['public'])->toBeFalse()
        ->and($args['publicly_queryable'])->toBeFalse()
        ->and($args['has_archive'])->toBeFalse()
        ->and($args['exclude_from_search'])->toBeTrue()
        ->and($args['rewrite'])->toBeFalse()
        ->and($args['show_ui'])->toBeTrue()
        ->and($args['show_in_rest'])->toBeTrue()
        ->and($args['supports'])->toBe(['title', 'editor', 'revisions', 'page-attributes'])
        ->and($args['map_meta_cap'])->toBeTrue();
});

it('collapses every capability of the post type and the groups to manage_pkde_faqs', function () {
    expect(array_values(array_unique(faq_post_caps())))->toBe(['manage_pkde_faqs'])
        ->and(array_keys(faq_post_caps()))->toContain('edit_posts', 'publish_posts', 'delete_others_posts', 'read_private_posts')
        ->and(array_values(array_unique(faq_taxonomy_args()['capabilities'])))->toBe(['manage_pkde_faqs'])
        ->and(faq_taxonomy_args()['public'])->toBeFalse()
        ->and(faq_taxonomy_args()['show_admin_column'])->toBeTrue()
        ->and(faq_taxonomy_args()['show_in_rest'])->toBeTrue()
        ->and(faq_taxonomy_args()['hierarchical'])->toBeFalse();
});

it('gives the SEO role the Editor capabilities, the FAQ capability and, with Rank Math, its SEO capabilities', function () {
    $editor = ['edit_posts' => true, 'edit_others_posts' => true, 'publish_pages' => true, 'read' => true];

    expect(faq_seo_caps($editor, false))->toBe($editor + ['manage_pkde_faqs' => true])
        ->and(faq_seo_caps($editor, true))->toHaveKeys(['edit_posts', 'manage_pkde_faqs', 'rank_math_general', 'rank_math_titles'])
        ->and($editor)->not->toHaveKey('manage_pkde_faqs'); // an Editor is never given it
});

it('orders a group by its stored order first, then menu order and title, and ignores ids that left the group', function () {
    $members = [
        10 => ['menu_order' => 0, 'title' => 'Zebra'],
        11 => ['menu_order' => 0, 'title' => 'apple'],
        12 => ['menu_order' => 5, 'title' => 'Mango'],
        13 => ['menu_order' => 0, 'title' => 'Banana'],
    ];

    expect(faq_ordered_ids($members, [12, 99, 10, 12]))->toBe([12, 10, 11, 13]) // stored: 12, 10 (99 left, 12 once); the rest by menu order, then title (apple, Banana)
        ->and(faq_ordered_ids($members, []))->toBe([11, 13, 10, 12])
        ->and(faq_ordered_ids([], [1, 2]))->toBe([]);
});

it('finds the groups a page asks for in its FAQ blocks, nested and in synced patterns, once each', function () {
    $blocks = [
        ['blockName' => 'core/group', 'attrs' => [], 'innerBlocks' => [
            ['blockName' => 'sage/faq', 'attrs' => ['groups' => [3, 5]], 'innerBlocks' => []],
            ['blockName' => 'core/block', 'attrs' => ['ref' => 77], 'innerBlocks' => []],
        ]],
        ['blockName' => 'sage/faq', 'attrs' => ['groups' => [5, 8]], 'innerBlocks' => []],
        ['blockName' => 'sage/faq', 'attrs' => [], 'innerBlocks' => []], // hand-typed mode: no group
    ];
    $resolve = fn(int $ref) => $ref === 77 ? [['blockName' => 'sage/faq', 'attrs' => ['groups' => [9]], 'innerBlocks' => []]] : [];

    expect(faq_groups_in_blocks($blocks, $resolve))->toBe([3, 5, 9, 8])
        ->and(faq_groups_in_blocks($blocks))->toBe([3, 5, 8]);
});

it('is listed in the loader and ships its scripts', function () {
    $root = dirname(__DIR__, 2);

    expect(file_get_contents("$root/pkde-platform.php"))->toContain("'faq',")
        ->and(is_file("$root/modules/faq/faq-order.js"))->toBeTrue()
        ->and(is_file("$root/modules/faq/faq-editor.js"))->toBeTrue()
        ->and(file_get_contents("$root/modules/faq/faq.php"))->toContain("'public' => false", "'rewrite' => false", 'wp_sitemaps_post_types', 'manage_pkde_faqs', 'pkde_seo');
});
