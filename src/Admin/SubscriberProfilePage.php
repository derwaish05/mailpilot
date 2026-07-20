<?php
/**
 * Subscriber profile screen.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Admin;

use MailPilot\Activity\Event;
use MailPilot\Plugin;
use MailPilot\Sync\SyncLog;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Renders a single subscriber: personal info, provider connections, and the
 * activity timeline. Data is read from the local tables in a bounded number of
 * queries (no per-row N+1).
 */
final class SubscriberProfilePage {

	public function __construct( private Plugin $plugin ) {}

	/**
	 * Render the profile for a subscriber id.
	 *
	 * @param int $id Subscriber id.
	 */
	public function render( int $id ): void {
		$subscriber = $this->plugin->subscriber_repository()->find( $id );

		if ( null === $subscriber ) {
			echo '<div class="wrap"><h1>' . esc_html__( 'Subscriber not found', 'mailpilot' ) . '</h1></div>';

			return;
		}

		$tags     = $this->plugin->relationships()->tags_for( $id );
		$timeline = $this->plugin->activity()->timeline( $id );
		$syncs    = ( new SyncLog() )->for_subscriber( $id );

		$back = add_query_arg( [ 'page' => AdminMenu::SLUG . '-subscribers' ], admin_url( 'admin.php' ) );

		echo '<div class="wrap">';
		printf(
			'<h1 class="wp-heading-inline">%s</h1> <a href="%s" class="page-title-action">%s</a><hr class="wp-header-end" />',
			esc_html( $subscriber->display_name() ),
			esc_url( $back ),
			esc_html__( 'Back to list', 'mailpilot' )
		);

		echo '<div id="poststuff"><div id="post-body" class="metabox-holder columns-2"><div id="post-body-content">';

		// Personal information.
		$this->card(
			__( 'Personal Information', 'mailpilot' ),
			function () use ( $subscriber, $tags ): void {
				echo '<table class="widefat striped"><tbody>';
				$this->row( __( 'Email', 'mailpilot' ), $subscriber->email );
				$this->row( __( 'Name', 'mailpilot' ), $subscriber->display_name() );
				$this->row( __( 'Phone', 'mailpilot' ), (string) $subscriber->phone );
				$this->row( __( 'Company', 'mailpilot' ), (string) $subscriber->company );
				$this->row( __( 'Country', 'mailpilot' ), (string) $subscriber->country );
				$this->row( __( 'Status', 'mailpilot' ), $subscriber->status->label() );
				$this->row( __( 'Source', 'mailpilot' ), $subscriber->source->label() );
				$this->row( __( 'Tags', 'mailpilot' ), implode( ', ', $tags ) );
				$this->row( __( 'Created', 'mailpilot' ), (string) $subscriber->created_at );
				echo '</tbody></table>';
			}
		);

		// Activity timeline.
		$this->card(
			__( 'Activity Timeline', 'mailpilot' ),
			function () use ( $timeline ): void {
				if ( ! $timeline ) {
					echo '<p>' . esc_html__( 'No activity yet.', 'mailpilot' ) . '</p>';

					return;
				}

				echo '<table class="widefat striped"><thead><tr>';
				echo '<th>' . esc_html__( 'When', 'mailpilot' ) . '</th><th>' . esc_html__( 'Event', 'mailpilot' ) . '</th><th>' . esc_html__( 'Detail', 'mailpilot' ) . '</th>';
				echo '</tr></thead><tbody>';
				foreach ( $timeline as $entry ) {
					$event = Event::tryFrom( (string) $entry->event_type );
					printf(
						'<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
						esc_html( (string) $entry->created_at ),
						esc_html( $event ? $event->label() : (string) $entry->event_type ),
						esc_html( (string) $entry->description )
					);
				}
				echo '</tbody></table>';
			}
		);

		echo '</div><div id="postbox-container-1" class="postbox-container">';

		// Provider sync connections.
		$this->card(
			__( 'Provider Sync', 'mailpilot' ),
			function () use ( $syncs ): void {
				if ( ! $syncs ) {
					echo '<p>' . esc_html__( 'Not synced to any provider yet.', 'mailpilot' ) . '</p>';

					return;
				}

				echo '<table class="widefat striped"><thead><tr>';
				echo '<th>' . esc_html__( 'Provider', 'mailpilot' ) . '</th><th>' . esc_html__( 'Action', 'mailpilot' ) . '</th><th>' . esc_html__( 'Status', 'mailpilot' ) . '</th>';
				echo '</tr></thead><tbody>';
				foreach ( $syncs as $sync ) {
					printf(
						'<tr><td>%s</td><td>%s</td><td>%s</td></tr>',
						esc_html( (string) $sync->provider ),
						esc_html( (string) $sync->action ),
						esc_html( (string) $sync->status )
					);
				}
				echo '</tbody></table>';
			}
		);

		/**
		 * Fires in the profile sidebar so Pro can add orders/memberships panels.
		 *
		 * @param int $id Subscriber id.
		 */
		do_action( 'mailpilot_subscriber_profile_sidebar', $id );

		echo '</div></div></div></div>';
	}

	/**
	 * Render a postbox card.
	 *
	 * @param string   $title Card title.
	 * @param callable $body  Body renderer.
	 */
	private function card( string $title, callable $body ): void {
		echo '<div class="postbox"><h2 class="hndle" style="padding:8px 12px">' . esc_html( $title ) . '</h2><div class="inside">';
		$body();
		echo '</div></div>';
	}

	/**
	 * Render a label/value table row.
	 *
	 * @param string $label Label.
	 * @param string $value Value.
	 */
	private function row( string $label, string $value ): void {
		printf( '<tr><th style="width:140px">%s</th><td>%s</td></tr>', esc_html( $label ), esc_html( $value ) );
	}
}
