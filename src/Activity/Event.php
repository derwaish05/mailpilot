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
			self::FormSubmission    => __( 'Form Submission', 'brainstudioz-mailpilot' ),
			self::SubscriberCreated => __( 'Subscriber Created', 'brainstudioz-mailpilot' ),
			self::SubscriberUpdated => __( 'Subscriber Updated', 'brainstudioz-mailpilot' ),
			self::StatusChanged     => __( 'Status Changed', 'brainstudioz-mailpilot' ),
			self::TagAdded          => __( 'Tag Added', 'brainstudioz-mailpilot' ),
			self::TagRemoved        => __( 'Tag Removed', 'brainstudioz-mailpilot' ),
			self::ProviderSynced    => __( 'Provider Synced', 'brainstudioz-mailpilot' ),
			self::OrderCreated      => __( 'Order Created', 'brainstudioz-mailpilot' ),
			self::OrderCompleted    => __( 'Order Completed', 'brainstudioz-mailpilot' ),
			self::PurchasedProduct  => __( 'Purchased Product', 'brainstudioz-mailpilot' ),
			self::RefundedProduct   => __( 'Refunded Product', 'brainstudioz-mailpilot' ),
		};
	}
}
