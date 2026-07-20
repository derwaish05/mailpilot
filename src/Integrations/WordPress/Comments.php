<?php
/**
 * WordPress comments capture.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Integrations\WordPress;

use MailPilot\Integrations\AbstractIntegration;
use MailPilot\Subscribers\Source;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Captures commenters as subscribers when a comment is posted.
 */
final class Comments extends AbstractIntegration {

	public function id(): string {
		return 'wp_comments';
	}

	public function label(): string {
		return __( 'WordPress Comments', 'mailpilot' );
	}

	public function is_available(): bool {
		return true; // Core feature, always present.
	}

	protected function source(): string {
		return Source::ContactForm->value;
	}

	public function register(): void {
		add_action( 'comment_post', [ $this, 'on_comment' ], 10, 1 );
	}

	/**
	 * Capture a commenter.
	 *
	 * @param int $comment_id Comment id.
	 */
	public function on_comment( int $comment_id ): void {
		$comment = get_comment( $comment_id );
		if ( ! $comment || '' === (string) $comment->comment_author_email ) {
			return;
		}

		$name  = trim( (string) $comment->comment_author );
		$parts = '' !== $name ? preg_split( '/\s+/', $name, 2 ) : [];

		$this->subscribe(
			(string) $comment->comment_author_email,
			[
				'first_name' => $parts[0] ?? '',
				'last_name'  => $parts[1] ?? '',
			]
		);
	}
}
