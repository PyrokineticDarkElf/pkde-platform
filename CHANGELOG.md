# Changelog

## 2026.10.21.1

- The CF7 test-copy admin notice goes through gettext (domain `pkde-text`) like every other user-facing string, with the same default wording, so a site can change it with a `gettext` filter or a translation file. A test checks that every module uses only the `pkde-text` and `sage` domains.

## 2026.10.21

- First public release: the shared must-use plugins of a Bedrock WordPress site as one Composer package (cf7-assets-on-demand, cf7-test-copy, coming-soon, content-signal, disable-comments, google-tag-manager, hide-wp-version, local-business-schema, mailpit-smtp, post-smtp-env, private-usernames, safe-svg, staging-mail-guard) behind one loader file; modules can be switched off with PKDE_PLATFORM_DISABLE. GPL-2.0-or-later, SECURITY.md, a secret scan and a public-safety test in CI; the installed package contains no tests, docs or composer.json.
