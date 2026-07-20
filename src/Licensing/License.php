<?php
/**
 * License tier reporting.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Licensing;

use MailPilot\Settings\Settings;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reports the active license tier.
 *
 * The free plugin is fully functional on its own and gates nothing — this class
 * only *reports* a tier so the dashboard can display it and so add-ons can light
 * up their own additional features. The tier is resolved through the
 * `mailpilot_license_tier` filter, which a paid add-on may supply; with no add-on
 * present the tier is always Free.
 */
final class License {

	public const TIER_FREE   = 'free';
	public const TIER_PRO    = 'pro';
	public const TIER_AGENCY = 'agency';

	public function __construct( private Settings $settings ) {}

	/**
	 * The active tier (free/pro/agency), as reported by add-ons via filter.
	 */
	public function tier(): string {
		$tier = self::TIER_FREE;

		/**
		 * Filter the reported license tier.
		 *
		 * Add-ons (e.g. MailPilot Pro) return the tier their license resolves to.
		 * The core plugin itself never sets anything but Free here.
		 *
		 * @param string  $tier    Reported tier.
		 * @param License $license License instance.
		 */
		$tier = (string) apply_filters( 'mailpilot_license_tier', $tier, $this );

		return in_array( $tier, [ self::TIER_FREE, self::TIER_PRO, self::TIER_AGENCY ], true )
			? $tier
			: self::TIER_FREE;
	}

	/**
	 * Whether the reported tier is at least Pro.
	 */
	public function is_pro(): bool {
		return in_array( $this->tier(), [ self::TIER_PRO, self::TIER_AGENCY ], true );
	}

	/**
	 * Whether the reported tier is Agency.
	 */
	public function is_agency(): bool {
		return self::TIER_AGENCY === $this->tier();
	}

	/**
	 * Extension point for add-ons to gate *their own* features.
	 *
	 * The core plugin withholds nothing, so this returns true unless an add-on
	 * hooks `mailpilot_can_use_feature` to gate a feature it ships. Nothing in the
	 * free plugin is locked behind this check.
	 *
	 * @param string $feature Feature slug.
	 */
	public function can( string $feature ): bool {
		/**
		 * Filter whether an add-on feature is available.
		 *
		 * @param bool    $allowed Whether allowed (true by default — core gates nothing).
		 * @param string  $feature Feature slug.
		 * @param License $license License instance.
		 */
		return (bool) apply_filters( 'mailpilot_can_use_feature', true, $feature, $this );
	}
}
