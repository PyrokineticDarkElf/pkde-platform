<?php

// The module defines its pure helper and returns early outside WordPress, so it loads here without WordPress.
require_once dirname(__DIR__, 2) . '/modules/disable-xmlrpc.php';

use function App\MuPlugins\xmlrpc_allowed;

it('is off unless the site opts in', function () {
    expect(xmlrpc_allowed(''))->toBeFalse()
        ->and(xmlrpc_allowed('false'))->toBeFalse()
        ->and(xmlrpc_allowed('0'))->toBeFalse()
        ->and(xmlrpc_allowed('nope'))->toBeFalse();
});

it('accepts an opt-in from .env or from the filter', function () {
    foreach (['1', 'true', 'TRUE', ' yes ', 'on'] as $value) {
        expect(xmlrpc_allowed($value))->toBeTrue();
    }

    expect(xmlrpc_allowed('', true))->toBeTrue();
});

it('turns the methods, the pingback header and pings off, and refuses SVG over XML-RPC', function () {
    $module = file_get_contents(dirname(__DIR__, 2) . '/modules/disable-xmlrpc.php');
    $svg = file_get_contents(dirname(__DIR__, 2) . '/modules/safe-svg.php');

    expect($module)->toContain("add_filter('xmlrpc_enabled', '__return_false')")
        ->and($module)->toContain("add_filter('xmlrpc_methods', '__return_empty_array')")
        ->and($module)->toContain("unset(\$headers['X-Pingback'])")
        ->and($module)->toContain("apply_filters('pkde_allow_xmlrpc'")
        ->and($svg)->toContain("defined('XMLRPC_REQUEST')");
});
