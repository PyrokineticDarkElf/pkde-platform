# Changelog

## 2026.10.29

ADOPT ALL audit-fix batch (MGR-67, TPL-138). **Adopt:** bump only, for sites on the package; `ALLOW_XMLRPC=true` in `.env` for a site that uses XML-RPC (the WordPress mobile app, Jetpack); see the release feed.

- **XML-RPC is off by default** (new module `disable-xmlrpc`; owner decision 2026-10-29, bp/jsd/rp audits): `xmlrpc_enabled` false, every method removed, the `X-Pingback` header dropped, pings closed. `ALLOW_XMLRPC=true` in `.env` (or `add_filter('pkde_allow_xmlrpc', '__return_true')`) opts a site back in. Even then SVG uploads are refused over XML-RPC (that route writes with `wp_upload_bits()`, which has no sanitiser hook). The deploy tools also deny `xmlrpc.php` in `.htaccess` (pkde-deploy 2026.10.29.1).
- **`unfiltered_html` for administrators only** (new module `restrict-unfiltered-html`; tcs audit): Editors can no longer save script or iframes in content (stored XSS that runs for visitors and for the administrators who open it); Administrators keep it for GTM snippets and embeds. `add_filter('pkde_editors_unfiltered_html', '__return_true')` restores it for a site that needs Editors to have it.
- **safe-svg removes remote references** (`removeRemoteReferences(true)`; tcs audit): external `<use href>`, `xlink:href` and CSS `url()` targets are stripped from uploaded SVGs.
- Pest cases for the three (the pure rules, the hooks and the SVG option).

## 2026.10.28.3

- **Security fix (rp audit, RP-84):** `safe-svg` sanitised an upload only when the client-sent `$file['type']` was `image/svg+xml`, and hooked only `wp_handle_upload_prefilter`. An SVG named `x.svg` but sent as another Content-Type (or none), or uploaded through the REST API's raw-body upload or any programmatic sideload (`wp_handle_sideload_prefilter`), was stored unsanitised (WordPress stores it as `image/svg+xml` from its extension, so script and event-handler attributes survived). Now an upload is sanitised when its type says SVG OR its file name ends in `.svg` (any case), and the same sanitiser is hooked on the sideload prefilter too. Pest cases cover the decision and the two hooks. **Adopt:** bump only (all sites on the package; sites on copies get it at Phase C). Not in this release: `X-Content-Type-Options: nosniff` and a CSP for `/app/uploads` SVG (a server header: a pkde-deploy hardening-block change, tracked separately).

## 2026.10.28.2

- `local-mail-guard` also removes live-capable payment gateways from checkout when `WP_ENV=development` (manager, TPL-128). WooCommerce Stripe was already forced into test mode at runtime (settings filter, no database writes: `testmode=yes`, every non-`test_` key, secret, token and webhook secret blanked); now `woocommerce_available_payment_gateways` (priority 999) drops every gateway that is neither offline (`bacs`, `cheque`, `cod`) nor reporting test mode (`testmode` or `sandbox` = yes/1/true/on), so a live PayPal, WooPayments or Square setup in an imported database cannot take a payment from a local site. Pest cases cover the rule and the filter run with stub gateways. Staging and production are untouched. **Adopt:** nothing; a bump is enough. A gateway that keeps its test flag under another name is dropped (safe side): say so and it is added.

## 2026.10.28.1

- `local-mail-guard` also answers the rest of the live state a database import brings, at runtime and without writing to the database (MGR-61 scope, from js and bb; TPL-128). With `WP_ENV=development`: the site admin email (`admin_email`, `new_admin_email`) and the WooCommerce new/cancelled/failed order recipients and every Contact Form 7 recipient become `LOCAL_TEST_EMAIL` (default `local-test@example.com`); WooCommerce Stripe settings are forced into test mode with every key, secret, token and webhook secret blanked except the `test_` ones (so a live key can never charge a card); requests to Mailchimp, Sender, Brevo/Sendinblue, Klaviyo, MailerLite and HubSpot are refused with a WP_Error. These apply even without `MAIL_HOST`. Staging and production are untouched. The stored values stay in the database, so the scrub in `docs/modules.md` is still done; this makes a forgotten step harmless. Pest cases cover the Stripe blanking, the host list, and the whole module run in a fresh process with live-like values. **Adopt:** nothing; a bump is enough.

## 2026.10.28

- New module `local-mail-guard` (MGR-61, from el; TPL-128): a local site filled from a live database kept Post SMTP's stored `postman_options` (live SMTP login, sender) and the live Contact Form 7 recipients, so a local test form tried to send real mail through the live mailbox. When `WP_ENV` is `development`, every read of `postman_options` now answers a fresh Mailpit option (`MAIL_HOST`/`MAIL_PORT`, no login, no encryption, sender = the admin email) and PHPMailer is pointed at Mailpit last (priority 999). Nothing from the stored row is used, so no live credential is either. Without `MAIL_HOST` every message is dropped and one line goes to the error log. Staging and production are untouched. Pest cases run the module in a fresh PHP process with live-like options and check that none of their values come back.
- Settings fall back to `.env` (TPL-128, manager): a new `Pkde\Platform\setting($name)` reads the constant if `config/application.php` defined it, else `$_ENV`, `$_SERVER`, `getenv()` and Bedrock's `env()`. `phone-e164`, `login-slug`, `post-smtp-env`, `content-signal`, `google-tag-manager` and `local-mail-guard` use it, so a package bump plus a `.env` line is enough: a site no longer has to port the template's `Config::define` lines for `PHONE_DEFAULT_REGION`, `PHONE_PREFERRED_COUNTRIES`, `LOGIN_SLUG`, `SMTP_*`. **Adopt:** nothing to port; sites that already define the constants keep working (a constant wins). The template's `config/application.php` lines stay as the documented default.
- Docs: "After importing a live database" in `docs/modules.md` lists what a live import brings that no module can reach (Post SMTP options and log, CF7 recipients and reCAPTCHA keys, Stripe test mode, WooCommerce email recipients, MailPoet/Sender).

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
