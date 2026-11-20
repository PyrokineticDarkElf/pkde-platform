<?php

/**
 * Plugin Name: FAQs
 * Description: Frequently asked questions as their own, non-public content (WP-102). One question and its answer per FAQ, shown on a page by the kit's FAQ block by GROUP.
 *               - the post type `pkde_faq`: no URL, no archive, no search, no sitemap (public false, rewrite false); the question is the title and the answer is the content,
 *                 written with paragraphs, lists, h3/h4 headings and bold, italic and links only; its own "FAQs" admin menu; revisions;
 *               - the taxonomy `pkde_faq_group`: many groups per FAQ, an admin column and a filter on the FAQs list, a description per group;
 *               - the order is per group: drag the rows of the FAQs list filtered by one group; stored on the group (term meta `_pkde_faq_order`, the ordered FAQ ids), so one FAQ can
 *                 sit in a different place in each of its groups; `menu_order` and then the title are the fallback;
 *               - who: the capability `manage_pkde_faqs` (every capability of the post type and the taxonomy is mapped to it) for Administrators and for the role "SEO" (`pkde_seo`:
 *                 the Editor's capabilities plus `manage_pkde_faqs`, plus Rank Math's SEO capabilities when it is active). Editors do not have it. Installed on the first request and
 *                 again when FAQ_VERSION changes (idempotent);
 *               - the page cache: saving, trashing, deleting, re-grouping or re-ordering a FAQ, and changing a group, purges the pages that show the group. A page records its groups
 *                 (post meta `_pkde_faq_groups`, one row per group id) when it is saved; when a published page with an FAQ block has no record, the whole page cache is flushed instead.
 *               - for the kit's block: `faqs_for_groups()` returns the groups and their FAQs in order.
 *              Switch the module off with PKDE_PLATFORM_DISABLE=faq.
 */

namespace App\MuPlugins;

const FAQ_POST_TYPE = 'pkde_faq';
const FAQ_TAXONOMY = 'pkde_faq_group';
const FAQ_CAP = 'manage_pkde_faqs';
const FAQ_ROLE = 'pkde_seo';
const FAQ_VERSION = '1';
const FAQ_ORDER_META = '_pkde_faq_order';
const FAQ_PAGE_META = '_pkde_faq_groups';
const FAQ_BLOCK = 'sage/faq';

/** What an answer is written with. */
const FAQ_ANSWER_BLOCKS = ['core/paragraph', 'core/list', 'core/list-item', 'core/heading'];

/** Rank Math's own SEO capabilities, given to the SEO role when Rank Math is active. */
const FAQ_RANK_MATH_CAPS = [
    'rank_math_general', 'rank_math_titles', 'rank_math_sitemap', 'rank_math_404_monitor', 'rank_math_link_builder', 'rank_math_redirections',
    'rank_math_site_analysis', 'rank_math_onpage_general', 'rank_math_onpage_advanced', 'rank_math_onpage_snippet', 'rank_math_onpage_social', 'rank_math_content_ai',
];

/** The post type's primitive capabilities: every one is the one capability (the meta capabilities edit_post, read_post and delete_post stay WordPress's own and map to these). */
function faq_post_caps(): array
{
    $caps = [];

    foreach (['edit_posts', 'edit_others_posts', 'delete_posts', 'publish_posts', 'read_private_posts', 'delete_private_posts', 'delete_published_posts',
        'delete_others_posts', 'edit_private_posts', 'edit_published_posts', 'create_posts'] as $name) {
        $caps[$name] = FAQ_CAP;
    }

    return $caps;
}

