<?php

require_once __DIR__ . '/site-root.php';

/*
|--------------------------------------------------------------------------
| One page measure (ADR 0008)
|--------------------------------------------------------------------------
|
| The header, page header, footer, shop and every page container share ONE width (`max-w-content`, the --container-content token)
| and ONE side padding (the --page-gutter token), so a Section, the header and the footer start and end at the same x. A fixed
| Tailwind width or px-6 in a view brings the drift back (shop pages 64px inside the header, 2026-10-26).
*/

function themeFile(string $relative): string
{
    return file_get_contents(pkde_site_root() . '/web/app/themes/sage/' . $relative);
}

it('sizes page containers with max-w-content and the gutter token, never a fixed max-w-6xl/7xl or px-6', function () {
    $offenders = [];
    foreach (glob(pkde_site_root() . '/web/app/themes/sage/resources/{views,blocks}/{,*/,*/*/,*/*/*/}*.php', GLOB_BRACE) as $file) {
        $source = file_get_contents($file);
        if (preg_match('/\bmax-w-(?:6xl|7xl)\b/', $source) || preg_match('/max-w-content[^"\']*\bpx-6\b|\bpx-6\b[^"\']*max-w-content/', $source)) {
            $offenders[] = basename($file);
        }
    }

    expect($offenders)->toBe([]);
});

it('defines the gutter token, with the gutter preset following it, and leaves the Section measure to the kit default', function () {
    $css = themeFile('resources/css/theme.css');

    expect($css)->toContain('--page-gutter: 1.5rem;')
        ->and($css)->not->toMatch('/^\\s*--pkde-section-measure:/m')
        ->and(themeFile('theme.json'))->toContain('"size": "var(--page-gutter)"');
});

it('keeps theme.json wideSize at the header content width, so alignwide lines up with the header', function () {
    $css = themeFile('resources/css/theme.css');
    preg_match('/--page-gutter:\\s*([\\d.]+)rem/', $css, $gutter);
    $container = 80; // --container-content: var(--container-7xl), 80rem
    $layout = json_decode(themeFile('theme.json'), true)['settings']['layout'];

    expect($layout['wideSize'])->toBe(($container - 2 * (float) $gutter[1]) . 'rem');
});

it('keeps the 404, author and about views on the page measure: narrow text sits in an inner column, never in the container', function () {
    $offenders = [];
    foreach (['pages/404', 'blog/author', 'template-about'] as $view) {
        // A site that has no such view (or its own) is not held to the template's.
        if (! is_file(pkde_site_root() . "/web/app/themes/sage/resources/views/$view.blade.php")) {
            continue;
        }

        $source = themeFile("resources/views/$view.blade.php");
        // a container (mx-auto + px-6) narrower than the page is the drift this guards against
        if (preg_match('/mx-auto max-w-(?:2xl|3xl|4xl)[^"]*\\bpx-6\\b/', $source) || ! str_contains($source, 'max-w-content px-[var(--page-gutter,1.5rem)]')) {
            $offenders[] = $view;
        }
    }

    expect($offenders)->toBe([]);
});

it('keeps theme.json contentSize at a reading width, so flowing text never runs the page measure', function () {
    $layout = json_decode(themeFile('theme.json'), true)['settings']['layout'];

    expect($layout['contentSize'])->toBe(siteDeviation('content_size', '48rem')); // a site that sets flowing text at the page measure on purpose says so in tests/site-deviations.php
});

it('takes the header breakpoint and the opt-in search from the kit, with the unchanged sm default', function () {
    $header = themeFile('resources/views/sections/header.blade.php');

    expect($header)->toContain('\App\nav_classes()')
        ->and($header)->toContain("pkde-kit::partials.header-search")
        ->and($header)->not->toContain('hidden sm:block')
        ->and($header)->not->toMatch('/class="[^"]*\bsm:hidden\b/');
});

it('never makes a narrow column its own container: a max-w-2xl to 5xl element with its own px-6 or px-gutter shrinks the section below the header', function () {
    $offenders = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(pkde_site_root() . '/web/app/themes/sage/resources/views', FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }
        // One class attribute, either order. The container is `max-w-content px-[var(--page-gutter,1.5rem)]`; the narrow column goes in an inner div.
        $source = file_get_contents($file->getPathname());
        if (preg_match('/class="[^"]*\bmax-w-(?:2xl|3xl|4xl|5xl)\b[^"]*\bpx-(?:6|\[var\(--page-gutter)/', $source) || preg_match('/class="[^"]*\bpx-(?:6|\[var\(--page-gutter)[^"]*\bmax-w-(?:2xl|3xl|4xl|5xl)\b/', $source)) {
            $offenders[] = str_replace(pkde_site_root() . '/web/app/themes/sage/resources/views/', '', $file->getPathname());
        }
    }

    expect($offenders)->toBe([]);
});

it('imports the kit\'s editor stylesheet (kit.css and the editor width), so the block editor canvas is as wide as the page and the kit blocks are styled in it', function () {
    expect(themeFile('resources/css/editor.css'))->toContain('vendor/pkde/sage-kit/resources/css/editor-kit.css');

    // the file itself ships in the kit and is checked there (EditorKitCssTest): the theme's vendor/ is not installed when CI runs these root tests
});

it('has no margin above the footer: a tinted last section would leave a strip of the page background (MDS-73)', function () {
    expect(themeFile('resources/views/sections/footer.blade.php'))->toMatch('/^<footer class="content-info bg-neutral-950/')->not->toMatch('/^<footer[^>]*\bmt-\d+/');
});
