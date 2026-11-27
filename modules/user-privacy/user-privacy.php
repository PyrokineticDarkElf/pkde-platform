<?php

/**
 * Plugin Name: User privacy
 * Description: Keeps people's names out of public view: private-usernames (generated login names), author-archives (no author pages that list a login) and rest-users-private (the REST users routes need a login).
 *              Switch it off with PKDE_PLATFORM_DISABLE=user-privacy, or one part at a time by the part's own name (the names these parts had before they were merged).
 */

namespace Pkde\Platform;

load_parts('user-privacy');