function faq_post_type_args(): array
{
    return [
        'labels' => [
            'name' => 'FAQs',
            'singular_name' => 'FAQ',
            'add_new_item' => 'Add a FAQ',
            'edit_item' => 'Edit FAQ',
            'new_item' => 'New FAQ',
            'view_item' => 'View FAQ',
            'search_items' => 'Search FAQs',
            'not_found' => 'No FAQs yet.',
            'all_items' => 'All FAQs',
            'menu_name' => 'FAQs',
        ],
        'description' => 'Questions and answers, shown on pages by the FAQ block. Not a page of its own.',
        'public' => false,
        'publicly_queryable' => false,
        'has_archive' => false,
        'exclude_from_search' => true,
        'rewrite' => false,
        'query_var' => false,
        'show_ui' => true,
        'show_in_menu' => true,
        'show_in_nav_menus' => false,
        'show_in_admin_bar' => false,
        'show_in_rest' => true,
        'rest_base' => 'pkde-faqs',
        'menu_position' => 26,
        'menu_icon' => 'dashicons-editor-help',
        'supports' => ['title', 'editor', 'revisions', 'page-attributes'],
        'capability_type' => ['pkde_faq', 'pkde_faqs'],
        'capabilities' => faq_post_caps(),
        'map_meta_cap' => true,
        'can_export' => true,
    ];
}

function faq_taxonomy_args(): array
{
    return [
        'labels' => [
            'name' => 'FAQ groups',
            'singular_name' => 'FAQ group',
            'menu_name' => 'Groups',
            'all_items' => 'All groups',
            'add_new_item' => 'Add a group',
            'edit_item' => 'Edit group',
            'search_items' => 'Search groups',
            'not_found' => 'No groups yet.',
        ],
        'description' => 'A FAQ can be in several groups. The FAQ block shows the FAQs of the groups it is set to.',
        'public' => false,
        'publicly_queryable' => false,
        'rewrite' => false,
        'query_var' => false,
        'hierarchical' => false,
        'show_ui' => true,
        'show_in_menu' => true,
        'show_in_nav_menus' => false,
        'show_tagcloud' => false,
        'show_admin_column' => true,
        'show_in_rest' => true,
        'rest_base' => 'pkde-faq-groups',
        'capabilities' => ['manage_terms' => FAQ_CAP, 'edit_terms' => FAQ_CAP, 'delete_terms' => FAQ_CAP, 'assign_terms' => FAQ_CAP],
    ];
}

/**
 * The FAQ ids of one group in display order: the stored order first (only ids that are still in the group), then the rest by menu_order and title.
 *
 * @param  array<int, array{menu_order?: int, title?: string}>  $members  every FAQ in the group, by id
 * @param  list<int>  $stored  the group's stored order
 * @return list<int>
 */
function faq_ordered_ids(array $members, array $stored): array
{
    $first = [];

    foreach ($stored as $id) {
        $id = (int) $id;

        if (isset($members[$id]) && ! in_array($id, $first, true)) {
            $first[] = $id;
        }
    }

    $rest = array_diff(array_keys($members), $first);
    usort($rest, function ($a, $b) use ($members) {
        return [(int) ($members[$a]['menu_order'] ?? 0), strtolower((string) ($members[$a]['title'] ?? '')), $a] <=> [(int) ($members[$b]['menu_order'] ?? 0), strtolower((string) ($members[$b]['title'] ?? '')), $b];
    });

    return array_merge($first, array_values($rest));
}

/** The group ids a list of parsed blocks asks for (every `sage/faq` block, nested ones too), unique, in order of appearance. @return list<int> */
function faq_groups_in_blocks(array $blocks, ?callable $resolveReusable = null, int $depth = 0): array
{
    $ids = [];

    foreach ($blocks as $block) {
        $name = $block['blockName'] ?? '';

        if ($name === FAQ_BLOCK) {
            foreach ((array) ($block['attrs']['groups'] ?? []) as $id) {
                $ids[] = (int) $id;
            }
        } elseif ($name === 'core/block' && $resolveReusable && $depth < 3 && ! empty($block['attrs']['ref'])) {
            $ids = array_merge($ids, faq_groups_in_blocks($resolveReusable((int) $block['attrs']['ref']), $resolveReusable, $depth + 1));
        }

        if (! empty($block['innerBlocks'])) {
            $ids = array_merge($ids, faq_groups_in_blocks($block['innerBlocks'], $resolveReusable, $depth));
        }
    }

    return array_values(array_unique(array_filter($ids)));
}

/** The capabilities of the SEO role: the Editor's, the FAQ capability, and Rank Math's when it is active. @return array<string, bool> */
function faq_seo_caps(array $editorCaps, bool $rankMath): array
{
    $caps = array_merge($editorCaps, [FAQ_CAP => true]);

    if ($rankMath) {
        foreach (FAQ_RANK_MATH_CAPS as $cap) {
            $caps[$cap] = true;
        }
    }

    return $caps;
}

