<?php

require_once dirname(__DIR__, 2) . '/pkde-platform.php';

use function Pkde\Platform\enabled_modules;
use function Pkde\Platform\module_file;
use function Pkde\Platform\modules;

it('lists exactly the modules that exist in modules/', function () {
    $onDisk = [];
    foreach (glob(dirname(__DIR__, 2) . '/modules/*') as $path) {
        $onDisk[] = is_dir($path) ? basename($path) : basename($path, '.php');
    }
    sort($onDisk);
    $listed = modules();
    sort($listed);

    expect($listed)->toBe($onDisk);
});

it('finds every listed module, single file or directory', function () {
    foreach (modules() as $name) {
        expect(module_file($name))->not->toBeNull($name);
    }
    expect(module_file('private-usernames'))->toEndWith('modules/private-usernames/private-usernames.php')
        ->and(module_file('safe-svg'))->toEndWith('modules/safe-svg.php')
        ->and(module_file('no-such-module'))->toBeNull();
});

it('switches modules off by name and ignores unknown names, spaces and empty entries', function () {
    $all = ['a', 'b', 'c'];

    expect(enabled_modules($all, ''))->toBe($all)
        ->and(enabled_modules($all, 'b'))->toBe(['a', 'c'])
        ->and(enabled_modules($all, ' a , c ,,x'))->toBe(['b'])
        ->and(enabled_modules($all, 'a,b,c'))->toBe([]);
});

it('has no plugin header in any module the Bedrock autoloader could pick up', function () {
    // The autoloader includes every file with a plugin header in the ROOT of the package directory; modules/ is not scanned.
    $rootFiles = glob(dirname(__DIR__, 2) . '/*.php');

    expect(array_map('basename', $rootFiles))->toBe(['pkde-platform.php']);
});

it('ships nothing publicly servable: tests, docs and CI are export-ignored', function () {
    $attributes = file_get_contents(dirname(__DIR__, 2) . '/.gitattributes');

    foreach (['/tests', '/docs', '/.github', '/README.md', '/bin', '/composer.json'] as $path) {
        expect($attributes)->toContain("$path export-ignore");
    }
});
