<?php
/**
 * Subscriber source enum.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Subscribers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Where a subscriber originated.
 */
enum Source: string {

	case NewsletterForm = 'newsletter_form';
	case WooCommerce    = 'woocommerce';
	case Registration   = 'registration';
	case ContactForm    = 'contact_form';
	case Manual         = 'manual';
	case Import         = 'import';
	case Api            = 'api';
	case Webhook        = 'webhook';

	/**
	 * Resolve a source from arbitrary input, defaulting to Manual.
	 *
	 * @param string|null $value Raw source string.
	 */
	public static function fromString( ?string $value ): self {
		return self::tryFrom( (string) $value ) ?? self::Manual;
	}

	/**
	 * Human-readable label.
	 */
	public function label(): string {
		return match ( $this ) {
			self::NewsletterForm => __( 'Newsletter Form', 'mailpilot' ),
			self::WooCommerce    => __( 'WooCommerce', 'mailpilot' ),
			self::Registration   => __( 'Registration', 'mailpilot' ),
			self::ContactForm    => __( 'Contact Form', 'mailpilot' ),
			self::Manual         => __( 'Manual', 'mailpilot' ),
			self::Import         => __( 'Import', 'mailpilot' ),
			self::Api            => __( 'API', 'mailpilot' ),
			self::Webhook        => __( 'Webhook', 'mailpilot' ),
		};
	}
}