if (! function_exists('add_action')) {
    return; // outside WordPress (the unit tests load this file for the functions above)
}

// ---------------------------------------------------------------------------------------------------------------------------------------------------------------------
// registration and roles

add_action('init', function () {
    register_post_type(FAQ_POST_TYPE, faq_post_type_args());
    register_taxonomy(FAQ_TAXONOMY, [FAQ_POST_TYPE], faq_taxonomy_args());
    register_term_meta(FAQ_TAXONOMY, FAQ_ORDER_META, ['type' => 'array', 'single' => true, 'show_in_rest' => false, 'default' => []]);
}, 5);

/** Administrator and SEO get the capability; created or completed, never duplicated. */
function faq_install(): void
{
    if ($admin = get_role('administrator')) {
        $admin->add_cap(FAQ_CAP);
    }

    $editor = get_role('editor');
    $caps = faq_seo_caps($editor ? $editor->capabilities : ['read' => true], defined('RANK_MATH_VERSION') || class_exists('RankMath\\Helper'));
    $seo = get_role(FAQ_ROLE);

    if (! $seo) {
        add_role(FAQ_ROLE, 'SEO', $caps);
    } else {
        foreach ($caps as $cap => $grant) {
            if (! isset($seo->capabilities[$cap])) {
                $seo->add_cap($cap, (bool) $grant);
            }
        }
    }
}

add_action('init', function () {
    if (get_option('pkde_faq_installed') !== FAQ_VERSION) {
        faq_install();
        update_option('pkde_faq_installed', FAQ_VERSION);
    }
}, 6);

// Core's sitemaps never list a post type that is not public; this makes it explicit for any plugin that builds the list from the registry.
add_filter('wp_sitemaps_post_types', function ($types) {
    unset($types[FAQ_POST_TYPE]);

    return $types;
});

// the answer editor: the FAQ post type is written with a few blocks only, whoever the user is (after the editor-settings module's list, priority 20)
add_filter('allowed_block_types_all', function ($allowed, $context) {
    if (is_object($context) && ($context->post->post_type ?? '') === FAQ_POST_TYPE) {
        return FAQ_ANSWER_BLOCKS;
    }

    return $allowed;
}, 30, 2);

add_filter('enter_title_here', fn($text, $post) => ($post->post_type ?? '') === FAQ_POST_TYPE ? 'The question' : $text, 10, 2);

add_action('enqueue_block_editor_assets', function () {
    $file = __DIR__ . '/faq-editor.js';

    if (get_post_type() !== FAQ_POST_TYPE || ! is_file($file)) {
        return;
    }

    wp_register_script('pkde-faq-editor', false, ['wp-rich-text', 'wp-data', 'wp-format-library', 'wp-hooks', 'wp-compose', 'wp-element', 'wp-block-editor', 'wp-dom-ready'], null, true);
    wp_enqueue_script('pkde-faq-editor');
    wp_add_inline_script('pkde-faq-editor', (string) file_get_contents($file));
});

// ---------------------------------------------------------------------------------------------------------------------------------------------------------------------
// the groups of a FAQ and their order

/** @return array<int, array{menu_order: int, title: string}> every FAQ of the group (any status), by id */
function faq_group_members(int $termId, string $status = 'any'): array
{
    $posts = get_posts([
        'post_type' => FAQ_POST_TYPE, 'post_status' => $status, 'numberposts' => -1, 'suppress_filters' => true, 'no_found_rows' => true,
        'tax_query' => [['taxonomy' => FAQ_TAXONOMY, 'field' => 'term_id', 'terms' => [$termId]]],
    ]);
    $members = [];

    foreach ($posts as $post) {
        $members[(int) $post->ID] = ['menu_order' => (int) $post->menu_order, 'title' => (string) $post->post_title];
    }

    return $members;
}

