<?php

/*
Plugin Name:  Private Usernames
Description:  Keeps login usernames private and public author identity (nicename) separate from them.
Version:      1.0.0
Author:       pkde
Text Domain:  pkde-text
License:      GPL-2.0-or-later
*/

/**
 * This file lives in a subdirectory (private-usernames/), not directly in
 * mu-plugins/ — WordPress core only auto-loads .php files it finds right in
 * the mu-plugins root, so files one level down are picked up instead by
 * Bedrock's own autoloader (bedrock-autoloader.php), which requires a real
 * WordPress plugin header (the block above) to recognise a file as loadable
 * at all — a file without one is silently skipped, not an error. wordlists/
 * alongside this file are plain data (require()'d directly below), not
 * something the autoloader needs to know about.
 *
 * Keeps the login username private and the public author identity
 * (nicename/slug) separate from it, on every account-creation path.
 *
 * The problem this solves: WordPress defaults a new account's PUBLIC
 * user_nicename (the /author/{slug}/ URL, REST API `slug`, byline links) to
 * sanitize_title(user_login) — the PRIVATE login credential — unless
 * something else sets it explicitly. Scrape the front end, and you've
 * recovered half of a valid login/password pair for free. The fix isn't to
 * hide the author's public identity (a byline is supposed to be public,
 * often a real name a client specifically wants shown) — it's to stop the
 * public slug from ever being *derived from* the private login in the
 * first place.
 *
 * Two account-creation paths are covered, each intentionally scoped
 * differently:
 * - wp-admin's "Add New User" screen (user-new.php) — a human would
 *   otherwise type a predictable login here, so this generates one instead
 *   (pre_user_login below) and requires a real first+last name so a proper
 *   nicename can be derived (pre_user_nicename, user_profile_update_errors).
 * - WP-CLI / bin/import-users.php (the agency's CSV bulk importer) — left
 *   alone. That caller already computes its own login/nicename explicitly
 *   per row (mirroring how it's actually provisioning real accounts), so
 *   overriding it here would just fight a value that was already chosen on
 *   purpose. Both generator functions below are still reused directly by
 *   that importer, just called, not filtered.
 *
 * Nicename is deliberately NOT editable by the account's own owner after
 * creation, and not re-derived on a later name edit — once published, an
 * author's slug is effectively a permalink, and changing it has real SEO/
 * E-E-A-T consequences (broken author-archive backlinks, a discontinuity
 * in whatever Google has already associated with that URL). It's editable
 * only by an Administrator or whoever RankMath's own Role Manager has
 * granted `rank_math_general` to — see pkde_render_nicename_field below.
 */

namespace App;

/**
 * Two *separate*, explicitly-ordered word lists — {first}-{second}-{4-digit
 * number} — not one merged pool drawn from twice (that earlier approach
 * could pair two words from the same list, e.g. two Pokémon names, instead
 * of one of each). Named explicitly rather than picked up via glob() order,
 * so swapping in a different pair (adjectives.js/animals.js, say) is a
 * one-line change here, not a file-renaming puzzle to get the right
 * alphabetical sort.
 */
const PKDE_WORDLIST_FIRST = 'pokemon-type.js';
const PKDE_WORDLIST_SECOND = 'pokemon-gen-1.js';

/**
 * Parses one wordlists/*.js file — same `export const name = [...]` format
 * the Ansible reference tool's own dictionaries use, parsed with a regex
 * rather than a JS runtime (same technique that tool's own Python generator
 * used). Returns [] if the file doesn't exist, so callers can fall back.
 */
function pkde_load_wordlist_file(string $filename): array
{
    $path = __DIR__ . '/wordlists/' . $filename;

    if (! file_exists($path)) {
        return [];
    }

    $contents = file_get_contents($path);

    if (! preg_match('/=\s*(\[[\s\S]*?\])/', $contents, $array_match)) {
        return [];
    }

    preg_match_all('/[\'"]([^\'"]+)[\'"]/', $array_match[1], $string_matches);

    $words = [];
    foreach ($string_matches[1] as $word) {
        $slug = preg_replace('/[^a-z0-9]/', '', strtolower($word));
        if ($slug !== '') {
            $words[] = $slug;
        }
    }

    return array_values(array_unique($words));
}

/**
 * Fallback so account creation still works on a fresh clone before anyone's
 * added PKDE_WORDLIST_FIRST/PKDE_WORDLIST_SECOND to wordlists/.
 */
