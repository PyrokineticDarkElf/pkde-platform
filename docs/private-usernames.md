# Account security: logins vs. author names

Always on, for every account on every site built from this template.

**The problem this solves:** WordPress defaults a new account's *public* `user_nicename` (the
`/author/{slug}/` URL, the REST API's `slug` field, byline links) to `sanitize_title(user_login)`
— derived directly from the *private* login credential. Scrape the front end of an unmodified
WordPress site, and you've recovered half of a valid login/password pair for free — this is
exactly the mechanism behind the classic `?author=1`/`?author=2` username-enumeration attack. The
fix isn't to hide the author's identity (a byline is supposed to be public, and often a client
specifically wants a real name shown) — it's to make sure the public slug is never *derived from*
the private login in the first place. Nicename stays public and human; login stays private and
unpredictable.

**Every new account gets a random login, never a human-typed one** — a memorable word pair plus a
4-digit number (`fighting-charizard-4821`, not a hex string, so it's still relayable over the
phone), generated from `wordlists/*.js` (this same directory). Swap those two files for a
different flavour (adjectives/animals, whatever) with no code change — see the
`PKDE_WORDLIST_FIRST`/`PKDE_WORDLIST_SECOND` constants at the top of `private-usernames.php` for
which file plays which role, since two *separate* lists are combined (one word from each), not
one merged pool drawn from twice — the same word list twice would just produce the same word
paired with itself sometimes, e.g. "charizard-charizard". Format is the same
`export const name = [...]` your CSV-import dictionaries use (see [Team
accounts](../../../../bin/docs/team-accounts.md)), parsed with a regex rather than requiring a JS
runtime.

This applies on **every account-creation path**, each covered differently:

- **wp-admin's "Add New User" screen** — the username field is shown read-only, pre-filled with
  the exact value that will actually be saved (generated once, server-side, when the page renders
  — never a different value silently swapped in at submission; that would be worse than not
  explaining anything). First and last name are both required to create the account at all — a
  nicename can only ever be derived once, at that moment, so this is the one point worth blocking
  on; editing an *existing* profile's name never re-derives its nicename (see below), so the
  requirement isn't repeated there.
- **WP-CLI / the CSV importer** — left alone entirely. Those callers already compute their own
  login and nicename explicitly per account (see [Team
  accounts](../../../../bin/docs/team-accounts.md)), so overriding them here would just fight a
  value chosen on purpose.

**Nicename is deliberately not self-service, and not re-derived on a later name edit.** Once
published, an author's slug is effectively a permalink — changing it breaks author-archive
backlinks and discontinues whatever Google has already associated with that URL (a real SEO/E-E-A-T
cost, not a cosmetic one). It's editable only by an Administrator, or whoever RankMath's own Role
Manager has explicitly granted `rank_math_general` to (confirmed against RankMath's own
`class-capability-manager.php` — a real, already-registered capability, not a guess) — a
non-technical client is never exposed to a control that could quietly break something they don't
understand the consequences of.

## Security note

The word lists and the generator are public. That does not weaken anything this module promises: it keeps the *login* out of author URLs, the REST API and bylines, and it picks names with `random_int`, so an attacker cannot predict one from another. A login name is never treated as a secret; the controls that stop guessing are strong passwords, lockouts and a login bot challenge.