/** @return list<int> the FAQ ids of the group in display order ('az': by title) */
function faq_group_ids(int $termId, string $order = 'manual', string $status = 'any'): array
{
    $members = faq_group_members($termId, $status);

    if ($order === 'az') {
        uasort($members, fn($a, $b) => strcasecmp($a['title'], $b['title']));

        return array_keys($members);
    }

    return faq_ordered_ids($members, (array) get_term_meta($termId, FAQ_ORDER_META, true));
}

/**
 * The groups and their published FAQs, for the FAQ block. A FAQ that is in two of the groups is shown once, in the first.
 *
 * @param  list<int>  $groupIds  in display order
 * @return list<array{group: \WP_Term, faqs: list<\WP_Post>}>
 */
function faqs_for_groups(array $groupIds, string $order = 'manual', int $limit = 0): array
{
    $out = [];
    $seen = [];
    $left = $limit > 0 ? $limit : PHP_INT_MAX;

    foreach ($groupIds as $groupId) {
        $term = get_term((int) $groupId, FAQ_TAXONOMY);

        if (! $term || is_wp_error($term) || $left < 1) {
            continue;
        }

        $faqs = [];

        foreach (faq_group_ids((int) $term->term_id, $order, 'publish') as $id) {
            if ($left < 1) {
                break;
            }

            if (isset($seen[$id]) || ! ($post = get_post($id))) {
                continue;
            }

            $seen[$id] = true;
            $faqs[] = $post;
            $left--;
        }

        $out[] = ['group' => $term, 'faqs' => $faqs];
    }

    return $out;
}

// a FAQ that joins a group goes to the end of that group's order; one that leaves it is dropped from the stored order
add_action('set_object_terms', function ($objectId, $terms, $ttIds, $taxonomy, $append, $oldTtIds) {
    if ($taxonomy !== FAQ_TAXONOMY || get_post_type($objectId) !== FAQ_POST_TYPE) {
        return;
    }

    $added = array_diff((array) $ttIds, (array) $oldTtIds);
    $removed = array_diff((array) $oldTtIds, (array) $ttIds);

    foreach (array_merge($added, $removed) as $ttId) {
        $term = get_term_by('term_taxonomy_id', (int) $ttId, FAQ_TAXONOMY);

        if (! $term) {
            continue;
        }

        $order = array_values(array_filter(array_map('intval', (array) get_term_meta($term->term_id, FAQ_ORDER_META, true)), fn($id) => $id !== (int) $objectId));

        if (in_array($ttId, $added, true)) {
            $order[] = (int) $objectId;
        }

        update_term_meta($term->term_id, FAQ_ORDER_META, $order);
        faq_queue_purge([(int) $term->term_id]);
    }
}, 10, 6);

// ---------------------------------------------------------------------------------------------------------------------------------------------------------------------
// the FAQs list: a group filter, and drag order inside one group

$faqScreenGroup = function (): int {
    return isset($_GET['faq_group']) ? max(0, (int) $_GET['faq_group']) : 0; // phpcs:ignore WordPress.Security.NonceVerification
};

add_action('restrict_manage_posts', function ($postType) use ($faqScreenGroup) {
    if ($postType !== FAQ_POST_TYPE) {
        return;
    }

    wp_dropdown_categories([
        'taxonomy' => FAQ_TAXONOMY, 'name' => 'faq_group', 'id' => 'faq_group', 'show_option_all' => 'All groups', 'hide_empty' => false, 'hierarchical' => false, 'orderby' => 'name',
        'selected' => $faqScreenGroup(), 'value_field' => 'term_id', 'show_count' => true,
    ]);
});

add_action('pre_get_posts', function ($query) use ($faqScreenGroup) {
    if (! is_admin() || ! $query->is_main_query() || $query->get('post_type') !== FAQ_POST_TYPE || ! ($group = $faqScreenGroup())) {
        return;
    }

    $ids = faq_group_ids($group);
    $query->set('post__in', $ids ?: [0]);
    $query->set('orderby', 'post__in');
    $query->set('posts_per_page', -1); // the whole group on one page, so the rows can be dragged
});

add_filter('manage_' . FAQ_POST_TYPE . '_posts_columns', function ($columns) use ($faqScreenGroup) {
    if (! $faqScreenGroup()) {
        return $columns;
    }

    // the drag handle right after the checkbox
    $checkbox = array_slice($columns, 0, 1, true);

    return $checkbox + ['pkde_order' => ''] + array_slice($columns, 1, null, true);
});

