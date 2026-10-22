# Changelog

## 2026.10.22

- Fix: `cf7-assets-on-demand` logged its "no opt-in" notice on pages whose content has the form. A Sage/Blade view renders before its layout, so the form renders before `wp_enqueue_scripts` has run (Contact Form 7 has not registered its handles yet, so an enqueue then is dropped). The safety net now waits for that action and only enqueues late, and logs, when the page really did not opt in. Pest cases cover the decision.

## 2026.10.21.1

- The CF7 test-copy admin notice goes through gettext (domain `pkde-text`) like every other user-facing string, with the same default wording, so a site can change it with a `gettext` filter or a translation file. A test checks that every module uses only the `pkde-text` and `sage` domains.

## 2026.10.21

- First public release: the shared must-use plugins of a Bedrock WordPress site as one Composer package (cf7-assets-on-demand, cf7-test-copy, coming-soon, content-signal, disable-comments, google-tag-manager, hide-wp-version, local-business-schema, mailpit-smtp, post-smtp-env, private-usernames, safe-svg, staging-mail-guard) behind one loader file; modules can be switched off with PKDE_PLATFORM_DISABLE. GPL-2.0-or-later, SECURITY.md, a secret scan and a public-safety test in CI; the installed package contains no tests, docs or composer.json.
