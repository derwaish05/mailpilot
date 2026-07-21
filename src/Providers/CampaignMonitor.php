<?php
/**
 * Campaign Monitor provider adapter.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Campaign Monitor (Createsend) API v3.3 adapter. Auth: HTTP Basic with the API
 * key as the username. Subscribers are added/updated against a list id.
 */
final class CampaignMonitor extends AbstractProvider {

	private const BASE = 'https://api.createsend.com/api/v3.3';

	public function id(): string {
		return 'campaign_monitor';
	}

	public function label(): string {
		return 'Campaign Monitor';
	}

	public function capabilities(): Capabilities {
		return new Capabilities(
			api_key_auth: true,
			list_selection: true,
			tag_selection: false,
			group_selection: false,
			double_opt_in: true,
			custom_field_mapping: true,
		);
	}

	public function credential_fields(): array {
		return [
			$this->credential_field( 'api_key', __( 'API Key', 'brainstudioz-mailpilot' ), true ),
			$this->credential_field( 'client_id', __( 'Client ID', 'brainstudioz-mailpilot' ), true ),
		];
	}

	public function guide_url(): string {
		return 'https://help.campaignmonitor.com/api-keys';
	}

	public function create_contact( Contact $contact, ProviderConnection $connection ): SyncResult {
		return $this->upsert( $contact, $connection );
	}

	public function update_contact( Contact $contact, ProviderConnection $connection ): SyncResult {
		return $this->upsert( $contact, $connection );
	}

	public function delete_contact( string $email, ProviderConnection $connection ): SyncResult {
		$list = $this->list_id( $connection );
		if ( '' === $list ) {
			return SyncResult::success();
		}

		return $this->request(
			'DELETE',
			self::BASE . "/subscribers/{$list}.json?email=" . rawurlencode( $email ),
			[],
			$this->auth( $connection )
		);
	}

	public function apply_tags( string $email, array $tags, ProviderConnection $connection ): SyncResult {
		// Campaign Monitor's classic API has no contact-level tag primitive
		// (only list membership + custom fields). Reporting a clear failure
		// here — rather than a silent fake success — so callers (and the
		// sync log) see that nothing actually happened, instead of assuming
		// tags were applied. `capabilities()->tag_selection` is already
		// false, which keeps this out of the connection UI.
		return SyncResult::failure( __( 'Campaign Monitor has no tag primitive for contacts — use list membership or custom fields instead.', 'brainstudioz-mailpilot' ) );
	}

	public function remove_tags( string $email, array $tags, ProviderConnection $connection ): SyncResult {
		return SyncResult::failure( __( 'Campaign Monitor has no tag primitive for contacts — use list membership or custom fields instead.', 'brainstudioz-mailpilot' ) );
	}

	public function get_lists( ProviderConnection $connection ): array {
		$client = $connection->credential( 'client_id' );
		if ( '' === $client ) {
			return [];
		}

		$result = $this->request( 'GET', self::BASE . "/clients/{$client}/lists.json", [], $this->auth( $connection ) );
		if ( ! $result->success ) {
			$this->list_error = $result->message;
			return [];
		}

		$lists = [];
		foreach ( (array) $result->data as $list ) {
			$lists[] = [ 'id' => (string) ( $list['ListID'] ?? '' ), 'name' => (string) ( $list['Name'] ?? '' ) ];
		}

		return $lists;
	}

	/**
	 * Add/update a subscriber on the configured list.
	 *
	 * @param Contact            $contact    Contact.
	 * @param ProviderConnection $connection Connection.
	 */
	private function upsert( Contact $contact, ProviderConnection $connection ): SyncResult {
		$list = $this->list_id( $connection );
		if ( '' === $list ) {
			return SyncResult::failure( 'No Campaign Monitor list configured.' );
		}

		$custom = [];
		foreach ( $contact->fields as $key => $value ) {
			$custom[] = [ 'Key' => $connection->field_map[ $key ] ?? (string) $key, 'Value' => $value ];
		}

		$body = [
			'EmailAddress'   => $contact->email,
			'Name'           => trim( (string) $contact->first_name . ' ' . (string) $contact->last_name ),
			'CustomFields'   => $custom,
			'ConsentToTrack' => 'Yes',
			'Resubscribe'    => true,
		];

		return $this->request( 'POST', self::BASE . "/subscribers/{$list}.json", $body, $this->auth( $connection ) );
	}

	/**
	 * Configured list id.
	 *
	 * @param ProviderConnection $connection Connection.
	 */
	private function list_id( ProviderConnection $connection ): string {
		return $connection->lists()[0] ?? (string) $connection->setting( 'list_id', '' );
	}

	/**
	 * HTTP Basic auth (api key as username).
	 *
	 * @param ProviderConnection $connection Connection.
	 * @return array<string, string>
	 */
	private function auth( ProviderConnection $connection ): array {
		$token = base64_encode( $connection->credential( 'api_key' ) . ':x' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		return [ 'Authorization' => 'Basic ' . $token ];
	}
}