add_action('manage_' . FAQ_POST_TYPE . '_posts_custom_column', function ($column) {
    if ($column === 'pkde_order') {
        echo '<span class="pkde-faq-handle dashicons dashicons-menu" title="Drag to re-order" style="cursor:move"></span>';
    }
});

add_action('admin_notices', function () use ($faqScreenGroup) {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;

    if ($screen && $screen->id === 'edit-' . FAQ_POST_TYPE && ! $faqScreenGroup()) {
        echo '<div class="notice notice-info"><p>To change the order, choose a group in the filter above: the order is kept for each group, so one FAQ can sit in a different place in each of its groups.</p></div>';
    }
});

add_action('admin_enqueue_scripts', function () use ($faqScreenGroup) {
    $screen = function_exists('get_current_screen') ? get_current_screen() : null;
    $file = __DIR__ . '/faq-order.js';

    if (! $screen || $screen->id !== 'edit-' . FAQ_POST_TYPE || ! ($group = $faqScreenGroup()) || ! is_file($file)) {
        return;
    }

    wp_register_script('pkde-faq-order', false, ['jquery', 'jquery-ui-sortable'], null, true);
    wp_enqueue_script('pkde-faq-order');
    wp_add_inline_script('pkde-faq-order', 'window.pkdeFaqOrder = ' . wp_json_encode(['nonce' => wp_create_nonce('pkde_faq_reorder'), 'group' => $group]) . ';' . (string) file_get_contents($file));
});

add_action('wp_ajax_pkde_faq_reorder', function () {
    check_ajax_referer('pkde_faq_reorder', 'nonce');

    if (! current_user_can(FAQ_CAP)) {
        wp_send_json_error('forbidden', 403);
    }

    $group = (int) ($_POST['group'] ?? 0);
    $term = $group ? get_term($group, FAQ_TAXONOMY) : null;

    if (! $term || is_wp_error($term)) {
        wp_send_json_error('no such group', 404);
    }

    $members = faq_group_members($group);
    $ids = array_values(array_filter(array_map('intval', (array) ($_POST['ids'] ?? [])), fn($id) => isset($members[$id])));

    update_term_meta($group, FAQ_ORDER_META, $ids);
    faq_queue_purge([$group]);
    wp_send_json_success(['group' => $group, 'count' => count($ids)]);
});

// ---------------------------------------------------------------------------------------------------------------------------------------------------------------------
// the page cache

/** Each page that holds an FAQ block records the groups it shows, so a change to a group purges only those pages. */
add_action('save_post', function ($postId, $post) {
    if (wp_is_post_revision($postId) || wp_is_post_autosave($postId) || $post->post_type === FAQ_POST_TYPE) {
        return;
    }

    $resolve = fn(int $ref) => ($p = get_post($ref)) ? parse_blocks($p->post_content) : [];
    $hasBlock = has_block(FAQ_BLOCK, $post) || str_contains($post->post_content, '<!-- wp:block ');
    $groups = $hasBlock ? faq_groups_in_blocks(parse_blocks($post->post_content), $resolve) : [];
    // 0 = "looked, and this page shows no group" (an FAQ block typed by hand, or a synced pattern without one): the page is recorded, so it never counts as unknown
    $record = $hasBlock && ! $groups ? [0] : $groups;
    $before = array_map('intval', get_post_meta($postId, FAQ_PAGE_META));

    if ($record === $before) {
        return;
    }

    delete_post_meta($postId, FAQ_PAGE_META);

    foreach ($record as $group) {
        add_post_meta($postId, FAQ_PAGE_META, $group);
    }
}, 20, 2);

/** @var list<int> $faqPurgeQueue */
$GLOBALS['pkde_faq_purge_queue'] = [];

/** Groups changed in this request; the pages that show them are purged once, at the end. */
function faq_queue_purge(array $groupIds): void
{
    $GLOBALS['pkde_faq_purge_queue'] = array_values(array_unique(array_merge($GLOBALS['pkde_faq_purge_queue'], array_map('intval', $groupIds))));
}

