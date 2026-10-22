<?php

// The module defines its pure helper and returns early outside WordPress, so it loads here without WordPress.
require_once dirname(__DIR__, 2) . '/modules/cf7-assets-on-demand.php';

use function App\MuPlugins\cf7_late_form_action;

it('does nothing when the assets are already enqueued', function () {
    expect(cf7_late_form_action(true, true))->toBe('none')
        ->and(cf7_late_form_action(true, false))->toBe('none');
});

it('defers while wp_enqueue_scripts has not run, as for a Blade view that renders before its layout', function () {
    expect(cf7_late_form_action(false, false))->toBe('defer');
});

it('enqueues late only once wp_enqueue_scripts has run and nothing opted in', function () {
    expect(cf7_late_form_action(false, true))->toBe('enqueue');
});
