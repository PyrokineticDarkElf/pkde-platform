<?php

/**
 * The site-details module: the pure parts. The screen, the roles and Rank Math are proved in a real WordPress on the template stack.
 */
require_once dirname(__DIR__, 2) . '/modules/site-details/site-details.php';

use function App\MuPlugins\site_details_client_value;
use function App\MuPlugins\site_details_defaults;
use function App\MuPlugins\site_details_has_hours;
use function App\MuPlugins\site_details_hours_rows;
use function App\MuPlugins\site_details_merge;
use function App\MuPlugins\site_details_rank_math_titles;
use function App\MuPlugins\site_details_required;
use function App\MuPlugins\site_details_sanitize;
use function App\MuPlugins\site_details_strip_locked;

$e164 = function (string $raw, string $region): ?string {
    $digits = preg_replace('/[^\d+]/', '', $raw);

    if (str_starts_with($digits, '0') && $region === 'GB') {
        $digits = '+44' . substr($digits, 1);
    }

    return preg_match('/^\+[1-9]\d{7,14}$/', $digits) === 1 ? $digits : null;
};

it('cleans a submission: phones to E.164 with the typed form kept, bad ones named and dropped', function () use ($e164) {
    $errors = [];
    $clean = site_details_sanitize([
        'business' => ['trading_name' => '  Acme   <b>Clocks</b> ', 'company_number' => '01234567'],
        'phones' => [['label' => 'Sales', 'number' => '01234 567 890'], ['label' => 'Bad', 'number' => 'call me'], ['label' => '', 'number' => '']],
        'whatsapp' => '+44 7700 900123',
        'emails' => [['label' => 'Orders', 'address' => 'orders@example.test'], ['label' => '', 'address' => 'not an email']],
        'locations' => [['street' => '1 High St', 'town' => 'Leeds', 'postcode' => 'LS1 1AA', 'map_url' => 'https://maps.example.test/x'], ['street' => '', 'town' => '', 'map_url' => 'javascript:alert(1)']],
        'socials' => [['network' => 'instagram', 'url' => 'https://instagram.com/acme'], ['network' => 'nonsense', 'url' => 'https://x.test'], ['network' => 'x', 'url' => 'javascript:alert(1)'], ['network' => 'other', 'url' => 'https://other.test/a', 'label' => 'Blog']],
    ], $e164, 'GB', $errors);

    expect($clean['business']['trading_name'])->toBe('Acme Clocks')
        ->and($clean['phones'])->toBe([['label' => 'Sales', 'number' => '+441234567890', 'display' => '01234 567 890']])
        ->and($clean['whatsapp'])->toBe('+447700900123')
        ->and($clean['emails'])->toBe([['label' => 'Orders', 'address' => 'orders@example.test']])
        ->and($clean['locations'])->toHaveCount(1)->and($clean['locations'][0]['map_url'])->toBe('https://maps.example.test/x')
        ->and(array_column($clean['socials'], 'network'))->toBe(['instagram', 'other'])->and($clean['socials'][1]['label'])->toBe('Blog')
        ->and(implode('|', $errors))->toContain('"call me"')->toContain('"not an email"')->toContain('not a full web address');
});

it('keeps the weekly hours as pairs and drops a half-filled day', function () use ($e164) {
    $clean = site_details_sanitize(['hours' => ['weekly' => ['mon' => ['open' => '09:00', 'close' => '17:30'], 'tue' => ['open' => '09:00', 'close' => ''], 'wed' => ['open' => '25:00', 'close' => '17:00']],
        'exceptions' => [['date' => '2026-12-25', 'label' => 'Christmas Day'], ['date' => 'tomorrow', 'label' => 'x']]]], $e164, 'GB');

    expect($clean['hours']['weekly']['mon'])->toBe(['open' => '09:00', 'close' => '17:30'])
        ->and($clean['hours']['weekly']['tue'])->toBe(['open' => '', 'close' => ''])
        ->and($clean['hours']['weekly']['wed'])->toBe(['open' => '', 'close' => ''])
        ->and($clean['hours']['exceptions'])->toBe([['date' => '2026-12-25', 'label' => 'Christmas Day', 'open' => '', 'close' => '']]);
});

