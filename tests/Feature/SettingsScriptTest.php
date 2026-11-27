<?php

require_once dirname(__DIR__, 2) . '/bin/deploy/settings.php';

it('names every plugin settings script and finds the file it starts', function () {
    $dir = dirname(__DIR__, 2) . '/bin/deploy';

    expect(array_keys(pkde_settings_scripts()))->toBe(['rankmath', 'w3tc', 'wordfence', 'relevanssi', 'cf7-key', 'sitemap'])
        ->and(pkde_settings_script('w3tc'))->toBe('w3tc-settings.php')
        ->and(pkde_settings_script('nope'))->toBeNull()
        ->and(pkde_settings_script(null))->toBeNull();

    foreach (pkde_settings_scripts() as $file) {
        expect(is_file("$dir/$file"))->toBeTrue($file);
    }
});
