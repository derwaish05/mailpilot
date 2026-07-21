<?php
/**
 * MailerLite provider adapter.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MailerLite (new) API adapter. Auth: a bearer token. Subscribers are upserted
 * by email; MailerLite uses groups rather than tags.
 */
final class MailerLite extends AbstractProvider {

	private const BASE = 'https://connect.mailerlite.com/api';

	public function id(): string {
		return 'mailerlite';
	}

	public function label(): string {
		return 'MailerLite';
	}

	public function capabilities(): Capabilities {
		return new Capabilities(
			api_key_auth: true,
			list_selection: true,
			tag_selection: false,
			group_selection: true,
			double_opt_in: true,
			custom_field_mapping: true,
		);
	}

	public function guide_url(): string {
		return 'https://www.mailerlite.com/help/where-to-find-the-mailerlite-api-key-group-id-and-documentation';
	}

	public function list_label(): string {
		return __( 'Group', 'brainstudioz-mailpilot' );
	}

	public function create_contact( Contact $contact, ProviderConnection $connection ): SyncResult {
		return $this->upsert( $contact, $connection );
	}

	public function update_contact( Contact $contact, ProviderConnection $connection ): SyncResult {
		return $this->upsert( $contact, $connection );
	}

	public function delete_contact( string $email, ProviderConnection $connection ): SyncResult {
		$lookup = $this->request( 'GET', self::BASE . '/subscribers/' . rawurlencode( $email ), [], $this->auth( $connection ) );
		$id     = (string) ( $lookup->data['data']['id'] ?? '' );

		if ( '' === $id ) {
			return SyncResult::success();
		}

		return $this->request( 'DELETE', self::BASE . '/subscribers/' . rawurlencode( $id ), [], $this->auth( $connection ) );
	}

	public function apply_tags( string $email, array $tags, ProviderConnection $connection ): SyncResult {
		// MailerLite has no tag primitive; groups (managed via list
		// selection/`get_lists()` above) are its closest equivalent.
		// Reporting a clear failure — rather than a silent fake success — so
		// callers (and the sync log) see nothing actually happened.
		// `capabilities()->tag_selection` is already false, keeping this out
		// of the connection UI.
		return SyncResult::failure( __( 'MailerLite has no tag primitive for subscribers — use groups instead.', 'brainstudioz-mailpilot' ) );
	}

	public function remove_tags( string $email, array $tags, ProviderConnection $connection ): SyncResult {
		return SyncResult::failure( __( 'MailerLite has no tag primitive for subscribers — use groups instead.', 'brainstudioz-mailpilot' ) );
	}

	public function get_lists( ProviderConnection $connection ): array {
		$result = $this->request( 'GET', self::BASE . '/groups?limit=100', [], $this->auth( $connection ) );
		if ( ! $result->success ) {
			$this->list_error = $result->message;
			return [];
		}

		$this->list_error = null;
		$lists            = [];
		foreach ( (array) ( $result->data['data'] ?? [] ) as $group ) {
			$lists[] = [ 'id' => (string) ( $group['id'] ?? '' ), 'name' => (string) ( $group['name'] ?? '' ) ];
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
		$fields = array_filter(
			[
				'name'    => $contact->first_name,
				'last_name' => $contact->last_name,
				'phone'   => PhoneNumber::to_e164( $contact->phone, $contact->country ) ?? $contact->phone,
				'company' => $contact->company,
			],
			static fn ( $v ): bool => null !== $v && '' !== $v
		);

		foreach ( $contact->fields as $key => $value ) {
			$fields[ $connection->field_map[ $key ] ?? (string) $key ] = $value;
		}

		$body = [ 'email' => $contact->email, 'fields' => $fields ];

		$groups = array_map( 'strval', $connection->lists() );
		if ( $groups ) {
			$body['groups'] = $groups;
		}
		if ( $connection->double_opt_in() ) {
			$body['status'] = 'unconfirmed';
		}

		return $this->request( 'POST', self::BASE . '/subscribers', $body, $this->auth( $connection ) );
	}

	/**
	 * Bearer auth header.
	 *
	 * @param ProviderConnection $connection Connection.
	 * @return array<string, string>
	 */
	private function auth( ProviderConnection $connection ): array {
		return [
			'Authorization' => 'Bearer ' . $connection->credential( 'api_key' ),
			'Accept'        => 'application/json',
		];
	}
}