function pkde_default_first_wordlist(): array
{
    return ['brave', 'calm', 'bold', 'quiet', 'swift', 'clever', 'gentle', 'mighty', 'sunny', 'sharp', 'steady', 'bright', 'quick', 'sturdy', 'keen', 'wise'];
}

function pkde_default_second_wordlist(): array
{
    return ['falcon', 'otter', 'maple', 'harbor', 'ember', 'summit', 'willow', 'comet', 'meadow', 'beacon', 'canyon', 'orchid', 'ridge', 'lantern'];
}

/**
 * A short, memorable, non-guessable login — "fighting-charizard-4821"
 * rather than a hex string, so it's still readable if an agency support
 * person has to relay it over the phone. Retries on an (astronomically
 * unlikely) instance collision; the last-resort fallback only exists so
 * this can never loop forever.
 */
function pkde_generate_username(): string
{
    $first_words = pkde_load_wordlist_file(PKDE_WORDLIST_FIRST) ?: pkde_default_first_wordlist();
    $second_words = pkde_load_wordlist_file(PKDE_WORDLIST_SECOND) ?: pkde_default_second_wordlist();

    for ($attempt = 0; $attempt < 20; $attempt++) {
        $candidate = $first_words[random_int(0, count($first_words) - 1)] . '-' . $second_words[random_int(0, count($second_words) - 1)] . '-' . str_pad((string) random_int(0, 9999), 4, '0', STR_PAD_LEFT);

        if (! username_exists($candidate)) {
            return $candidate;
        }
    }

    return 'user-' . wp_generate_password(10, false, false);
}

/**
 * The public slug: derived from a real first+last name, never from login.
 * Both names are required by design (see the validation below) — the
 * fallback here only ever applies to a caller that bypasses that
 * validation directly (bin/import-users.php on a malformed CSV row), so it
 * stays clearly non-personal rather than silently degrading to something
 * login-shaped.
 */
function pkde_generate_nicename_from_name(string $first_name, string $last_name, int $fallback_id): string
{
    $first_name = trim($first_name);
    $last_name = trim($last_name);

    if ($first_name === '' || $last_name === '') {
        return 'author-' . $fallback_id;
    }

    $slug = sanitize_title(str_replace("'", '', $first_name . ' ' . $last_name));

    return $slug !== '' ? $slug : 'author-' . $fallback_id;
}

/**
 * Only the wp-admin "Add New User" screen needs this — WP-CLI/the CSV
 * importer already supply their own deliberately-chosen login per user.
 * Trusts the submitted value when it's exactly what pkde_render_username_field
 * put there (same generated shape, still free) — see that function's own
 * comment for why the value must never differ from what was shown.
 */
add_filter('pre_user_login', function ($user_login) {
    if (($GLOBALS['pagenow'] ?? '') !== 'user-new.php') {
        return $user_login;
    }

    if (preg_match('/^[a-z]+-[a-z]+-\d{4}$/', (string) $user_login) && ! username_exists($user_login)) {
        return $user_login;
    }

    return pkde_generate_username();
});

/**
 * Same scoping as above: only the admin-UI creation path, where WordPress's
 * own default (before this filter runs) is always sanitize_title(user_login)
 * — never an explicit value, since core exposes no nicename field on this
 * screen at all. user_profile_update_errors (below) already blocks creation
 * here unless both names are present, so they're guaranteed non-empty by
 * the time this runs.
 */
add_filter('pre_user_nicename', function ($user_nicename) {
    if (($GLOBALS['pagenow'] ?? '') !== 'user-new.php') {
        return $user_nicename;
    }

    $first_name = sanitize_text_field(wp_unslash($_POST['first_name'] ?? ''));
    $last_name = sanitize_text_field(wp_unslash($_POST['last_name'] ?? ''));

    return pkde_generate_nicename_from_name($first_name, $last_name, 0);
});

/**
 * Requires a real first+last name on the "Add New User" screen only (see
 * $update below) — a good nicename can only ever be derived once, at the
 * moment this fires for a new account, so this is the one point where it's
 * worth blocking on. Editing an existing profile never re-derives nicename
 * (see the "why not on edit" comment on the render/save functions below),
 * so requiring names there again would enforce a precondition that no
 * longer protects anything.
 */
