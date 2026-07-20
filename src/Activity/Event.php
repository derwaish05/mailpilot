<?php
/**
 * Activity event-type enum.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Activity;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The activity event types recorded in `wp_mailpilot_activity_log`.
 *
 * @see doc/06-technical-spec.md
 */
enum Event: string {

	case FormSubmission   = 'form_submission';
	case SubscriberCreated = 'subscriber_created';
	case SubscriberUpdated = 'subscriber_updated';
	case StatusChanged    = 'status_changed';
	case TagAdded         = 'tag_added';
	case TagRemoved       = 'tag_removed';
	case ProviderSynced   = 'provider_synced';
	case OrderCreated     = 'order_created';
	case OrderCompleted   = 'order_completed';
	case PurchasedProduct = 'purchased_product';
	case RefundedProduct  = 'refunded_product';

	/**
	 * Human-readable label.
	 */
	public function label(): string {
		return match ( $this ) {
			self::FormSubmission    => __( 'Form Submission', 'mailpilot' ),
			self::SubscriberCreated => __( 'Subscriber Created', 'mailpilot' ),
			self::SubscriberUpdated => __( 'Subscriber Updated', 'mailpilot' ),
			self::StatusChanged     => __( 'Status Changed', 'mailpilot' ),
			self::TagAdded          => __( 'Tag Added', 'mailpilot' ),
			self::TagRemoved        => __( 'Tag Removed', 'mailpilot' ),
			self::ProviderSynced    => __( 'Provider Synced', 'mailpilot' ),
			self::OrderCreated      => __( 'Order Created', 'mailpilot' ),
			self::OrderCompleted    => __( 'Order Completed', 'mailpilot' ),
			self::PurchasedProduct  => __( 'Purchased Product', 'mailpilot' ),
			self::RefundedProduct   => __( 'Refunded Product', 'mailpilot' ),
		};
	}
}
