<?php

// The mu-plugin defines its pure helpers and then returns early unless WP_ENV is 'staging', so it can be
// loaded here without WordPress.
require_once dirname(__DIR__, 2) . '/modules/staging-mail-guard.php';

use function App\MailGuard\mask_recipients;

it('masks recipient addresses for the log', function () {
    expect(mask_recipients('jane.doe@example.com'))->toBe('j***@example.com')
        ->and(mask_recipients('Jane Doe <jane.doe@example.com>'))->toBe('j***@example.com')
        ->and(mask_recipients(['a@x.test', 'Bob <bob@y.test>']))->toBe('a***@x.test, b***@y.test')
        ->and(mask_recipients('not-an-address'))->toBe('***')
        ->and(mask_recipients([]))->toBe('(none)');
});
