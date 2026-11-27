<?php

require_once __DIR__ . '/site-root.php';

/*
|--------------------------------------------------------------------------
| Repo hygiene
|--------------------------------------------------------------------------
|
| Each test here encodes a mistake that actually reached a live site, so CI
| fails loudly instead of the next deploy quietly repeating it.
*/

function repoRoot(): string
{
    return pkde_site_root();
}

/** @return list<string>|null null when git is unavailable (the test is skipped) */
function trackedFiles(): ?array
{
    $out = [];
    $code = 0;
    exec('git -c safe.directory=* -C ' . escapeshellarg(repoRoot()) . ' ls-files 2>/dev/null', $out, $code);

    return $code === 0 && $out !== [] ? $out : null;
}

function isIgnored(string $path): bool
{
    exec('git -c safe.directory=* -C ' . escapeshellarg(repoRoot()) . ' check-ignore -q ' . escapeshellarg($path), $o, $code);

    return $code === 0;
}

it('does not track runtime state, dev tooling or files that would be publicly served', function () {
    $files = trackedFiles();
    if ($files === null) {
        $this->markTestSkipped('git is not available');
    }

    // The template itself tracks bin/, CLAUDE.md and the theme docs by design, so those three
    // rules only apply to a site repo cloned from it (where bin/setup is not tracked). Tracked, not
    // merely present: a site keeps bin/setup on disk as an untracked file, and checking the disk
    // would skip these rules locally so that only CI (which has no bin/setup) enforced them.
    $isTemplate = in_array('bin/setup', $files, true);

    // [pattern, reason, siteOnly]: siteOnly rules are skipped in the template, which tracks those files by design.
    $rules = [
        ['#^bin/(?!deploy/)#', 'dev tooling (only bin/deploy/ belongs in the repo)', true],
        ['#^CLAUDE\.md$#', 'AI tooling instructions', true],
        ['#^web/app/(w3tc-config|wflogs|ewww|uploads|cache)/#', 'plugin runtime state (lives in shared/ on the servers)', false],
        ['#^web/app/(db|advanced-cache|object-cache)\.php$#', 'generated drop-in', false],
        ['#^web/(demo-content\.html|nginx\.conf|\.htaccess|\.user\.ini)$#', 'would be publicly served or is server-managed', false],
        ['#^web/app/themes/[^/]+/docs/#', 'developer notes (publicly served under the docroot)', true],
        ['#(^|/)\.env(\.(?!example$)[^/]*)?$#', 'secrets', false],
        ['#\.(key|pem)$#', 'private key', false],
        ['#(^|/)\.admin-credentials$#', 'generated admin password', false],
        ['#\.sql(\.gz)?$#', 'database dump', false],
        ['#(^|/)users[^/]*\.csv$#', 'real user list', false],
        ['#^local-data/#', 'local copy of a live site (DB dumps, media)', false],
    ];

    $violations = [];
    foreach ($files as $file) {
        foreach ($rules as [$pattern, $why, $siteOnly]) {
            if (! ($siteOnly && $isTemplate) && preg_match($pattern, $file)) {
                $violations[] = "$file ($why)";
            }
        }
    }

    expect($violations)->toBe([]);
});

it('keeps local-data/ ignored by git and skipped by Pint', function () {
    expect(isIgnored('local-data/dump.sql'))->toBeTrue()
        ->and(isIgnored('local-data/uploads/2026/photo.jpg'))->toBeTrue();

    $pint = json_decode(file_get_contents(repoRoot() . '/pint.json'), true);
    expect($pint['exclude'])->toContain('local-data');
});

it('tracks every hand-written mu-plugin, including ones in subdirectories', function () {
    $files = trackedFiles();
    if ($files === null) {
        $this->markTestSkipped('git is not available');
    }

    // Composer-installed mu-plugins are the only ones allowed to be ignored.
    $composerManaged = ['bedrock-disallow-indexing', 'pkde-platform'];

    $ignored = [];
    foreach (glob(repoRoot() . '/web/app/mu-plugins/*/*.php') as $abs) {
        $rel = substr($abs, strlen(repoRoot()) + 1);
        if (in_array(basename(dirname($abs)), $composerManaged, true)) {
            continue;
        }
        if (isIgnored($rel)) {
            $ignored[] = $rel;
        }
    }

    expect($ignored)->toBe([])
        ->and($files)->toContain('web/app/mu-plugins/bedrock-autoloader.php');
});

it('keeps plugin state paths configurable from the environment', function () {
    $config = file_get_contents(repoRoot() . '/config/application.php');

    foreach (['W3TC_CONFIG_DIR', 'WFWAF_LOG_PATH', 'WP_CACHE', 'WORDFENCE_DISABLE_MISCONFIGURED_HOWGETIPS', 'WP_ADMIN_EMAIL', 'WORDFENCE_ALERT_EMAIL'] as $constant) {
        expect($config)->toContain($constant);
    }
});

it('keeps web/wp-config.php as the untouched Bedrock loader', function () {
    // W3 Total Cache (and other plugins) append define() lines to wp-config.php.
    // It is a tracked file that every deploy overwrites, so any such edit is lost
    // silently. Constants belong in config/application.php.
    $wpConfig = file_get_contents(repoRoot() . '/web/wp-config.php');

    expect($wpConfig)->not->toMatch('/^\s*define\s*\(/m');
});

it('publishes the Docker ports on 127.0.0.1 only', function () {
    $compose = file_get_contents(repoRoot() . '/docker-compose.yml');

    preg_match_all('/^\s*-\s*"([^"]*:\d+)"\s*$/m', $compose, $m);
    $published = array_filter($m[1], fn($mapping) => substr_count($mapping, ':') >= 1 && ! str_contains($mapping, '/'));

    expect($published)->not->toBeEmpty();
    foreach ($published as $mapping) {
        expect($mapping)->toStartWith('127.0.0.1:');
    }
});

it('ignores .env variants and private keys but keeps .env.example', function () {
    expect(isIgnored('.env.production'))->toBeTrue()
        ->and(isIgnored('server.pem'))->toBeTrue()
        ->and(isIgnored('.env.example'))->toBeFalse();
});

it('exits when the theme entry point is requested directly (ABSPATH guard)', function () {
    expect(file_get_contents(repoRoot() . '/web/app/themes/sage/functions.php'))->toContain("if (! defined('ABSPATH'))");
});
