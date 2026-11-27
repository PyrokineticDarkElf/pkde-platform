<?php

/**
 * Plugin Name: Schema
 * Description: Structured data: the LocalBusiness node (local-business-schema), absolute URLs in schema (schema-absolute-urls) and the product offer and shipping details (product-schema). Business details come from Site details, which feeds Rank Math's settings: this module reads those, so Site details is the one source.
 *              Switch it off with PKDE_PLATFORM_DISABLE=schema, or one part at a time by the part's own name (the names these parts had before they were merged).
 */

namespace Pkde\Platform;

load_parts('schema');
