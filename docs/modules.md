# Always-on mu-plugins

Single-file mu-plugins (loaded automatically by WordPress, no activation needed) for small,
site-wide behaviour that shouldn't depend on a plugin staying active. For the larger, multi-file
mu-plugin in this same directory, see [`private-usernames/README.md`](private-usernames/README.md).

## `disable-comments.php` — comments, trackbacks, pingbacks off sitewide

Off, sitewide, by design — not just closed per-post. Two layers:

1. **This file** filters `comments_open`/`pings_open` to `false` regardless of any post's
   individual setting, strips `comments`/`trackbacks` support from every registered post type, and
   hides the Comments screen/admin-bar entry.
2. **`bin/setup`** sets `default_comment_status`/`default_ping_status` to `closed` and passes
   `--comment_status=closed --ping_status=closed` on every page/post it seeds.

There's no "Leave a Reply" section anywhere in the theme — `content-single.blade.php` had its
`comments_template()` call removed entirely, along with the now-dead comments partial and its
view composer, rather than leaving unreachable code around.

Exception: when WooCommerce is active, **products keep comments**, because product reviews are comments (the Reviews tab and form, governed by WooCommerce's own reviews setting); everything else stays closed. The rule is the pure function `keeps_comments()`, tested in `tests/Feature/ThemeHelpersTest.php`.

## `safe-svg.php` — allow sanitised SVG uploads

WordPress blocks SVG uploads by default (XSS precaution — an unsanitised SVG can carry
`<script>`/event-handler attributes). A real client's logo and icon set is routinely SVG, so
rejecting the upload outright is near-universal friction, not a one-off case. This file allows the
mime type (`upload_mimes`, `wp_check_filetype_and_ext`) and then sanitises the actual file contents
through [`enshrined/svg-sanitize`](https://github.com/darylldoyle/svg-sanitizer) on
`wp_handle_upload_prefilter`, before WordPress ever moves the file into place — the mime-type
filters alone only make SVG *acceptable*, they don't make it *safe*.

## `mailpit-smtp.php` — routing local mail to Mailpit

Read by `MAIL_HOST`/`MAIL_PORT` in `.env` (see the root README's Environment variables table) —
leave both unset (e.g. a production `.env`) to fall back to PHP's default mail transport.

**Why mail needs its own mu-plugin at all:** PHP's default mail transport has nowhere to deliver
to inside the `php` container, so without this file routing `wp_mail()` through SMTP, every
outgoing email would just silently fail. It also fixes a second, unrelated problem that would
break outgoing mail even with SMTP wired up: WordPress derives its default "From" address as
`wordpress@<home_url's host>`, which on this template's default `WP_HOME` (`localhost`) becomes
`wordpress@localhost` — an address PHPMailer's own validation rejects outright (no dot in the
domain), failing `wp_mail()` silently before it ever reaches Mailpit. The mu-plugin swaps that one
specific case for the site's `admin_email`, which is already guaranteed to look like a real
address.

## `cf7-assets-on-demand.php` — Contact Form 7 JS/CSS only on form pages

Contact Form 7 loads its script and stylesheet on every page. This file disables that with its
`wpcf7_load_js` / `wpcf7_load_css` filters and re-enqueues them only where a form shows. See
[`bin/docs/contact-form.md`](../../../bin/docs/contact-form.md#assets-load-only-on-form-pages) for what counts as a
form page and the `pkde_load_cf7_assets` opt-in. Source: the plugin author's own method,
<https://contactform7.com/loading-javascript-and-stylesheet-only-when-it-is-necessary/>.

## `content-signal.php` — AI content signals in robots.txt

Adds Cloudflare's `Content-Signal:` line (`search`, `ai-input`, `ai-train`, each `yes`/`no`) inside the
`User-agent: *` group of the virtual robots.txt. It is a declared preference, not enforcement. Set
`CONTENT_SIGNAL` in `.env` (e.g. `search=yes, ai-input=yes, ai-train=no`, which is also the default when blank or
invalid). Skipped on non-public sites (local, staging). It runs at priority 99, after Rank Math's robots.txt editor
(priority 10, which replaces the whole file), so editing robots.txt in Rank Math keeps the line. A physical
`web/robots.txt` file would bypass WordPress and this.

## `post-smtp-env.php` — all Post SMTP settings from .env

Post SMTP stores host, port, encryption and sender in the `postman_options` option; only the login can come from
constants. This overlays `SMTP_HOST`, `SMTP_PORT` (465), `SMTP_ENCRYPTION` (`ssl`/`tls`/`none`, bad value = `ssl`),
`SMTP_FROM` (defaults to `SMTP_USERNAME`) and `SMTP_FROM_NAME` onto that option (plus `prevent_sender_email_override`, so
WordPress and Contact Form 7 cannot swap the From address for `wordpress@host`, which 20i refuses), on both the `option_` and
`default_option_` filters. The plugin itself is required in `composer.json` but left inactive by `bin/setup` (a site activates it at launch). Nothing happens unless `SMTP_HOST` is set, so local Mailpit (`mailpit-smtp.php`, `MAIL_*`)
is untouched. The Post SMTP settings screen shows these values and they always win. Details and the 20i values:
[the launch guide](../../../docs/deployment/20i-launch-guide.md).

## `coming-soon.php` — 503 gate for a site that is not launched

`COMING_SOON=true` in `.env` (defined in `config/application.php`) shows anyone not logged in a 503 page with
`Retry-After` and `X-Robots-Tag: noindex`; off by default, commented in `.env.example`. It renders the theme view
`resources/views/coming-soon.blade.php` if the site has one, else a plain page with the site name and tagline.
Login, wp-admin, admin-ajax, cron and the REST API never reach it; robots.txt, the favicon and the sitemaps are let
through. Remove the variable at launch and flush the page cache. See
[ADR 0001](../../../docs/decisions/template/0001-coming-soon-custom-mu-plugin.md).

## `hide-wp-version.php` — don't advertise the WordPress version

Removes the generator `<meta>` tag (`wp_generator`), blanks the feeds' generator (`the_generator` filter) and strips the
core version from the `?ver=` of core scripts and styles; plugin and theme versions in `?ver=` stay (they cache-bust
their own assets). Hardening only: hiding the number does not replace updating. The pure URL helper has a Pest test
(`tests/Feature/HideWpVersionTest.php`). Not covered: other plugins' own generator tags, e.g. `speculation-rules`
prints `<meta name="generator" content="speculation-rules 1.7.0">`.

## `cf7-test-copy.php` — temporary BCC to the admin for testing a live form

While the option `pkde_cf7_test_until` (a unix timestamp) is in the future, Contact Form 7's `mail` and `mail_2` also
go as a `Bcc` to `admin_email`; the option is deleted after it expires and a wp-admin notice shows while it is on.
Commands (on for an hour, off) are in [`bin/docs/contact-form.md`](../../../bin/docs/contact-form.md#test-copy-on-a-live-site).
The active/Bcc logic is pure and has a Pest test (`tests/Feature/Cf7TestCopyTest.php`).
