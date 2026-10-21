<?php

// The mu-plugin defines its pure helper and returns early outside WordPress, so it loads here without WordPress.
require_once dirname(__DIR__, 2) . '/modules/hide-wp-version.php';

use function App\MuPlugins\strip_core_version;

it('strips only the core version from asset URLs', function () {
    expect(strip_core_version('/wp/wp-includes/css/style.css?ver=7.1.3', '7.1.3'))->toBe('/wp/wp-includes/css/style.css')
        ->and(strip_core_version('/a.js?ver=7.1.3&x=1', '7.1.3'))->toBe('/a.js?x=1')
        ->and(strip_core_version('/a.js?x=1&ver=7.1.3', '7.1.3'))->toBe('/a.js?x=1')
        ->and(strip_core_version('/a.js?x=1&ver=7.1.3&y=2', '7.1.3'))->toBe('/a.js?x=1&y=2')
        ->and(strip_core_version('/plugin.css?ver=2.10.7', '7.1.3'))->toBe('/plugin.css?ver=2.10.7')
        ->and(strip_core_version('/a.js?ver=7.1.31', '7.1.3'))->toBe('/a.js?ver=7.1.31')
        ->and(strip_core_version('/a.js', '7.1.3'))->toBe('/a.js');
});

it('still names the speculation-rules generator callback the installed plugin registers', function () {
    $hooks = dirname(__DIR__, 2) . '/web/app/plugins/speculation-rules/hooks.php';

    if (! file_exists($hooks)) {
        $this->markTestSkipped('the speculation-rules plugin is not installed');
    }

    // If an update renames the function, the mu-plugin would silently stop removing the tag.
    expect(file_get_contents($hooks))->toContain("add_action( 'wp_head', '" . App\MuPlugins\PLUGIN_GENERATOR_CALLBACKS[0] . "' )");
});
