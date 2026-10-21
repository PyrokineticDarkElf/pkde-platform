<?php

/**
 * Plugin Name: Google Tag Manager
 * Description: Outputs the Google Tag Manager container snippets (head script + body noscript). Off
 *              unless GTM_CONTAINER_ID is set in .env AND the environment is production, so local and
 *              staging sites never send hits into the live analytics. The snippets are Google's own,
 *              unmodified apart from the container ID.
 */

namespace App\MuPlugins;

function gtm_container_id(): string
{
    $id = defined('GTM_CONTAINER_ID') ? (string) GTM_CONTAINER_ID : '';

    return preg_match('/^GTM-[A-Z0-9]+$/', $id) && wp_get_environment_type() === 'production' ? $id : '';
}

add_action('wp_head', function () {
    if (! $id = gtm_container_id()) {
        return;
    }

    $id = esc_js($id);
    echo <<<HTML
<!-- Google Tag Manager -->
<script>(function(w,d,s,l,i){w[l]=w[l]||[];w[l].push({'gtm.start':
new Date().getTime(),event:'gtm.js'});var f=d.getElementsByTagName(s)[0],
j=d.createElement(s),dl=l!='dataLayer'?'&l='+l:'';j.async=true;j.src=
'https://www.googletagmanager.com/gtm.js?id='+i+dl;f.parentNode.insertBefore(j,f);
})(window,document,'script','dataLayer','{$id}');</script>
<!-- End Google Tag Manager -->

HTML;
}, 1);

add_action('wp_body_open', function () {
    if (! $id = gtm_container_id()) {
        return;
    }

    $src = esc_url('https://www.googletagmanager.com/ns.html?id=' . $id);
    echo <<<HTML
<!-- Google Tag Manager (noscript) -->
<noscript><iframe src="{$src}"
height="0" width="0" style="display:none;visibility:hidden"></iframe></noscript>
<!-- End Google Tag Manager (noscript) -->

HTML;
}, 1);
