<?php
/**
 * Kit (ConvertKit) provider adapter.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Kit (formerly ConvertKit) API v4 adapter. Auth: an `X-Kit-Api-Key` header.
 * Kit has first-class tags; "lists" map to Kit tags.
 */
final class Kit extends AbstractProvider {

	private const BASE = 'https://api.kit.com/v4';

	public function id(): string {
		return 'kit';
	}

	public function label(): string {
		return 'Kit';
	}

	public function capabilities(): Capabilities {
		return new Capabilities(
			api_key_auth: true,
			list_selection: true,
			tag_selection: true,
			group_selection: false,
			double_opt_in: true,
			custom_field_mapping: true,
		);
	}

	public function guide_url(): string {
		return 'https://app.kit.com/account_settings/developer_settings';
	}

	public function list_label(): string {
		return __( 'Form', 'brainstudioz-mailpilot' );
	}

	public function create_contact( Contact $contact, ProviderConnection $connection ): SyncResult {
		return $this->upsert( $contact, $connection );
	}

	public function update_contact( Contact $contact, ProviderConnection $connection ): SyncResult {
		return $this->upsert( $contact, $connection );
	}

	public function delete_contact( string $email, ProviderConnection $connection ): SyncResult {
		// Kit unsubscribes rather than hard-deletes by email.
		return $this->request(
			'POST',
			self::BASE . '/subscribers/' . rawurlencode( $email ) . '/unsubscribe',
			[],
			$this->auth( $connection )
		);
	}

	public function apply_tags( string $email, array $tags, ProviderConnection $connection ): SyncResult {
		return $this->for_each_tag( $tags, function ( string $tag ) use ( $email, $connection ): SyncResult {
			return $this->request(
				'POST',
				self::BASE . '/tags/' . rawurlencode( $tag ) . '/subscribers',
				[ 'email_address' => $email ],
				$this->auth( $connection )
			);
		} );
	}

	public function remove_tags( string $email, array $tags, ProviderConnection $connection ): SyncResult {
		return $this->for_each_tag( $tags, function ( string $tag ) use ( $email, $connection ): SyncResult {
			return $this->request(
				'DELETE',
				self::BASE . '/tags/' . rawurlencode( $tag ) . '/subscribers/' . rawurlencode( $email ),
				[],
				$this->auth( $connection )
			);
		} );
	}

	/**
	 * Run a per-tag API call across every tag and aggregate the outcome.
	 *
	 * Kit has no bulk tag endpoint, so each tag needs its own request. This
	 * used to loop and unconditionally return success afterward, which masked
	 * real per-tag API failures from the sync log. Now: any transient failure
	 * makes the aggregate transient (worth a queue retry); any permanent
	 * failure with none transient makes it a failure; it only reports success
	 * if every tag call actually succeeded.
	 *
	 * @param array<int, string>            $tags Tags to process.
	 * @param callable(string): SyncResult  $call Per-tag API call.
	 */
	private function for_each_tag( array $tags, callable $call ): SyncResult {
		if ( ! $tags ) {
			return SyncResult::success();
		}

		$messages      = [];
		$any_failed    = false;
		$any_transient = false;

		foreach ( $tags as $tag ) {
			$result = $call( (string) $tag );

			if ( ! $result->success ) {
				$any_failed     = true;
				$any_transient  = $any_transient || $result->retryable;
				$messages[]     = $tag . ': ' . $result->message;
			}
		}

		if ( ! $any_failed ) {
			return SyncResult::success();
		}

		$message = implode( '; ', $messages );

		return $any_transient ? SyncResult::transient( $message ) : SyncResult::failure( $message );
	}

	public function get_lists( ProviderConnection $connection ): array {
		$result = $this->request( 'GET', self::BASE . '/tags', [], $this->auth( $connection ) );
		if ( ! $result->success ) {
			$this->list_error = $result->message;
			return [];
		}

		$lists = [];
		foreach ( (array) ( $result->data['tags'] ?? [] ) as $tag ) {
			$lists[] = [ 'id' => (string) ( $tag['id'] ?? '' ), 'name' => (string) ( $tag['name'] ?? '' ) ];
		}

		return $lists;
	}

	/**
	 * Upsert a subscriber, then apply configured tags.
	 *
	 * @param Contact            $contact    Contact.
	 * @param ProviderConnection $connection Connection.
	 */
	private function upsert( Contact $contact, ProviderConnection $connection ): SyncResult {
		$fields = [];
		foreach ( $contact->fields as $key => $value ) {
			$fields[ $connection->field_map[ $key ] ?? (string) $key ] = $value;
		}

		$body = array_filter(
			[
				'email_address' => $contact->email,
				'first_name'    => $contact->first_name,
				'fields'        => $fields ?: null,
			],
			static fn ( $v ): bool => null !== $v && '' !== $v
		);

		$result = $this->request( 'POST', self::BASE . '/subscribers', $body, $this->auth( $connection ) );

		// Kit "lists" are tags; subscribe to configured ones.
		if ( $result->success && $connection->lists() ) {
			$this->apply_tags( $contact->email, $connection->lists(), $connection );
		}

		return $result;
	}

	/**
	 * Auth header.
	 *
	 * @param ProviderConnection $connection Connection.
	 * @return array<string, string>
	 */
	private function auth( ProviderConnection $connection ): array {
		return [
			'X-Kit-Api-Key' => $connection->credential( 'api_key' ),
			'Accept'        => 'application/json',
		];
	}
}
