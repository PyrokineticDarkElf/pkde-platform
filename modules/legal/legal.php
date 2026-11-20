<?php

/**
 * Plugin Name: Legal documents
 * Description: Privacy, cookies, terms and the like as their own content, not as pages (WP-104). One document per policy, at the root address it has today.
 *               - the post type `pkde_legal`: public, indexable, in the sitemaps (core's, and Rank Math's once its switch is turned on for the type), out of the site search, title and editor and revisions, its own "Legal" admin menu, no archive;
 *               - the URL is `/<slug>/`, with no base: a PAGE with that slug always wins (a document that would clash is kept as a draft, with a notice, and the other way round), and
 *                 posts, products, product categories and the shop keep their own addresses (only a request that would otherwise be a missing page is looked up as a document);
 *               - the fields `_pkde_legal_effective`, `_pkde_legal_reviewed` (dates), `_pkde_legal_version` and `_pkde_legal_type` (privacy, cookies, terms, returns, delivery,
 *                 accessibility, modern-slavery, other), registered meta shown in REST, edited in a panel of the document sidebar;
 *               - who: Administrators and Editors (the SEO role has the Editor's capabilities, so it too), through the `edit_pkde_legals` family of capabilities;
 *               - the block set: headings, paragraphs, lists, tables, quotes, details and separators; no Sections, colours or marketing blocks;
 *               - core and WooCommerce: the Privacy Policy page and the WooCommerce Terms page may be a document (their dropdowns list the documents, the links resolve);
 *               - a registered menu location `legal` ("Legal"), filled automatically from the published documents in document-type order, unless a site assigns a real menu to it.
 *              The kit prints a document (`single-pkde_legal`); `bin/deploy/legal-migrate.php` (pkde-deploy) converts a site's legal Pages into documents, keeping their IDs.
 *              Switch the module off with PKDE_PLATFORM_DISABLE=legal.
 */

namespace App\MuPlugins;

const LEGAL_POST_TYPE = 'pkde_legal';
const LEGAL_VERSION = '1';
const LEGAL_META_EFFECTIVE = '_pkde_legal_effective';
const LEGAL_META_REVIEWED = '_pkde_legal_reviewed';
const LEGAL_META_VERSION = '_pkde_legal_version';
const LEGAL_META_TYPE = '_pkde_legal_type';
const LEGAL_MENU_LOCATION = 'legal';

/** The document types in the order the Legal menu lists them (key => label). */
const LEGAL_TYPES = [
    'privacy' => 'Privacy policy',
    'cookies' => 'Cookie policy',
    'terms' => 'Terms and conditions',
    'returns' => 'Returns and refunds',
    'delivery' => 'Delivery',
    'accessibility' => 'Accessibility',
    'modern-slavery' => 'Modern slavery statement',
    'other' => 'Other',
];

/** What a document is written with (existing content with another block keeps rendering). */
const LEGAL_BLOCKS = ['core/heading', 'core/paragraph', 'core/list', 'core/list-item', 'core/table', 'core/quote', 'core/details', 'core/separator', 'core/missing'];

/** The capability names of the post type, by the primitive each stands for. */
function legal_post_caps(): array
{
    return [
        'edit_posts' => 'edit_pkde_legals',
        'edit_others_posts' => 'edit_others_pkde_legals',
        'delete_posts' => 'delete_pkde_legals',
        'publish_posts' => 'publish_pkde_legals',
        'read_private_posts' => 'read_private_pkde_legals',
        'delete_private_posts' => 'delete_private_pkde_legals',
        'delete_published_posts' => 'delete_published_pkde_legals',
        'delete_others_posts' => 'delete_others_pkde_legals',
        'edit_private_posts' => 'edit_private_pkde_legals',
        'edit_published_posts' => 'edit_published_pkde_legals',
        'create_posts' => 'create_pkde_legals',
    ];
}

