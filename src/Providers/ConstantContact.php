<?php
/**
 * Constant Contact provider adapter.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Constant Contact API v3 adapter. Uses OAuth2 (the connection stores a bearer
 * access token). Contacts are upserted via the sign-up-form endpoint and added
 * to the configured list.
 */
final class ConstantContact extends AbstractProvider {

	private const BASE = 'https://api.cc.email/v3';

	public function id(): string {
		return 'constant_contact';
	}

	public function label(): string {
		return 'Constant Contact';
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
		return [ $this->credential_field( 'access_token', __( 'Access Token', 'mailpilot' ), true ) ];
	}

	public function guide_url(): string {
		return 'https://developer.constantcontact.com/api_guide/getting_started.html';
	}

	public function create_contact( Contact $contact, ProviderConnection $connection ): SyncResult {
		return $this->upsert( $contact, $connection );
	}

	public function update_contact( Contact $contact, ProviderConnection $connection ): SyncResult {
		return $this->upsert( $contact, $connection );
	}

	public function delete_contact( string $email, ProviderConnection $connection ): SyncResult {
		$id = $this->contact_id( $email, $connection );
		if ( '' === $id ) {
			// Not found — nothing to delete.
			return SyncResult::success();
		}

		return $this->request( 'DELETE', self::BASE . '/contacts/' . rawurlencode( $id ), [], $this->auth( $connection ) );
	}

	public function apply_tags( string $email, array $tags, ProviderConnection $connection ): SyncResult {
		// Constant Contact's v3 API has no contact-level tag primitive (only
		// list membership, custom fields, and notes). Reporting a clear
		// failure — rather than a silent fake success — so callers (and the
		// sync log) see nothing actually happened. `capabilities()
		// ->tag_selection` is already false, keeping this out of the
		// connection UI.
		return SyncResult::failure( __( 'Constant Contact has no tag primitive for contacts — use list membership or custom fields instead.', 'mailpilot' ) );
	}

	public function remove_tags( string $email, array $tags, ProviderConnection $connection ): SyncResult {
		return SyncResult::failure( __( 'Constant Contact has no tag primitive for contacts — use list membership or custom fields instead.', 'mailpilot' ) );
	}

	public function get_lists( ProviderConnection $connection ): array {
		$result = $this->request( 'GET', self::BASE . '/contact_lists?limit=100', [], $this->auth( $connection ) );
		if ( ! $result->success ) {
			$this->list_error = $result->message;
			return [];
		}

		$lists = [];
		foreach ( (array) ( $result->data['lists'] ?? [] ) as $list ) {
			$lists[] = [ 'id' => (string) ( $list['list_id'] ?? '' ), 'name' => (string) ( $list['name'] ?? '' ) ];
		}

		return $lists;
	}

	/**
	 * Upsert a contact via the sign-up-form endpoint.
	 *
	 * @param Contact            $contact    Contact.
	 * @param ProviderConnection $connection Connection.
	 */
	private function upsert( Contact $contact, ProviderConnection $connection ): SyncResult {
		$body = array_filter(
			[
				'email_address'    => $contact->email,
				'first_name'       => $contact->first_name,
				'last_name'        => $contact->last_name,
				'list_memberships' => $connection->lists() ?: null,
			],
			static fn ( $v ): bool => null !== $v && '' !== $v
		);

		return $this->request( 'POST', self::BASE . '/contacts/sign_up_form', $body, $this->auth( $connection ) );
	}

	/**
	 * Resolve a contact id by email.
	 *
	 * @param string             $email      Email.
	 * @param ProviderConnection $connection Connection.
	 */
	private function contact_id( string $email, ProviderConnection $connection ): string {
		$result = $this->request(
			'GET',
			self::BASE . '/contacts?email=' . rawurlencode( $email ) . '&status=all',
			[],
			$this->auth( $connection )
		);

		return (string) ( $result->data['contacts'][0]['contact_id'] ?? '' );
	}

	/**
	 * Bearer (OAuth2 access token) auth header.
	 *
	 * @param ProviderConnection $connection Connection.
	 * @return array<string, string>
	 */
	private function auth( ProviderConnection $connection ): array {
		return [ 'Authorization' => 'Bearer ' . $connection->credential( 'access_token' ) ];
	}
}
