<?php

/**
 * Plugin Name: Mail
 * Description: Everything about where the site's mail goes: Mailpit on a local stack (mailpit-smtp), the local and staging guards that stop a non-production site mailing real people (local-mail-guard, staging-mail-guard) and the SMTP settings read from the environment (post-smtp-env).
 *              Switch it off with PKDE_PLATFORM_DISABLE=mail, or one part at a time by the part's own name (the names these parts had before they were merged).
 */

namespace Pkde\Platform;

load_parts('mail');