it('seeds from the declaration, lets stored values win, and always shows a locked value as declared', function () {
    $declared = ['business' => ['trading_name' => 'Declared Ltd', 'legal_name' => 'Declared Limited', 'company_number' => '111'], 'phones' => [['label' => '', 'number' => '+441111111111', 'display' => '01111 111111']]];
    $stored = ['business' => ['trading_name' => 'Edited name', 'legal_name' => 'Tampered Ltd', 'company_number' => ''], 'phones' => [['label' => '', 'number' => '+442222222222', 'display' => '02222 222222']]];

    $merged = site_details_merge($stored, $declared, ['business.legal_name']);

    expect($merged['business']['trading_name'])->toBe('Edited name')        // stored wins
        ->and($merged['business']['legal_name'])->toBe('Declared Limited')  // locked: the declaration
        ->and($merged['business']['company_number'])->toBe('111')           // empty in storage: seeded
        ->and($merged['phones'][0]['number'])->toBe('+442222222222');

    expect(site_details_merge($stored, $declared, ['phones'])['phones'][0]['number'])->toBe('+441111111111') // a locked group
        ->and(site_details_merge([], [], [])['business'])->toBe(site_details_defaults()['business']);
});

it('writes the hours as rows and says whether there are any', function () {
    $hours = ['weekly' => ['mon' => ['open' => '09:00', 'close' => '17:00']] + site_details_defaults()['hours']['weekly']];

    expect(site_details_hours_rows($hours)[0])->toBe(['Monday', '09:00 to 17:00'])->and(site_details_hours_rows($hours)[6])->toBe(['Sunday', 'Closed'])
        ->and(site_details_has_hours($hours))->toBeTrue()->and(site_details_has_hours(site_details_defaults()['hours']))->toBeFalse();
});

it('feeds Rank Math\'s title settings, and leaves its own value where Site details has none', function () {
    $details = array_replace(site_details_defaults(), [
        'business' => ['trading_name' => 'Acme Clocks'] + site_details_defaults()['business'],
        'phones' => [['label' => '', 'number' => '+441234567890', 'display' => '01234 567890']],
        'emails' => [['label' => '', 'address' => 'hello@example.test']],
        'locations' => [['street' => '1 High St', 'town' => 'Leeds', 'county' => '', 'postcode' => 'LS1 1AA', 'country' => 'GB', 'map_url' => '']],
        'socials' => [['network' => 'facebook', 'url' => 'https://facebook.com/acme', 'label' => ''], ['network' => 'x', 'url' => 'https://x.com/acme', 'label' => ''], ['network' => 'instagram', 'url' => 'https://instagram.com/acme', 'label' => '']],
    ]);
    $details['hours']['weekly']['mon'] = ['open' => '09:00', 'close' => '17:00'];

    $titles = site_details_rank_math_titles(['knowledgegraph_name' => 'Old', 'website_name' => 'Keep me'], $details);

    expect($titles['knowledgegraph_name'])->toBe('Acme Clocks')->and($titles['website_name'])->toBe('Keep me')
        ->and($titles['phone'])->toBe('+441234567890')->and($titles['phone_numbers'])->toBe([['type' => 'customer support', 'number' => '+441234567890']])
        ->and($titles['email'])->toBe('hello@example.test')
        ->and($titles['local_address'])->toBe(['streetAddress' => '1 High St', 'addressLocality' => 'Leeds', 'postalCode' => 'LS1 1AA', 'addressCountry' => 'GB'])
        ->and($titles['opening_hours'])->toBe([['day' => 'Monday', 'time' => '09:00-17:00']])
        ->and($titles['social_url_facebook'])->toBe('https://facebook.com/acme')->and($titles['twitter_author_names'])->toBe('acme')
        ->and($titles['social_additional_profiles'])->toBe('https://instagram.com/acme');

    // nothing set: Rank Math's settings are untouched
    expect(site_details_rank_math_titles(['knowledgegraph_name' => 'Old', 'email' => 'old@example.test'], site_details_defaults()))->toBe(['knowledgegraph_name' => 'Old', 'email' => 'old@example.test']);
});

