<?php
/**
 * ActiveCampaign provider adapter.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * ActiveCampaign API v3 adapter. Requires the account API URL plus an API key
 * (`Api-Token` header). Contacts are upserted via the contact/sync endpoint.
 */
final class ActiveCampaign extends AbstractProvider {

	public function id(): string {
		return 'activecampaign';
	}

	public function label(): string {
		return 'ActiveCampaign';
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
			$this->credential_field( 'api_url', __( 'API URL', 'brainstudioz-mailpilot' ), true, 'https://youraccount.api-us1.com' ),
			$this->credential_field( 'api_key', __( 'API Key', 'brainstudioz-mailpilot' ), true, __( 'Your API token', 'brainstudioz-mailpilot' ) ),
		];
	}

	public function guide_url(): string {
		return 'https://help.activecampaign.com/hc/en-us/articles/207317590-Getting-started-with-the-API';
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
			return SyncResult::success();
		}

		return $this->request( 'DELETE', $this->endpoint( $connection, '/contacts/' . rawurlencode( $id ) ), [], $this->auth( $connection ) );
	}

	public function apply_tags( string $email, array $tags, ProviderConnection $connection ): SyncResult {
		$id = $this->contact_id( $email, $connection );
		if ( '' === $id ) {
			return SyncResult::failure( 'Contact not found.' );
		}

		$errors        = [];
		$any_transient = false;

		foreach ( $tags as $tag ) {
			$result = $this->request(
				'POST',
				$this->endpoint( $connection, '/contactTags' ),
				[ 'contactTag' => [ 'contact' => $id, 'tag' => $tag ] ],
				$this->auth( $connection )
			);

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

	public function remove_tags( string $email, array $tags, ProviderConnection $connection ): SyncResult {
		$id = $this->contact_id( $email, $connection );
		if ( '' === $id ) {
			return SyncResult::failure( 'Contact not found.' );
		}

		// Removing a tag needs the contactTag *association* id, not the tag
		// id itself — ActiveCampaign has no "detach by contact+tag" shortcut.
		// Look up the contact's tag associations, then delete the ones whose
		// tag id matches what's being removed.
		$result = $this->request(
			'GET',
			$this->endpoint( $connection, '/contacts/' . rawurlencode( $id ) . '/contactTags' ),
			[],
			$this->auth( $connection )
		);

		if ( ! $result->success ) {
			return $result;
		}

		$remove        = array_map( 'strval', $tags );
		$errors        = [];
		$any_transient = false;

		foreach ( (array) ( $result->data['contactTags'] ?? [] ) as $assoc ) {
			$tag_id = (string) ( $assoc['tag'] ?? '' );
			if ( ! in_array( $tag_id, $remove, true ) ) {
				continue;
			}

			$assoc_id = (string) ( $assoc['id'] ?? '' );
			if ( '' === $assoc_id ) {
				continue;
			}

			$delete = $this->request( 'DELETE', $this->endpoint( $connection, '/contactTags/' . rawurlencode( $assoc_id ) ), [], $this->auth( $connection ) );
			if ( ! $delete->success ) {
				$any_transient = $any_transient || $delete->retryable;
				$errors[]      = $tag_id . ': ' . $delete->message;
			}
		}

		if ( ! $errors ) {
			return SyncResult::success();
		}

		$message = implode( '; ', $errors );

		return $any_transient ? SyncResult::transient( $message ) : SyncResult::failure( $message );
	}

	public function get_lists( ProviderConnection $connection ): array {
		$result = $this->request( 'GET', $this->endpoint( $connection, '/lists?limit=100' ), [], $this->auth( $connection ) );
		if ( ! $result->success ) {
			$this->list_error = $result->message;
			return [];
		}

		$lists = [];
		foreach ( (array) ( $result->data['lists'] ?? [] ) as $list ) {
			$lists[] = [ 'id' => (string) ( $list['id'] ?? '' ), 'name' => (string) ( $list['name'] ?? '' ) ];
		}

		return $lists;
	}

	/**
	 * Upsert via contact/sync, then add to the configured list.
	 *
	 * @param Contact            $contact    Contact.
	 * @param ProviderConnection $connection Connection.
	 */
	private function upsert( Contact $contact, ProviderConnection $connection ): SyncResult {
		$field_values = [];
		foreach ( $contact->fields as $key => $value ) {
			$mapped = $connection->field_map[ $key ] ?? null;
			if ( null !== $mapped ) {
				$field_values[] = [ 'field' => $mapped, 'value' => $value ];
			}
		}

		$body = [
			'contact' => array_filter(
				[
					'email'       => $contact->email,
					'firstName'   => $contact->first_name,
					'lastName'    => $contact->last_name,
					'phone'       => PhoneNumber::to_e164( $contact->phone, $contact->country ) ?? $contact->phone,
					'fieldValues' => $field_values ?: null,
				],
				static fn ( $v ): bool => null !== $v && '' !== $v
			),
		];

		$result = $this->request( 'POST', $this->endpoint( $connection, '/contact/sync' ), $body, $this->auth( $connection ) );

		$list = $connection->lists()[0] ?? '';
		if ( $result->success && '' !== $list ) {
			$id = (string) ( $result->data['contact']['id'] ?? '' );
			if ( '' !== $id ) {
				$this->request(
					'POST',
					$this->endpoint( $connection, '/contactLists' ),
					[ 'contactList' => [ 'list' => $list, 'contact' => $id, 'status' => 1 ] ],
					$this->auth( $connection )
				);
			}
		}

		return $result;
	}

	/**
	 * Resolve a contact id by email.
	 *
	 * @param string             $email      Email.
	 * @param ProviderConnection $connection Connection.
	 */
	private function contact_id( string $email, ProviderConnection $connection ): string {
		$result = $this->request( 'GET', $this->endpoint( $connection, '/contacts?email=' . rawurlencode( $email ) ), [], $this->auth( $connection ) );

		return (string) ( $result->data['contacts'][0]['id'] ?? '' );
	}

	/**
	 * Build a full API URL from the account API URL.
	 *
	 * @param ProviderConnection $connection Connection.
	 * @param string             $path       Path.
	 */
	private function endpoint( ProviderConnection $connection, string $path ): string {
		$base = rtrim( $connection->credential( 'api_url' ), '/' );

		return $base . '/api/3' . $path;
	}

	/**
	 * Auth header.
	 *
	 * @param ProviderConnection $connection Connection.
	 * @return array<string, string>
	 */
	private function auth( ProviderConnection $connection ): array {
		return [ 'Api-Token' => $connection->credential( 'api_key' ) ];
	}
}
