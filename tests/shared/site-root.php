<?php

// The root of the repository under test: the site that calls the reusable deploy workflow (PKDE_SITE_ROOT), else the repository that holds this file, so the same
// tests run in a site's CI through the workflow and, from a site checkout, by hand: PKDE_SITE_ROOT=. vendor/bin/pest --configuration ../pkde-platform/tests/shared/phpunit.xml
if (! function_exists('pkde_site_root')) {
    function pkde_site_root(): string
    {
        return rtrim((string) (getenv('PKDE_SITE_ROOT') ?: dirname(__DIR__, 2)), '/');
    }
}
