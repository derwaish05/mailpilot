<?php
/**
 * Pure classification of form display types by required behaviour.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Forms;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Classifies a form's `display_type` by what it takes to actually deliver it,
 * independent of whether MailPilot Pro is active. Kept free of WordPress
 * calls so {@see FormRenderer} can decide *what* to render without this
 * classification logic needing WP stubs to unit test.
 *
 * - "Pro-triggered" types (popup, full screen) fundamentally need a trigger
 *   (time delay, scroll, exit intent…) to decide *when* to show — without
 *   one they'd have to appear immediately and block the page, or never
 *   appear at all. That trigger/frequency/A-B engine is MailPilot Pro's
 *   Popups & Lead Capture module (Task 3.8).
 * - "Free-positioned" types (floating bar, slide in) are just fixed
 *   placement — no trigger required to make sense as "always visible until
 *   dismissed" — so Free can render these for real instead of falling back
 *   to a plain inline form.
 */
final class DisplayTypeMode {

	/**
	 * @var array<int, string>
	 */
	private const PRO_TRIGGERED_TYPES = [ 'popup', 'full_screen' ];

	/**
	 * @var array<int, string>
	 */
	private const FREE_POSITIONED_TYPES = [ 'floating_bar', 'slide_in' ];

	/**
	 * Whether this display type needs a trigger engine to make sense — i.e.
	 * is only meaningfully deliverable by MailPilot Pro's Popups module.
	 *
	 * @param string $display_type A form's `display_type`.
	 */
	public static function needs_pro_trigger( string $display_type ): bool {
		return in_array( $display_type, self::PRO_TRIGGERED_TYPES, true );
	}

	/**
	 * Whether Free can render this display type with real fixed positioning
	 * (no trigger needed) rather than a plain inline fallback.
	 *
	 * @param string $display_type A form's `display_type`.
	 */
	public static function gets_free_fallback_position( string $display_type ): bool {
		return in_array( $display_type, self::FREE_POSITIONED_TYPES, true );
	}
}
