<?php

require_once dirname(__DIR__, 2) . '/modules/disable-comments.php';

it('keeps comments only on products, and only with WooCommerce (reviews are comments)', function () {
    expect(\App\MuPlugins\keeps_comments('product', true))->toBeTrue()
        ->and(\App\MuPlugins\keeps_comments('product', false))->toBeFalse()
        ->and(\App\MuPlugins\keeps_comments('post', true))->toBeFalse()
        ->and(\App\MuPlugins\keeps_comments('page', true))->toBeFalse();
});
