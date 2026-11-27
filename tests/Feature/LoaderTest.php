<?php

require_once dirname(__DIR__, 2) . '/pkde-platform.php';

use function Pkde\Platform\enabled_modules;
use function Pkde\Platform\enabled_parts;
use function Pkde\Platform\module_parts;
use function Pkde\Platform\part_file;
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
    expect(module_file('faq'))->toEndWith('modules/faq/faq.php')
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

it('keeps every old module name working: the merged modules list them as parts, and each part has a file', function () {
    $old = ['cf7-assets-on-demand', 'cf7-editor-preview', 'cf7-form-key', 'local-mail-guard', 'mailpit-smtp', 'post-smtp-env', 'staging-mail-guard',
        'local-business-schema', 'product-schema', 'schema-absolute-urls', 'author-archives', 'private-usernames', 'rest-users-private'];
    $parts = array_merge(...array_values(module_parts()));
    sort($parts);
    sort($old);

    expect($parts)->toBe($old)
        ->and(array_keys(module_parts()))->each->toBeIn(modules());

    foreach (module_parts() as $module => $names) {
        foreach ($names as $part) {
            expect(part_file($module, $part))->not->toBeNull("$module/$part");
        }
    }
});

it('switches one part off by its old name and the whole merged module by its new one', function () {
    $all = module_parts()['mail'];

    expect(enabled_parts('mail', ''))->toBe($all)
        ->and(enabled_parts('mail', 'post-smtp-env'))->toBe(['local-mail-guard', 'mailpit-smtp', 'staging-mail-guard'])
        ->and(enabled_parts('mail', 'staging-mail-guard, local-mail-guard'))->toBe(['mailpit-smtp', 'post-smtp-env'])
        ->and(enabled_modules(modules(), 'mail'))->not->toContain('mail')
        ->and(enabled_modules(modules(), 'post-smtp-env'))->toContain('mail');
});
