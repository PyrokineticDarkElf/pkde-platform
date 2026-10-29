<?php

require_once dirname(__DIR__, 2) . '/modules/restrict-unfiltered-html.php';

use function App\MuPlugins\unfiltered_html_caps;

it('removes unfiltered_html from everyone who is not an administrator', function () {
    expect(unfiltered_html_caps(['unfiltered_html'], 'unfiltered_html', false))->toBe(['do_not_allow']);
});

it('leaves administrators, an opted-in site and every other capability alone', function () {
    expect(unfiltered_html_caps(['unfiltered_html'], 'unfiltered_html', true))->toBe(['unfiltered_html'])
        ->and(unfiltered_html_caps(['unfiltered_html'], 'unfiltered_html', false, true))->toBe(['unfiltered_html'])
        ->and(unfiltered_html_caps(['edit_posts'], 'edit_posts', false))->toBe(['edit_posts']);
});

it('removes remote references from SVG uploads', function () {
    expect(file_get_contents(dirname(__DIR__, 2) . '/modules/safe-svg.php'))->toContain('removeRemoteReferences(true)');
});
