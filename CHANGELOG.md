# Changelog

## 2026.10.27

- New opt-in module `phone-e164` (TPL-77, manager MGR-35): every Contact Form 7 `[tel]` field and the WooCommerce billing and shipping phone (classic checkout, checkout block, My Account addresses) is validated with libphonenumber and stored and mailed as E.164, for Google Ads Enhanced Conversions. Switched on by `PHONE_DEFAULT_REGION` in `.env` (blank = nothing happens); the number's own `+` and country code win, then the country the visitor picked (CF7: a hidden `<field>-country` from the kit's picker; WooCommerce: the address form's country), then the default. An invalid number is rejected with a message that names the country it tried. After a sent CF7 form the response carries `pkde_user_data` (phone and email) for the kit's data-layer push. Requires `giggsey/libphonenumber-for-php-lite` (^9.0; 9.0.41 locked in tests). Pest cases cover valid and invalid UK and international numbers, the region choice, the empty value, the message and the preferred countries.
- Hooks verified over HTTP and in Chrome with Contact Form 7 and WooCommerce 11.2.1 (see the template's `bin/docs/phone-e164.md`).

## 2026.10.26

- New module `wordfence-local-dns` (bb, local stacks only): Wordfence's per-request DNS lookup of `localhost:PORT` (its host pattern keeps the port) took about 2.2 s on every local page and was never cached. The module seeds Wordfence's `serverDNS` cache entry with a far-future timestamp and `127.0.0.1` when `WP_ENVIRONMENT_TYPE` is `local` and the entry is missing or expired. Local pages: 2.1 to 3.5 s before, 0.07 to 0.12 s after, on the template's dev stack. No effect on staging or production. Pest cases cover the entry format and the stale rule.

## 2026.10.23.2

- New module `login-slug` (MGR-54): the login slug is the same on staging and production. With `LOGIN_SLUG` set in `.env` (the template's `config/application.php` defines the constant), it answers every read of WPS Hide Login's `whl_page` option (`pre_option_whl_page`), a save of another value is ignored with a notice (Settings > General says the URL is managed in `.env`, and the field is read-only), and the stored row is never written. An unusable value (empty after cleaning, or containing `wp-login`) is ignored with an admin error. Unset = nothing changes. Verified locally with the real plugin: the stored row stays `stored-old`, the effective slug is the constant, `/<constant>/` is the login, the old slug and `wp-login.php` are 404, and a save attempt changes nothing. Pest cases cover the cleaning.

## 2026.10.23.1

- Hotfix (MGR-53), the real cause: Post SMTP 4 only calls its SMTP transport ready when the **envelope sender** is set too ("Envelope-From Email Address can not be empty"), and the overlay did not fill `envelope_sender`. With an empty stored `postman_options` row (a site where the Post SMTP wizard never ran, such as jsd staging) the active transport silently fell back to `PostmanDefaultModuleTransport` (`smtp:none:none://localhost:25`, the web server's local mail) although every other value was in force. The overlay now sets `envelope_sender` to the sender. Reproduced and verified locally with an empty stored row and a Mailpit SMTP server with login auth: before, the active transport is Default and nothing is delivered; after, it is Smtp and the test mail arrives.
- Belt and braces: with `SMTP_HOST` set, the non-secret values (transport, auth type, host, port, encryption, sender, envelope sender, sender name, sender-override lock) are also written into the stored `postman_options` on `plugins_loaded` (priority 0), only when the stored row differs, so nothing that reads the raw row misses them and the Post SMTP screen shows what is in force. The password is never stored: it stays the `POST_SMTP_AUTH_PASSWORD` constant.

## 2026.10.23

- Hotfix (MGR-53): `post-smtp-env` could leave Post SMTP without a From address, and Post SMTP 4.0.2 then marks its transport "not ready" (jsd production). The sender is now the first valid email address of `SMTP_FROM`, `SMTP_USERNAME`, `POST_SMTP_AUTH_USERNAME` (the names are read from the constants, then the environment).
- An admin notice (and one `error_log` line a day) says so when `SMTP_HOST` is set but no usable From exists, and when `SMTP_HOST` is not set on a `WP_ENV=production` site (mail would leave the web server unauthenticated). Strings go through gettext (`pkde-text`). Pest cases cover the sender chain and each notice condition.
- `composer.json` pins `config.platform.php` to 8.3.0 (dev installs only).

## 2026.10.22

- Fix: `cf7-assets-on-demand` logged its "no opt-in" notice on pages whose content has the form. A Sage/Blade view renders before its layout, so the form renders before `wp_enqueue_scripts` has run (Contact Form 7 has not registered its handles yet, so an enqueue then is dropped). The safety net now waits for that action and only enqueues late, and logs, when the page really did not opt in. Pest cases cover the decision.

## 2026.10.21.1

- The CF7 test-copy admin notice goes through gettext (domain `pkde-text`) like every other user-facing string, with the same default wording, so a site can change it with a `gettext` filter or a translation file. A test checks that every module uses only the `pkde-text` and `sage` domains.

## 2026.10.21

- First public release: the shared must-use plugins of a Bedrock WordPress site as one Composer package (cf7-assets-on-demand, cf7-test-copy, coming-soon, content-signal, disable-comments, google-tag-manager, hide-wp-version, local-business-schema, mailpit-smtp, post-smtp-env, private-usernames, safe-svg, staging-mail-guard) behind one loader file; modules can be switched off with PKDE_PLATFORM_DISABLE. GPL-2.0-or-later, SECURITY.md, a secret scan and a public-safety test in CI; the installed package contains no tests, docs or composer.json.
