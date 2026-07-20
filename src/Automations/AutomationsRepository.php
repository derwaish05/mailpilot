<?php
/**
 * Storage for webhooks and automation rules.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Automations;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reads/writes the automations config (outgoing/incoming webhooks and IF/THEN
 * rules) as two option arrays, with structural sanitisation.
 */
final class AutomationsRepository {

	private const WEBHOOKS   = 'mailpilot_webhooks';
	private const AUTOMATIONS = 'mailpilot_automations';

	/**
	 * All webhooks.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function webhooks(): array {
		$stored = get_option( self::WEBHOOKS, [] );

		return is_array( $stored ) ? array_values( $stored ) : [];
	}

	/**
	 * All automation rules.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function automations(): array {
		$stored = get_option( self::AUTOMATIONS, [] );

		return is_array( $stored ) ? array_values( $stored ) : [];
	}

	/**
	 * Replace all webhooks with a sanitised copy of the input.
	 *
	 * @param array<int, mixed> $webhooks Raw webhooks.
	 */
	public function save_webhooks( array $webhooks ): void {
		$clean = [];
		foreach ( $webhooks as $webhook ) {
			if ( ! is_array( $webhook ) ) {
				continue;
			}
			$direction = 'incoming' === ( $webhook['direction'] ?? '' ) ? 'incoming' : 'outgoing';
			$url       = esc_url_raw( (string) ( $webhook['url'] ?? '' ) );
			if ( 'outgoing' === $direction && '' === $url ) {
				continue;
			}
			$clean[] = [
				'id'        => (int) ( $webhook['id'] ?? 0 ) ?: random_int( 1000, PHP_INT_MAX ),
				'direction' => $direction,
				'event'     => sanitize_key( (string) ( $webhook['event'] ?? 'subscriber_created' ) ),
				'url'       => $url,
				'secret'    => $this->safe_secret( (string) ( $webhook['secret'] ?? '' ) ),
			];
		}

		update_option( self::WEBHOOKS, array_values( $clean ), false );
	}

	/**
	 * Replace all automation rules with a sanitised copy of the input.
	 *
	 * @param array<int, mixed> $automations Raw rules.
	 */
	public function save_automations( array $automations ): void {
		$clean = [];
		foreach ( $automations as $rule ) {
			if ( ! is_array( $rule ) ) {
				continue;
			}
			$clean[] = [
				'id'          => (int) ( $rule['id'] ?? 0 ) ?: random_int( 1000, PHP_INT_MAX ),
				'title'       => sanitize_text_field( (string) ( $rule['title'] ?? '' ) ),
				'trigger'     => sanitize_key( (string) ( $rule['trigger'] ?? 'created' ) ),
				'field'       => sanitize_key( (string) ( $rule['field'] ?? 'always' ) ),
				'value'       => sanitize_text_field( (string) ( $rule['value'] ?? '' ) ),
				'metaKey'     => sanitize_key( (string) ( $rule['metaKey'] ?? '' ) ),
				'action'      => sanitize_key( (string) ( $rule['action'] ?? 'add_tag' ) ),
				'actionValue' => sanitize_text_field( (string) ( $rule['actionValue'] ?? '' ) ),
				'active'      => ! empty( $rule['active'] ),
			];
		}

		update_option( self::AUTOMATIONS, array_values( $clean ), false );
	}

	/**
	 * Find an incoming webhook by its secret.
	 *
	 * @param string $secret Secret token.
	 * @return array<string, mixed>|null
	 */
	public function incoming_by_secret( string $secret ): ?array {
		$secret = trim( $secret );
		if ( '' === $secret ) {
			return null;
		}
		foreach ( $this->webhooks() as $webhook ) {
			if ( 'incoming' === ( $webhook['direction'] ?? '' ) && hash_equals( (string) ( $webhook['secret'] ?? '' ), $secret ) ) {
				return $webhook;
			}
		}

		return null;
	}

	/**
	 * Keep a valid-looking secret, or mint a fresh one.
	 *
	 * @param string $secret Submitted secret.
	 */
	private function safe_secret( string $secret ): string {
		$secret = preg_replace( '/[^a-zA-Z0-9_]/', '', $secret ) ?? '';

		return '' !== $secret ? $secret : 'whsec_' . wp_generate_password( 20, false );
	}
}