/** The published posts that show a group (their record), and whether some page with an FAQ block has no record (then the whole cache is flushed). @return array{ids: list<int>, unknown: bool} */
function faq_pages_for_groups(array $groupIds): array
{
    global $wpdb;

    $ids = [];

    foreach ($groupIds as $group) {
        $ids = array_merge($ids, array_map('intval', get_posts(['post_type' => 'any', 'post_status' => 'publish', 'numberposts' => -1, 'fields' => 'ids', 'suppress_filters' => true, 'no_found_rows' => true,
            'meta_key' => FAQ_PAGE_META, 'meta_value' => (int) $group])));
    }

    // a page counts as unknown when it holds an FAQ block (or a synced pattern that does) and has never been saved since this module recorded groups
    $synced = array_map('intval', (array) $wpdb->get_col($wpdb->prepare("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'wp_block' AND post_content LIKE %s", '%<!-- wp:sage/faq%')));
    $likes = ['p.post_content LIKE %s'];
    $args = ['%<!-- wp:sage/faq%'];

    foreach ($synced as $ref) {
        $likes[] = 'p.post_content LIKE %s';
        $args[] = '%"ref":' . $ref . '%';
    }

    $unrecorded = (int) $wpdb->get_var($wpdb->prepare(
        "SELECT COUNT(*) FROM {$wpdb->posts} p WHERE p.post_status = 'publish' AND p.post_type <> %s AND (" . implode(' OR ', $likes) . ")
         AND NOT EXISTS (SELECT 1 FROM {$wpdb->postmeta} m WHERE m.post_id = p.ID AND m.meta_key = %s)",
        [FAQ_POST_TYPE, ...$args, FAQ_PAGE_META],
    ));

    return ['ids' => array_values(array_unique($ids)), 'unknown' => $unrecorded > 0];
}

function faq_purge(array $groupIds): void
{
    $pages = faq_pages_for_groups($groupIds);

    // a synced pattern that changes is shown on pages whose own record is the old one: flush everything for it too
    $syncedChanged = $pages['ids'] && array_filter($pages['ids'], fn($id) => get_post_type($id) === 'wp_block');

    if ($pages['unknown'] || $syncedChanged) {
        // some page with an FAQ block has never recorded its groups: flush everything rather than serve a stale answer
        if (function_exists('w3tc_flush_all')) {
            w3tc_flush_all();
        }
        do_action('pkde_platform/faq_purge', [], true);

        return;
    }

    foreach ($pages['ids'] as $id) {
        clean_post_cache($id);

        if (function_exists('w3tc_flush_post')) {
            w3tc_flush_post($id);
        }
    }

    do_action('pkde_platform/faq_purge', $pages['ids'], false);
}

add_action('shutdown', function () {
    if ($GLOBALS['pkde_faq_purge_queue']) {
        $groups = $GLOBALS['pkde_faq_purge_queue'];
        $GLOBALS['pkde_faq_purge_queue'] = [];
        faq_purge($groups);
    }
});

/** The groups a FAQ is in right now. @return list<int> */
function faq_post_groups(int $postId): array
{
    $ids = wp_get_object_terms($postId, FAQ_TAXONOMY, ['fields' => 'ids']);

    return is_wp_error($ids) ? [] : array_map('intval', $ids);
}

add_action('save_post_' . FAQ_POST_TYPE, fn($postId) => faq_queue_purge(faq_post_groups((int) $postId)));
add_action('before_delete_post', function ($postId) {
    if (get_post_type($postId) === FAQ_POST_TYPE) {
        faq_queue_purge(faq_post_groups((int) $postId));
    }
});
add_action('wp_trash_post', function ($postId) {
    if (get_post_type($postId) === FAQ_POST_TYPE) {
        faq_queue_purge(faq_post_groups((int) $postId));
    }
});
add_action('untrashed_post', function ($postId) {
    if (get_post_type($postId) === FAQ_POST_TYPE) {
        faq_queue_purge(faq_post_groups((int) $postId));
    }
});
foreach (['created_' . FAQ_TAXONOMY, 'edited_' . FAQ_TAXONOMY, 'delete_' . FAQ_TAXONOMY] as $hook) {
    add_action($hook, fn($termId) => faq_queue_purge([(int) $termId]));
}