function legal_post_type_args(): array
{
    return [
        'labels' => [
            'name' => 'Legal',
            'singular_name' => 'Legal document',
            'menu_name' => 'Legal',
            'add_new' => 'Add document',
            'add_new_item' => 'Add a legal document',
            'edit_item' => 'Edit legal document',
            'new_item' => 'New legal document',
            'view_item' => 'View document',
            'search_items' => 'Search documents',
            'not_found' => 'No legal documents yet.',
            'all_items' => 'All documents',
        ],
        'public' => true,
        'publicly_queryable' => true,
        'exclude_from_search' => true,
        'has_archive' => false,
        'rewrite' => false, // the URL is /<slug>/, resolved in legal_resolve_request(); no base and no archive
        'show_in_rest' => true,
        'show_in_nav_menus' => true,
        'menu_icon' => 'dashicons-media-text',
        'menu_position' => 58,
        'capability_type' => ['pkde_legal', 'pkde_legals'],
        'capabilities' => legal_post_caps(),
        'map_meta_cap' => true,
        'supports' => ['title', 'editor', 'revisions', 'custom-fields'],
    ];
}

/** A date as `Y-m-d`, or '' when it is not one. */
function legal_sanitize_date(mixed $value): string
{
    $value = is_string($value) ? trim($value) : '';
    $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

    return $date !== false && $date->format('Y-m-d') === $value ? $value : '';
}

function legal_sanitize_type(mixed $value): string
{
    return is_string($value) && isset(LEGAL_TYPES[$value]) ? $value : 'other';
}

/**
 * The query variables for a request that WordPress resolved as a page (`pagename`) or, with /%postname%/ permalinks, as a post (`name`), when no page or post has that
 * address and a published document does. A Page or a post always wins; products, categories and the shop base never get here (they carry other query variables).
 *
 * @param  array<string, mixed>  $vars  the request's query variables
 * @param  callable(string): bool  $pageExists  a page (any status the visitor may see) at that path
 * @param  callable(string): bool  $postExists  a post at that name
 * @param  callable(string): bool  $documentExists  a published document with that slug
 * @return ?array<string, mixed> the variables to use instead, or null to leave the request alone
 */
function legal_resolve_request(array $vars, callable $pageExists, callable $postExists, callable $documentExists): ?array
{
    $slug = (string) ($vars['pagename'] ?? $vars['name'] ?? '');
    $others = array_diff_key($vars, ['pagename' => 1, 'name' => 1, 'page' => 1, 'paged' => 1, 'preview' => 1, 'preview_id' => 1, 'preview_nonce' => 1, '_thumbnail_id' => 1]);

    // one segment only (no child path, no feed, no attachment, no other query variable), and nothing else answers to it
    if ($slug === '' || str_contains($slug, '/') || $others !== [] || $pageExists($slug) || $postExists($slug) || ! $documentExists($slug)) {
        return null;
    }

    return ['post_type' => LEGAL_POST_TYPE, 'name' => $slug, LEGAL_POST_TYPE => $slug] + array_diff_key($vars, ['pagename' => 1, 'name' => 1]);
}

/**
 * Whether a slug is taken by the other kind of content, which wins: a Page for a document, a document for a Page.
 *
 * @param  callable(string, string): bool  $exists  (post type, slug) a published item of that type has that slug
 */
function legal_slug_clash(string $postType, string $slug, callable $exists): bool
{
    if ($slug === '') {
        return false;
    }

    return match ($postType) {
        LEGAL_POST_TYPE => $exists('page', $slug),
        'page' => $exists(LEGAL_POST_TYPE, $slug),
        default => false,
    };
}

/**
 * The documents for the Legal menu, in document-type order and then by title.
 *
 * @param  list<array{id: int, title: string, url: string, type: string}>  $documents
 * @return list<array{id: int, title: string, url: string, type: string}>
 */
function legal_menu_documents(array $documents): array
{
    $order = array_flip(array_keys(LEGAL_TYPES));
    usort($documents, fn($a, $b) => [$order[$a['type']] ?? 99, strcasecmp($a['title'], $b['title'])] <=> [$order[$b['type']] ?? 99, 0]);

    return $documents;
}

