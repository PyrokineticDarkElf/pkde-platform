<?php

require_once dirname(__DIR__, 2) . '/modules/disable-emoji.php';

use function App\MuPlugins\emoji_hooks;

it('removes the emoji styles hook of WordPress 6.4 and later as well as the older ones', function () {
    $hooks = array_map(fn($h) => implode(':', $h), emoji_hooks());

    expect($hooks)->toContain('wp_enqueue_scripts:wp_enqueue_emoji_styles:10', 'admin_enqueue_scripts:wp_enqueue_emoji_styles:10')
        ->and($hooks)->toContain('wp_head:print_emoji_detection_script:7', 'wp_print_styles:print_emoji_styles:10', 'admin_print_scripts:print_emoji_detection_script:10', 'admin_print_styles:print_emoji_styles:10');
});
