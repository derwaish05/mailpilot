<?php
/**
 * Free-tier sync-operation usage metering.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Licensing;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Meters provider-sync operations against the Free-tier monthly cap (ADR-006:
 * 5,000 sync ops/month on Free; Pro and Agency are unlimited).
 *
 * `License` stays the single source of truth for *tier* — this class only
 * counts and enforces on top of it, so tier logic itself is never duplicated
 * (Task 1.9 acceptance: "tier logic is not re-implemented anywhere outside
 * the core helper").
 */
final class UsageMeter {

	/**
	 * Option name holding the current period's counter.
	 */
	private const OPTION = 'mailpilot_usage_sync_ops';

	/**
	 * Free-tier monthly operation cap.
	 */
	public const FREE_MONTHLY_CAP = 5000;

	public function __construct( private License $license ) {}

	/**
	 * The monthly period key (UTC) a given timestamp falls in, e.g. "2026-07".
	 *
	 * Pure function — no WordPress dependency — so the period-rollover logic
	 * is unit-testable without stubs.
	 *
	 * @param int $timestamp Unix timestamp.
	 */
	public static function period_for( int $timestamp ): string {
		return gmdate( 'Y-m', $timestamp );
	}

	/**
	 * Sync operations counted so far in the current period.
	 */
	public function used(): int {
		return $this->state()['count'];
	}

	/**
	 * The operation cap for the active tier, or null when unlimited (Pro/Agency).
	 */
	public function cap(): ?int {
		return $this->license->is_pro() ? null : self::FREE_MONTHLY_CAP;
	}

	/**
	 * Operations remaining in the current period, or null when unlimited.
	 */
	public function remaining(): ?int {
		$cap = $this->cap();

		return null === $cap ? null : max( 0, $cap - $this->used() );
	}

	/**
	 * Whether another sync operation may run right now.
	 */
	public function has_capacity(): bool {
		$cap = $this->cap();

		return null === $cap || $this->used() < $cap;
	}

	/**
	 * Record one or more sync operations against the current period.
	 *
	 * Resets the counter first if the stored period has rolled over, so the
	 * cap is enforced per calendar month with no separate cron reset needed.
	 *
	 * @param int $by Number of operations to add (default 1).
	 */
	public function increment( int $by = 1 ): void {
		$state            = $this->state();
		$state['count']  += max( 0, $by );

		update_option( self::OPTION, $state, false );
	}

	/**
	 * The persisted counter state for the current period.
	 *
	 * @return array{period: string, count: int}
	 */
	private function state(): array {
		$period = self::period_for( time() );
		$stored = get_option( self::OPTION, [] );
		$stored = is_array( $stored ) ? $stored : [];

		if ( ( $stored['period'] ?? '' ) !== $period ) {
			return [ 'period' => $period, 'count' => 0 ];
		}

		return [ 'period' => $period, 'count' => (int) ( $stored['count'] ?? 0 ) ];
	}
}