/** The menu markup (a plain list, the classes WordPress gives a menu) for documents from legal_menu_documents(); '' when there are none. */
function legal_menu_html(array $documents, string $currentUrl = ''): string
{
    if ($documents === []) {
        return '';
    }

    $items = array_map(function ($d) use ($currentUrl) {
        $current = $currentUrl !== '' && untrailingslashit($currentUrl) === untrailingslashit($d['url']);

        return sprintf(
            '<li class="menu-item menu-item-legal menu-item-legal-%s%s"><a href="%s"%s>%s</a></li>',
            esc_attr($d['type']),
            $current ? ' current-menu-item' : '',
            esc_url($d['url']),
            $current ? ' aria-current="page"' : '',
            esc_html($d['title']),
        );
    }, $documents);

    return '<ul id="menu-legal" class="menu menu-legal">' . implode('', $items) . '</ul>';
}

/**
 * The page list of a "choose a page" dropdown with the documents added (the Privacy Policy page and the WooCommerce Terms page are chosen from one).
 *
 * @param  array<int, \WP_Post>  $pages
 * @param  array<int, \WP_Post>  $documents
 * @return array<int, \WP_Post>
 */
function legal_dropdown_pages(array $pages, array $documents): array
{
    $have = array_map(fn($page) => (int) $page->ID, $pages);

    foreach ($documents as $document) {
        if (! in_array((int) $document->ID, $have, true)) {
            $pages[] = $document;
        }
    }

    return $pages;
}

/**
 * Core asks for `manage_privacy_options` (an Administrator) to edit or delete the page chosen as the Privacy Policy page. A privacy DOCUMENT is edited by Editors like every
 * other document (the owner's decision), so that extra requirement is taken away for it; the capabilities of the post type stay.
 *
 * @param  list<string>  $caps  the primitive capabilities map_meta_cap() produced
 * @return list<string>
 */
function legal_caps_without_privacy_gate(array $caps): array
{
    return array_values(array_diff($caps, ['manage_options', 'manage_privacy_options']));
}

if (! function_exists('add_action')) {
    return; // outside WordPress (the unit tests load this file for the functions above)
}

// ---------------------------------------------------------------------------------------------------------------------------------------------------------------------
// registration, roles, fields

add_action('init', function () {
    register_post_type(LEGAL_POST_TYPE, legal_post_type_args());

    $auth = fn() => current_user_can('edit_pkde_legals');
    $meta = fn(string $sanitize) => ['type' => 'string', 'single' => true, 'show_in_rest' => true, 'default' => '', 'sanitize_callback' => $sanitize, 'auth_callback' => $auth];
    register_post_meta(LEGAL_POST_TYPE, LEGAL_META_EFFECTIVE, $meta(__NAMESPACE__ . '\\legal_sanitize_date'));
    register_post_meta(LEGAL_POST_TYPE, LEGAL_META_REVIEWED, $meta(__NAMESPACE__ . '\\legal_sanitize_date'));
    register_post_meta(LEGAL_POST_TYPE, LEGAL_META_VERSION, $meta('sanitize_text_field'));
    $type = $meta(__NAMESPACE__ . '\\legal_sanitize_type');
    $type['default'] = 'other';
    register_post_meta(LEGAL_POST_TYPE, LEGAL_META_TYPE, $type);

    register_nav_menus([LEGAL_MENU_LOCATION => 'Legal']);
}, 5);

/** Administrators and Editors (and the SEO role, which has the Editor's capabilities) get the capabilities; given again, never duplicated. */
function legal_install(): void
{
    foreach (['administrator', 'editor', 'pkde_seo'] as $name) {
        if ($role = get_role($name)) {
            foreach (legal_post_caps() as $cap) {
                $role->add_cap($cap);
            }
        }
    }
}

add_action('init', function () {
    if (get_option('pkde_legal_installed') !== LEGAL_VERSION) {
        legal_install();
        update_option('pkde_legal_installed', LEGAL_VERSION);
    }
}, 6);

