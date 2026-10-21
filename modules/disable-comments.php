<?php

/**
 * Plugin Name: Disable Comments
 * Description: Comments/trackbacks/pingbacks are off by default for this template's client sites. The one exception is
 *              WooCommerce product reviews (they are comments): when WooCommerce is active, products keep comments so the
 *              Reviews tab and review form work, governed by WooCommerce's own reviews setting. Everything else stays closed.
 */

namespace App\MuPlugins;

/** Does this post type keep comments? Only products, only while WooCommerce is active (their reviews are comments). */
function keeps_comments(string $postType, bool $wooActive): bool
{
    return $wooActive && $postType === 'product';
}

if (! function_exists('add_filter')) {
    return; // loaded outside WordPress (unit tests): the function above is all there is
}

// Belt-and-suspenders on top of the default_comment_status/default_ping_status
// options (set by bin/setup) — closes comments/pings even if something else
// flips those options later.
add_filter('comments_open', fn($open, $postId) => keeps_comments((string) get_post_type($postId), class_exists('WooCommerce')) ? $open : false, 20, 2);
add_filter('pings_open', '__return_false', 20);

// Remove comment/trackback support from every post type, core and custom (products keep comments for reviews).
add_action('init', function () {
    foreach (get_post_types() as $post_type) {
        if (! keeps_comments($post_type, class_exists('WooCommerce'))) {
            remove_post_type_support($post_type, 'comments');
        }
        remove_post_type_support($post_type, 'trackbacks');
    }
}, 100);

// Hide comments-related admin UI.
add_action('admin_menu', function () {
    remove_menu_page('edit-comments.php');
});

add_action('admin_bar_menu', function ($wp_admin_bar) {
    $wp_admin_bar->remove_node('comments');
}, 999);

add_action('admin_init', function () {
    foreach (get_post_types(['show_ui' => true]) as $post_type) {
        if (keeps_comments($post_type, class_exists('WooCommerce'))) {
            continue;
        }
        remove_meta_box('commentsdiv', $post_type, 'normal');
        remove_meta_box('commentstatusdiv', $post_type, 'normal');
    }
});
