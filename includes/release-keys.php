<?php
/**
 * Public keys (base64 Ed25519) trusted to sign plugin updates.
 *
 * Updates are only installed if their signature verifies with one of these
 * keys. The matching secret keys are kept offline by the maintainers, never on
 * GitHub or in CI. To rotate a key, add the new key here, release that version
 * signed with the old key, then remove the old key in a later release.
 *
 * @package WP_AI_Gatekeeper
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

return array(
	// Release key created 2026-11-15; secret key at ~/.config/wp-ai-gatekeeper/release-signing.key (maintainer machine).
	'fn7YENFgtXvsbi/6AyYPSJ6IQMAtcXsdVmNbGKSoPhI=',
);