// Rank Math lists a post type in its sitemap only when its own switch (rank-math-options-sitemap: pt_<type>_sitemap) is on, and a post type registered after Rank Math was set up has none.
// Switched on once, when Rank Math is active; an Administrator who turns it off later is not overruled.
add_action('init', function () {
    if (get_option('pkde_legal_rank_math_sitemap') === '1' || ! (defined('RANK_MATH_VERSION') || class_exists('RankMath\\Helper'))) {
        return;
    }

    $options = get_option('rank-math-options-sitemap', []);

    if (is_array($options) && ! isset($options['pt_' . LEGAL_POST_TYPE . '_sitemap'])) {
        $options['pt_' . LEGAL_POST_TYPE . '_sitemap'] = 'on';
        update_option('rank-math-options-sitemap', $options);
    }

    if (class_exists('RankMath\\Sitemap\\Cache')) {
        \RankMath\Sitemap\Cache::invalidate_storage(); // its sitemap index is cached
    }

    update_option('pkde_legal_rank_math_sitemap', '1');
}, 20);

// the editor: a restricted block set (after the editor-settings module's list, priority 20)
add_filter('allowed_block_types_all', function ($allowed, $context) {
    if (is_object($context) && ($context->post->post_type ?? '') === LEGAL_POST_TYPE) {
        return LEGAL_BLOCKS;
    }

    return $allowed;
}, 30, 2);

add_filter('map_meta_cap', function ($caps, $cap, $userId, $args) {
    if (! in_array($cap, ['edit_post', 'delete_post'], true) || empty($args[0])) {
        return $caps;
    }

    $post = get_post((int) $args[0]);

    if ($post && $post->post_type === LEGAL_POST_TYPE && (int) get_option('wp_page_for_privacy_policy') === (int) $post->ID) {
        return legal_caps_without_privacy_gate((array) $caps);
    }

    return $caps;
}, 20, 4);

add_filter('enter_title_here', fn($text, $post) => ($post->post_type ?? '') === LEGAL_POST_TYPE ? 'Document title, such as Privacy policy' : $text, 10, 2);

add_action('enqueue_block_editor_assets', function () {
    $file = __DIR__ . '/legal-editor.js';

    if (get_post_type() !== LEGAL_POST_TYPE || ! is_file($file)) {
        return;
    }

    wp_register_script('pkde-legal-editor', false, ['wp-plugins', 'wp-editor', 'wp-edit-post', 'wp-components', 'wp-data', 'wp-core-data', 'wp-element', 'wp-i18n'], null, true);
    wp_enqueue_script('pkde-legal-editor');
    wp_add_inline_script('pkde-legal-editor', 'window.pkdeLegalTypes = ' . wp_json_encode(LEGAL_TYPES) . ';' . (string) file_get_contents($file));
});

// ---------------------------------------------------------------------------------------------------------------------------------------------------------------------
// the address: /<slug>/, no base, no archive

/** The document's own address, `/<slug>/`; a draft or pending document keeps WordPress's preview link. */
add_filter('post_type_link', function ($link, $post) {
    if (($post->post_type ?? '') !== LEGAL_POST_TYPE || ! in_array($post->post_status, ['publish', 'private'], true) || ($post->post_name ?? '') === '') {
        return $link;
    }

    return home_url(user_trailingslashit($post->post_name));
}, 10, 2);

/** A request WordPress took for a page or a post that does not exist is looked up as a document: pages, posts and everything else keep winning. */
add_filter('request', function ($vars) {
    $slug = (string) ($vars['pagename'] ?? $vars['name'] ?? '');

    if ($slug === '' || is_admin()) {
        return $vars;
    }

    $document = function (string $slug): bool {
        $found = get_posts(['name' => $slug, 'post_type' => LEGAL_POST_TYPE, 'post_status' => 'publish', 'numberposts' => 1, 'fields' => 'ids', 'suppress_filters' => true]);

        return $found !== [];
    };
    $post = function (string $slug): bool {
        return get_posts(['name' => $slug, 'post_type' => 'post', 'post_status' => ['publish', 'private'], 'numberposts' => 1, 'fields' => 'ids', 'suppress_filters' => true]) !== [];
    };

    return legal_resolve_request($vars, function (string $path): bool {
        $page = get_page_by_path($path, OBJECT, 'page'); // any status: only a page a visitor could see counts (a draft one does not)

        return $page !== null && in_array($page->post_status, ['publish', 'private'], true);
    }, $post, $document) ?? $vars;
});