add_action('user_profile_update_errors', function ($errors, $update, $user) {
    if ($update) {
        return;
    }

    if (empty($_POST['first_name']) || empty($_POST['last_name'])) {
        $errors->add(
            'pkde_name_required',
            sprintf(
                '<strong>%1$s</strong>: %2$s',
                esc_html__('Error', 'pkde-text'),
                esc_html__('First and last name are both required — the public author slug is generated from them once, at creation.', 'pkde-text'),
            ),
            ['form-field' => 'first_name'],
        );
    }
}, 10, 3);

/**
 * Shows the generated login on the "Add New User" screen as read-only,
 * pre-filled with the exact same value pre_user_login above will actually
 * save — generated once, here, not regenerated separately at submission.
 * Showing one value and silently saving a different one would be worse
 * than not explaining anything at all; the round-tripped form field is
 * what keeps them identical in the normal case (pre_user_login only
 * diverges from it if that round-trip didn't happen — JS disabled, or the
 * readonly attribute bypassed client-side).
 */
add_action('user_new_form', function ($operation) {
    if ($operation !== 'add-new-user') {
        return;
    }

    $suggested_login = pkde_generate_username();
    ?>
    <script>
    (function () {
        var field = document.getElementById('user_login');
        if (! field) {
            return;
        }
        field.value = <?php echo wp_json_encode($suggested_login); ?>;
        field.readOnly = true;
        field.setAttribute('aria-readonly', 'true');
        var note = document.createElement('p');
        note.className = 'description';
        note.textContent = <?php echo wp_json_encode(__('Generated automatically — logins stay private and unrelated to the public author name.', 'pkde-text')); ?>;
        field.insertAdjacentElement('afterend', note);
    })();
    </script>
    <?php
});

/**
 * Only an Administrator, or whoever RankMath's own Role Manager has
 * explicitly granted `rank_math_general` to, can see or change an existing
 * user's nicename — see this file's top comment for why it isn't
 * self-service. Guarded on $user->ID existing so it never shows on the
 * "Add New User" screen (that account doesn't exist yet to have a nicename
 * to edit).
 */
function pkde_current_user_can_manage_nicename(): bool
{
    return current_user_can('manage_options') || current_user_can('rank_math_general');
}

function pkde_render_nicename_field($user)
{
    if (! isset($user->ID) || ! pkde_current_user_can_manage_nicename()) {
        return;
    }
    ?>
    <h2><?php esc_html_e('Author URL', 'pkde-text'); ?></h2>
    <table class="form-table">
        <tr class="user-user-nicename-wrap">
            <th><label for="pkde_user_nicename"><?php esc_html_e('Nicename', 'pkde-text'); ?></label></th>
            <td>
                <input
                    type="text"
                    name="pkde_user_nicename"
                    id="pkde_user_nicename"
                    value="<?php echo esc_attr($user->user_nicename); ?>"
                    class="regular-text"
                />
                <p class="description">
                    <?php esc_html_e('Used in this author\'s public archive URL and byline links. Changing it after the site has been live changes that URL — treat it as a one-time setting, not a routine edit.', 'pkde-text'); ?>
                </p>
            </td>
        </tr>
    </table>
    <?php
}
add_action('show_user_profile', __NAMESPACE__ . '\\pkde_render_nicename_field', 99);
add_action('edit_user_profile', __NAMESPACE__ . '\\pkde_render_nicename_field', 99);

/**
 * The one thing the original snippet this replaced got wrong: WordPress
 * core's own edit_user() (wp-admin/includes/user.php) never reads
 * user_nicename from $_POST at all — a plain `add_action` hook that relies
 * on core to "just save it" does nothing. wp_update_user() must be called
 * explicitly, which also gets collision handling for free (it shares
 * wp_insert_user()'s own uniqueness check + auto-suffixing).
 */
function pkde_save_nicename_field($user_id)
{
    if (! pkde_current_user_can_manage_nicename() || ! current_user_can('edit_user', $user_id)) {
        return;
    }

    if (! isset($_POST['pkde_user_nicename'])) {
        return;
    }

    $nicename = sanitize_title(wp_unslash($_POST['pkde_user_nicename']));

    if ($nicename === '') {
        return;
    }

    wp_update_user([
        'ID' => $user_id,
        'user_nicename' => $nicename,
    ]);
}
add_action('personal_options_update', __NAMESPACE__ . '\\pkde_save_nicename_field');
add_action('edit_user_profile_update', __NAMESPACE__ . '\\pkde_save_nicename_field');
