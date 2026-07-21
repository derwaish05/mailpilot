<?php
/**
 * Drip provider adapter.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Drip API v2 adapter. Auth: HTTP Basic with the API key as username. Requires
 * an account id. Drip has first-class tags.
 */
final class Drip extends AbstractProvider {

	private const BASE = 'https://api.getdrip.com/v2';

	public function id(): string {
		return 'drip';
	}

	public function label(): string {
		return 'Drip';
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

	public function credential_fields(): array {
		return [
			$this->credential_field( 'api_key', __( 'API Key', 'brainstudioz-mailpilot' ), true, __( 'API token', 'brainstudioz-mailpilot' ) ),
			$this->credential_field( 'account_id', __( 'Account ID', 'brainstudioz-mailpilot' ), true ),
		];
	}

	public function guide_url(): string {
		return 'https://www.getdrip.com/docs/rest-api';
	}

	public function list_label(): string {
		// Drip has no "list" concept — an account has one subscriber pool.
		// The closest equivalent audience container it exposes is a
		// campaign (an email sequence you can enroll a subscriber into),
		// which `get_lists()` already fetched but was previously unused
		// because this capability was off.
		return __( 'Campaign', 'brainstudioz-mailpilot' );
	}

	public function create_contact( Contact $contact, ProviderConnection $connection ): SyncResult {
		return $this->upsert( $contact, $connection );
	}

	public function update_contact( Contact $contact, ProviderConnection $connection ): SyncResult {
		return $this->upsert( $contact, $connection );
	}

	public function delete_contact( string $email, ProviderConnection $connection ): SyncResult {
		$account = $connection->credential( 'account_id' );

		return $this->request( 'DELETE', self::BASE . "/{$account}/subscribers/" . rawurlencode( $email ), [], $this->auth( $connection ) );
	}

	public function apply_tags( string $email, array $tags, ProviderConnection $connection ): SyncResult {
		$account = $connection->credential( 'account_id' );

		return $this->for_each_tag( $tags, function ( string $tag ) use ( $email, $account, $connection ): SyncResult {
			return $this->request(
				'POST',
				self::BASE . "/{$account}/tags",
				[ 'tags' => [ [ 'email' => $email, 'tag' => $tag ] ] ],
				$this->auth( $connection )
			);
		} );
	}

	public function remove_tags( string $email, array $tags, ProviderConnection $connection ): SyncResult {
		$account = $connection->credential( 'account_id' );

		return $this->for_each_tag( $tags, function ( string $tag ) use ( $email, $account, $connection ): SyncResult {
			return $this->request(
				'DELETE',
				self::BASE . "/{$account}/subscribers/" . rawurlencode( $email ) . '/tags/' . rawurlencode( $tag ),
				[],
				$this->auth( $connection )
			);
		} );
	}

	/**
	 * Run a per-tag API call across every tag and aggregate the outcome.
	 *
	 * Drip has no bulk tag endpoint, so each tag needs its own request. This
	 * used to loop and unconditionally return success afterward, which masked
	 * real per-tag API failures from the sync log.
	 *
	 * @param array<int, string>           $tags Tags to process.
	 * @param callable(string): SyncResult $call Per-tag API call.
	 */
	private function for_each_tag( array $tags, callable $call ): SyncResult {
		if ( ! $tags ) {
			return SyncResult::success();
		}

		$errors        = [];
		$any_transient = false;

		foreach ( $tags as $tag ) {
			$result = $call( (string) $tag );

			if ( ! $result->success ) {
				$any_transient = $any_transient || $result->retryable;
				$errors[]      = $tag . ': ' . $result->message;
			}
		}

		if ( ! $errors ) {
			return SyncResult::success();
		}

		$message = implode( '; ', $errors );

		return $any_transient ? SyncResult::transient( $message ) : SyncResult::failure( $message );
	}

	public function get_lists( ProviderConnection $connection ): array {
		$account = $connection->credential( 'account_id' );
		$result  = $this->request( 'GET', self::BASE . "/{$account}/campaigns?status=active", [], $this->auth( $connection ) );
		if ( ! $result->success ) {
			$this->list_error = $result->message;
			return [];
		}

		$lists = [];
		foreach ( (array) ( $result->data['campaigns'] ?? [] ) as $campaign ) {
			$lists[] = [ 'id' => (string) ( $campaign['id'] ?? '' ), 'name' => (string) ( $campaign['name'] ?? '' ) ];
		}

		return $lists;
	}

	/**
	 * Upsert a subscriber.
	 *
	 * @param Contact            $contact    Contact.
	 * @param ProviderConnection $connection Connection.
	 */
	private function upsert( Contact $contact, ProviderConnection $connection ): SyncResult {
		$account = $connection->credential( 'account_id' );
		if ( '' === $account ) {
			return SyncResult::failure( 'No Drip account id configured.' );
		}

		$custom = [];
		foreach ( $contact->fields as $key => $value ) {
			$custom[ $connection->field_map[ $key ] ?? (string) $key ] = $value;
		}

		$subscriber = array_filter(
			[
				'email'         => $contact->email,
				'first_name'    => $contact->first_name,
				'last_name'     => $contact->last_name,
				'phone'         => PhoneNumber::to_e164( $contact->phone, $contact->country ) ?? $contact->phone,
				'custom_fields' => $custom ?: null,
				'tags'          => $contact->tags ?: null,
			],
			static fn ( $v ): bool => null !== $v && '' !== $v
		);

		$result = $this->request( 'POST', self::BASE . "/{$account}/subscribers", [ 'subscribers' => [ $subscriber ] ], $this->auth( $connection ) );

		// Optionally enroll into the configured campaign — Drip's closest
		// equivalent to "list membership" (see `list_label()`).
		$campaign = $connection->lists()[0] ?? '';
		if ( $result->success && '' !== $campaign ) {
			$this->request(
				'POST',
				self::BASE . "/{$account}/campaigns/{$campaign}/subscribers",
				[ 'subscribers' => [ [ 'email' => $contact->email ] ] ],
				$this->auth( $connection )
			);
		}

		return $result;
	}

	/**
	 * HTTP Basic auth (api key as username).
	 *
	 * @param ProviderConnection $connection Connection.
	 * @return array<string, string>
	 */
	private function auth( ProviderConnection $connection ): array {
		$token = base64_encode( $connection->credential( 'api_key' ) . ':' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		return [ 'Authorization' => 'Basic ' . $token ];
	}
}