// A Page and a document may not share an address: the one being published is kept as a draft, with a notice, and the Page wins either way.
add_filter('wp_insert_post_data', function ($data, $postarr) {
    $type = (string) ($data['post_type'] ?? '');

    if (! in_array($type, [LEGAL_POST_TYPE, 'page'], true) || ! in_array($data['post_status'] ?? '', ['publish', 'future', 'private'], true)) {
        return $data;
    }

    $slug = (string) ($data['post_name'] ?? '');
    $self = (int) ($postarr['ID'] ?? 0);
    $exists = function (string $otherType, string $slug) use ($self): bool {
        $found = get_posts(['name' => $slug, 'post_type' => $otherType, 'post_status' => ['publish', 'future', 'private'], 'numberposts' => 1, 'fields' => 'ids', 'suppress_filters' => true, 'post__not_in' => [$self]]);

        return $found !== [];
    };

    if (legal_slug_clash($type, $slug, $exists)) {
        $data['post_status'] = 'draft';
        set_transient('pkde_legal_clash_' . get_current_user_id(), $slug, 300);
    }

    return $data;
}, 10, 2);

add_action('admin_notices', function () {
    $key = 'pkde_legal_clash_' . get_current_user_id();
    $slug = get_transient($key);

    if (! is_string($slug) || $slug === '') {
        return;
    }

    delete_transient($key);
    printf('<div class="notice notice-error"><p>%s</p></div>', esc_html("The address /$slug/ is already used by another page or legal document, so this was saved as a draft. Change the permalink (the slug) and publish again."));
});

// ---------------------------------------------------------------------------------------------------------------------------------------------------------------------
// core and WooCommerce: the Privacy Policy page and the Terms page may be a document

add_filter('get_pages', function ($pages, $args) {
    global $pagenow;

    if (! is_admin() || ($args['post_type'] ?? 'page') !== 'page' || ! in_array($pagenow ?? '', ['options-privacy.php', 'admin.php'], true)) {
        return $pages;
    }

    if ($pagenow === 'admin.php' && ($_GET['page'] ?? '') !== 'wc-settings') { // phpcs:ignore WordPress.Security.NonceVerification
        return $pages;
    }

    $documents = get_posts(['post_type' => LEGAL_POST_TYPE, 'post_status' => ['publish', 'private'], 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC', 'suppress_filters' => true]);

    return legal_dropdown_pages((array) $pages, $documents);
}, 10, 2);

// ---------------------------------------------------------------------------------------------------------------------------------------------------------------------
// the Legal menu

/** The published documents as menu rows. */
function legal_documents(): array
{
    $posts = get_posts(['post_type' => LEGAL_POST_TYPE, 'post_status' => 'publish', 'numberposts' => -1, 'suppress_filters' => true]);

    return array_map(fn($post) => [
        'id' => (int) $post->ID,
        'title' => get_the_title($post),
        'url' => get_permalink($post),
        'type' => legal_sanitize_type(get_post_meta($post->ID, LEGAL_META_TYPE, true)),
    ], $posts);
}

// A real menu assigned to the location wins; otherwise the location lists the published documents.
add_filter('pre_wp_nav_menu', function ($output, $args) {
    if (($args->theme_location ?? '') !== LEGAL_MENU_LOCATION || has_nav_menu(LEGAL_MENU_LOCATION)) {
        return $output;
    }

    $current = isset($_SERVER['REQUEST_URI']) ? home_url(strtok((string) $_SERVER['REQUEST_URI'], '?')) : '';
    $html = legal_menu_html(legal_menu_documents(legal_documents()), $current);

    return $html === '' ? '' : $html;
}, 10, 2);
