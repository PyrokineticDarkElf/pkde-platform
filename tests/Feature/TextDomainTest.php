<?php

/*
| Every user-facing string of the platform goes through gettext, so a site changes the wording (a plain-punctuation house style, say)
| with a `gettext` filter or a translation file instead of editing code. Domains: `pkde-text` for the platform's own screens, `sage`
| for the few strings that sit in a theme-facing page (the coming-soon gate). Any other domain would be a string a site cannot reach.
*/

it('uses only the pkde-text and sage text domains', function () {
    $root = dirname(__DIR__, 2);
    $other = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator("$root/modules", FilesystemIterator::SKIP_DOTS)) as $file) {
        if (! str_ends_with($file->getFilename(), '.php')) {
            continue;
        }
        preg_match_all("/\b(?:__|_x|_n|_nx|esc_html__|esc_attr__|esc_html_e|esc_attr_e)\((?:[^()]|\([^()]*\))*?,\s*'([a-z0-9_-]+)'\s*\)/i", file_get_contents($file->getPathname()), $m);
        foreach (array_unique($m[1]) as $domain) {
            if (! in_array($domain, ['pkde-text', 'sage'], true)) {
                $other[] = $domain . ' in ' . $file->getFilename();
            }
        }
    }

    expect($other)->toBe([]);
});

it('has no admin notice or form message written as a bare English string', function () {
    $source = file_get_contents(dirname(__DIR__, 2) . '/modules/cf7-test-copy.php');

    expect($source)->toContain("__('CF7 test copy active until %s: every form mail is also sent to %s.', 'pkde-text')");
});