it('says what a site of each type still needs', function () {
    $d = site_details_defaults();
    expect(site_details_required($d, 'organization'))->toBe(['the trading name', 'a phone number or an email address'])
        ->and(site_details_required($d, 'local-business'))->toContain('an address')
        ->and(site_details_required($d, 'shop'))->toContain('the legal name');

    $d['business']['trading_name'] = 'Acme';
    $d['emails'] = [['label' => '', 'address' => 'a@example.test']];
    expect(site_details_required($d, 'organization'))->toBe([]);
});

it('ships the screen, the capability and the script, and is listed in the loader', function () {
    $dir = dirname(__DIR__, 2);

    expect(file_get_contents("$dir/modules/site-details/site-details.php"))->toContain('manage_pkde_site_details', 'admin_post_pkde_site_details_save', 'check_admin_referer', 'option_rank-math-options-titles')
        ->and(is_file("$dir/modules/site-details/site-details.js"))->toBeTrue()
        ->and(file_get_contents("$dir/pkde-platform.php"))->toContain("'site-details'");
});

it('never stores a locked path, and makes the site an organisation or a local business for Rank Math from the declared type', function () {
    $clean = site_details_defaults();
    $clean['business']['legal_name'] = 'TAMPERED';
    $clean['business']['trading_name'] = 'Kept';
    $clean['phones'] = [['label' => '', 'number' => '+441132000000', 'display' => '0113']];

    $stored = site_details_strip_locked($clean, ['business.legal_name', 'phones', 'nonsense', 'business.nonsense']);

    expect($stored['business']['legal_name'])->toBe('')->and($stored['business']['trading_name'])->toBe('Kept')->and($stored['phones'])->toBe([]);

    expect(site_details_rank_math_titles(['knowledgegraph_type' => 'person', 'local_business_type' => 'Organization'], site_details_defaults(), 'local-business'))
        ->toBe(['knowledgegraph_type' => 'company', 'local_business_type' => 'LocalBusiness'])
        ->and(site_details_rank_math_titles(['local_business_type' => 'Store'], site_details_defaults(), 'local-business')['local_business_type'])->toBe('Store') // a more specific type is kept
        ->and(site_details_rank_math_titles(['knowledgegraph_type' => 'person'], site_details_defaults(), 'shop')['knowledgegraph_type'])->toBe('company')
        ->and(site_details_rank_math_titles(['knowledgegraph_type' => 'person'], site_details_defaults(), '')['knowledgegraph_type'])->toBe('person');
});

it('answers the old client_* options from Site details, and says nothing when there is nothing', function () {
    $d = site_details_defaults();
    $d['phones'] = [['label' => '', 'number' => '+441132000000', 'display' => '0113 200 0000']];
    $d['emails'] = [['label' => '', 'address' => 'hello@example.test']];
    $d['locations'] = [['street' => '1 High St', 'town' => 'Leeds', 'county' => '', 'postcode' => 'LS1 1AA', 'country' => 'GB', 'label' => '', 'map_url' => '']];
    $d['socials'] = [['network' => 'instagram', 'url' => 'https://instagram.com/a', 'label' => ''], ['network' => 'x', 'url' => 'https://x.com/a', 'label' => '']];

    expect(site_details_client_value('phone', $d))->toBe('0113 200 0000')
        ->and(site_details_client_value('email', $d))->toBe('hello@example.test')
        ->and(site_details_client_value('address', $d))->toBe('1 High St, Leeds, LS1 1AA')
        ->and(site_details_client_value('instagram', $d))->toBe('https://instagram.com/a')
        ->and(site_details_client_value('x', $d))->toBe('https://x.com/a')
        ->and(site_details_client_value('facebook', $d))->toBeNull()
        ->and(site_details_client_value('phone', site_details_defaults()))->toBeNull();
});
