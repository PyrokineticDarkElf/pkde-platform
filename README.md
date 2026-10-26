# pkde/platform

The shared must-use plugins of every PKDE WordPress site (Bedrock), as one Composer package. Public, GPL-2.0-or-later. Design: ADR 0007 in the
[starter template](https://github.com/PyrokineticDarkElf/bedrock-template) (`docs/decisions/template/0007-shared-code-as-versioned-packages.md`).

## Use

In the site's root `composer.json`:

```json
"repositories": [{ "type": "vcs", "url": "https://github.com/PyrokineticDarkElf/pkde-platform" }],
"require": { "pkde/platform": "2026.10.21" }
```

Pin an **exact tag**; Renovate opens the bump PR. The repository is public, so Composer needs no token.

Composer installs it to `web/app/mu-plugins/pkde-platform/` (the `installer-name` in `composer.json`; the directory is gitignored in the
site). Bedrock's autoloader includes `pkde-platform.php`, which requires every module in `modules/`.

## Modules

`cf7-assets-on-demand`, `cf7-test-copy`, `coming-soon`, `content-signal`, `disable-comments`, `google-tag-manager`, `hide-wp-version`,
`local-business-schema`, `login-slug`, `mailpit-smtp`, `post-smtp-env`, `private-usernames`, `safe-svg`, `staging-mail-guard`, `wordfence-local-dns`. What each does:
`docs/modules.md` and `docs/private-usernames.md` (these docs are not installed on sites).

Switch one off on a site: `PKDE_PLATFORM_DISABLE=google-tag-manager,safe-svg` (environment variable or a constant in
`config/application.php`).

## Security

Report vulnerabilities privately (see `SECURITY.md`). Nothing here is secret and nothing relies on the code being secret. One point worth
stating plainly: `private-usernames` keeps the *login name* out of public places (author archives, REST, nicenames) as a privacy measure; the
generated names (two public word lists plus a 4-digit suffix, all drawn with `random_int`) are not an authentication secret. Protection against
guessing comes from strong passwords, lockouts and a bot challenge on the login form, which the sites configure separately.

## License

GPL-2.0-or-later (see `LICENSE`), the same as WordPress.

## Develop and release

* `composer install`, then `composer lint` and `composer test` (Pint and Pest). Tests do not need WordPress: modules keep their logic in
  pure functions.
* Add a module: a file in `modules/` (or a directory with a file of the same name), and its name in `Pkde\Platform\modules()`. A test
  fails if the list and the directory disagree.
* Working on it from a site: in the site, `composer config repositories.pkde-platform path ../pkde-platform` and require `dev-main`
  (mount the directory into the container; a symlink to a directory outside the mount does not resolve). Never commit that change.
* Release: add a `## YYYY.MM.DD` section to `CHANGELOG.md`, push, wait for CI, then `bin/release.sh YYYY.MM.DD`. That script is the only
  way a tag is made (no branch protection on the free plan). Roll a site back by pinning the previous tag.
