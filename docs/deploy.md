# The deploy pipeline (reusable workflow and tools)

The PKDE build-and-deploy pipeline is a **reusable GitHub Actions workflow** (`.github/workflows/deploy.yml`, and `audit.yml`) plus the **deploy tools** it uses: `bin/deploy/` (the release and checks), `bin/diagnostics/` (run by hand, never by the workflow) and `bin/migrate/` (one-off migration tools, kept until they are deleted). They used to be the separate repository pkde-deploy; they live here now, in one repository with the platform modules, one tag for both. Nothing under `bin/` is ever shipped to a site's docroot (`bin` is export-ignored in the Composer dist); the workflow checks the tools out of this repository at its own stamped tag.

## A site uses it

`.github/workflows/deploy.yml` of a site (the caller; triggers, `paths-ignore` and `concurrency` stay in the site):

```yaml
name: Deploy
on:
  push:
    branches: [main, develop]
    paths-ignore: ['backlog/**', 'changelog.d/**', 'CHANGELOG.md', 'docs/**']
  pull_request:
  workflow_dispatch:
concurrency:
  group: deploy-${{ github.ref }}
  cancel-in-progress: false
jobs:
  deploy:
    uses: PyrokineticDarkElf/pkde-platform/.github/workflows/deploy.yml@2026.12.10   # an exact tag; Renovate bumps it
    with:
      deploy-enabled: ${{ vars.DEPLOY_ENABLED }}
    secrets:
      PROD_SSH_KEY: ${{ secrets.PROD_SSH_KEY }}
      # ... PROD_SSH_HOST, PROD_SSH_PORT, PROD_SSH_USER, PROD_DEPLOY_PATH and the same five STAGING_*
```

Inputs: `build-artifact` (default true; false for the starter), `deploy-enabled`, `deploy-allowed`, `runs-on`, `php-version`,
`node-version`, `theme-dir`. Secrets are passed explicitly (`secrets: inherit` is for organisations and enterprises); the only secrets are the server's `PROD_*` and `STAGING_*`.

* This repository and the packages it works with are **public**: Composer and the workflow's tools checkout need no token. The tools are checked out at `PKDE_PLATFORM_VERSION`, which `bin/release.sh` stamps with the tag in the tagged commit.

What the workflow does, in order: checks out the tools, `check-site-paths.sh` on the caller, actionlint, PHP and
Node, Composer install (dev), Pint, Pest, Composer install (production, root and theme), npm ci, token check, build, assemble and check
the artifact, and on a push to `main` (production) or `develop` (staging) with `DEPLOY_ENABLED` true: rsync to a new release directory,
then `activate-release.sh` as two execs on one SSH connection (`activate`, then `post`), each logging its duration.

## Required before a production release

Run `bin/diagnostics/fingerprint.sh compare-sites <staging-url> <production-url> --paths /,/about/,...` (a WooCommerce site adds `--hide '.related,.upsells'`: the related products are random) after the staging deploy and before the production release: it captures both sites (box, tag, DOM path and visible text per page at 1440 and 390; a capture that crashes in node is retried once) and lists every difference. It normalises what exists on one environment only by design: a CookieYes consent cookie with every optional category off, the consent banners and widgets of CookieYes, Complianz, Cookiebot, OneTrust, Borlabs and Termly, the Google Tag Manager frame and noscript are hidden, and `<picture>` counts as its `<img>` (EWWW WebP wrappers); `--raw` turns that off. A staging site behind Basic auth: `FINGERPRINT_AUTH=user:pass` in the environment (never an argument). Each one must be explained in the release task. Exit 0 means identical.

## Tools

**`bin/deploy/`** (what the workflow runs, and the deploy-side scripts): `activate-release.sh` (runs on the server), `assemble-artifact.sh` and `check-artifact.sh`, `composer-audit.sh` (the workflow runs it on the root and theme lock files: fails on a high, critical or unrated advisory; accept one deliberately with `config.audit.ignore` in that `composer.json`), `check-site-paths.sh`, `retry.sh`, `wp.sh`, `drift-check.php` (with `w3tc-standard.php`, `w3tc-settings.php`), the plugin-settings scripts (`rankmath-*`, `wordfence-*`, `relevanssi-settings.php`, `cf7-form-key*.php`, `seo-sitemap-exclusions.php`, `wfconfig-sql.php`), `wordfence-cloudflare.sh`, `waf-prepend.sh`, `alert-email.php` (the release step that sets Wordfence's alert recipients to exactly the alert address from .env: `WORDFENCE_ALERT_EMAIL`, else `WP_ADMIN_EMAIL`), `env-pull.sh`, `env-seed.sh`, `env-sync.py` and `push-gh-secrets.sh` (the server `.env` and its 1Password secure note `<host> .env`, one field per variable; `env-pull.sh` is a dry run unless `--apply`, `--check` changes nothing; values are never printed or put on a command line; see each script's header). `assemble-artifact.sh` ships `drift-check.php`, the settings scripts, the W3TC files and `wp.sh` into every release (outside the docroot).

**`bin/diagnostics/`** (by hand, not run by the workflow): `fingerprint.sh` and `.mjs`, `edges.mjs`, `perf-probe.sh` and `.php`, `prelaunch-audit.sh` and `.mjs`.

**`bin/migrate/`** (one-off, until they are deleted): `faq-migrate`, `legal-migrate`, `site-details-migrate`, `apply-pages`, `native/` (page-conversion tools) and `seo/` (`crawl.py`, `compare.py`, `text_compare.py`: the SEO baseline and the before/after comparison; `python3 bin/migrate/seo/crawl.py --base URL --out inventory`, then `compare.py live.csv new.csv [--accept PATHS] [--accept-file docs/seo/description-exceptions.md]`; needs python3 and `requests`).

**Local use.** The tools are not installed in a site (the site no longer requires pkde/deploy). Run them from a sibling checkout of this repository, `../pkde-platform/bin/deploy/...`: a shell script as `bash ../pkde-platform/bin/deploy/env-pull.sh`, a PHP script inside the local stack with the checkout mounted, for example `docker compose run --rm -v "$PWD/../pkde-platform:/pkde-platform:ro" wpcli wp --allow-root eval-file /pkde-platform/bin/deploy/drift-check.php`. The native tools use the current directory as the site root (or `PKDE_SITE_ROOT`).

## Temporary PHP files in a docroot

A PHP file deleted from a 20i docroot can keep answering for about 10 seconds: OPcache's `validate_timestamps` revalidates every 10 s and the NFS attribute cache adds to that. So any temporary PHP file (the performance probe's mu-plugin, the OPcache reset probe) must be gated by a random token, must print nothing sensitive, and must not be trusted to be gone the moment `rm` returns.

## Develop and release

The platform's own README has the release steps. The shell tests are `bash tests/deploy/<name>.test.sh`, the Python ones `python3 -m unittest discover -s tests/native`; CI (`.github/workflows/ci.yml`) runs all of them with Pint and Pest. `bin/release.sh` stamps the tag into both workflows (`PKDE_PLATFORM_VERSION`), waits for CI on that commit and tags it. Roll a site back by pinning the previous tag in its caller and in `composer.json`.
