<?php

// The mu-plugin defines its pure helpers and returns early outside WordPress, so it loads here without WordPress.
require_once dirname(__DIR__, 2) . '/modules/cf7-test-copy.php';

use function App\MuPlugins\cf7_test_copy_active;
use function App\MuPlugins\cf7_test_copy_components;

it('is active only while the stored timestamp is in the future', function () {
    expect(cf7_test_copy_active(2000, 1000))->toBeTrue()
        ->and(cf7_test_copy_active('2000', 1000))->toBeTrue()
        ->and(cf7_test_copy_active(1000, 1000))->toBeFalse()
        ->and(cf7_test_copy_active(500, 1000))->toBeFalse()
        ->and(cf7_test_copy_active(false, 1000))->toBeFalse()
        ->and(cf7_test_copy_active('', 1000))->toBeFalse()
        ->and(cf7_test_copy_active('soon', 1000))->toBeFalse();
});

it('adds a Bcc to the additional headers and changes nothing else', function () {
    $in = ['recipient' => 'office@example.com', 'subject' => 'Hi', 'body' => 'Text', 'additional_headers' => 'Reply-To: a@b.com'];
    $out = cf7_test_copy_components($in, 'admin@example.com');

    expect($out['additional_headers'])->toBe("Reply-To: a@b.com\nBcc: admin@example.com")
        ->and(array_diff_key($out, ['additional_headers' => 1]))->toBe(array_diff_key($in, ['additional_headers' => 1]));
    expect(cf7_test_copy_components(['additional_headers' => ''], 'admin@example.com')['additional_headers'])->toBe('Bcc: admin@example.com');
});

it('skips the Bcc when the admin already receives the mail', function () {
    $cases = [
        ['recipient' => 'Admin@Example.com', 'additional_headers' => ''],
        ['recipient' => 'office@example.com, admin@example.com', 'additional_headers' => ''],
        ['recipient' => 'office@example.com', 'additional_headers' => "Cc: admin@example.com"],
        ['recipient' => 'office@example.com', 'additional_headers' => "Reply-To: a@b.com\nbcc: Name <admin@example.com>"],
    ];

    foreach ($cases as $components) {
        expect(cf7_test_copy_components($components, 'admin@example.com'))->toBe($components);
    }
});

it('does not treat a Reply-To of the admin as a recipient', function () {
    $out = cf7_test_copy_components(['recipient' => 'office@example.com', 'additional_headers' => 'Reply-To: admin@example.com'], 'admin@example.com');

    expect($out['additional_headers'])->toContain('Bcc: admin@example.com');
});
