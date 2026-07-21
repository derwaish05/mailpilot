<?php
/**
 * GetResponse provider adapter.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * GetResponse API v3 adapter. Auth: `X-Auth-Token: api-key {key}`. Contacts are
 * added to a campaign (list); GetResponse supports tags.
 */
final class GetResponse extends AbstractProvider {

	private const BASE = 'https://api.getresponse.com/v3';

	public function id(): string {
		return 'getresponse';
	}

	public function label(): string {
		return 'GetResponse';
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
		return 'https://www.getresponse.com/help/where-do-i-find-the-api-key.html';
	}

	public function list_label(): string {
		return __( 'Campaign', 'brainstudioz-mailpilot' );
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

		return $this->request( 'DELETE', self::BASE . '/contacts/' . rawurlencode( $id ), [], $this->auth( $connection ) );
	}

	public function apply_tags( string $email, array $tags, ProviderConnection $connection ): SyncResult {
		return $this->upsert( new Contact( email: $email, tags: $tags ), $connection );
	}

	public function remove_tags( string $email, array $tags, ProviderConnection $connection ): SyncResult {
		$id = $this->contact_id( $email, $connection );
		if ( '' === $id ) {
			return SyncResult::failure( 'GetResponse: no contact found for ' . $email . '.' );
		}

		// GetResponse's contact resource carries its tag associations as a
		// `tags` array of `{tagId}` objects (the same shape `upsert()` posts
		// on create). There's no separate "detach tag" endpoint, so removal
		// means: read the contact's current tags, drop the ones being
		// removed, and PATCH the reduced list back — symmetric to how
		// `apply_tags()` sets tags on create via the same field.
		$current = $this->request( 'GET', self::BASE . '/contacts/' . rawurlencode( $id ), [], $this->auth( $connection ) );
		if ( ! $current->success ) {
			return $current;
		}

		$remove   = array_map( 'strval', $tags );
		$existing = array_filter( array_map(
			static fn ( $t ): string => (string) ( $t['tagId'] ?? '' ),
			(array) ( $current->data['tags'] ?? [] )
		) );
		$remaining = array_values( array_diff( $existing, $remove ) );

		$body = [ 'tags' => array_map( static fn ( string $t ): array => [ 'tagId' => $t ], $remaining ) ];

		return $this->request( 'POST', self::BASE . '/contacts/' . rawurlencode( $id ) . '/tags', $body, $this->auth( $connection ) );
	}

	public function get_lists( ProviderConnection $connection ): array {
		$result = $this->request( 'GET', self::BASE . '/campaigns?perPage=100', [], $this->auth( $connection ) );
		if ( ! $result->success ) {
			$this->list_error = $result->message;
			return [];
		}

		$lists = [];
		foreach ( (array) $result->data as $campaign ) {
			$lists[] = [ 'id' => (string) ( $campaign['campaignId'] ?? '' ), 'name' => (string) ( $campaign['name'] ?? '' ) ];
		}

		return $lists;
	}

	/**
	 * Upsert a contact into the configured campaign.
	 *
	 * @param Contact            $contact    Contact.
	 * @param ProviderConnection $connection Connection.
	 */
	private function upsert( Contact $contact, ProviderConnection $connection ): SyncResult {
		$campaign = $connection->lists()[0] ?? (string) $connection->setting( 'campaign_id', '' );
		if ( '' === $campaign ) {
			return SyncResult::failure( 'No GetResponse campaign configured.' );
		}

		$custom = [];
		foreach ( $contact->fields as $key => $value ) {
			$mapped = $connection->field_map[ $key ] ?? null;
			if ( null !== $mapped ) {
				$custom[] = [ 'customFieldId' => $mapped, 'value' => [ $value ] ];
			}
		}

		$body = array_filter(
			[
				'email'            => $contact->email,
				'name'             => trim( (string) $contact->first_name . ' ' . (string) $contact->last_name ) ?: null,
				'campaign'         => [ 'campaignId' => $campaign ],
				'tags'             => $contact->tags ? array_map( static fn ( $t ): array => [ 'tagId' => $t ], $contact->tags ) : null,
				'customFieldValues' => $custom ?: null,
			],
			static fn ( $v ): bool => null !== $v && '' !== $v
		);

		return $this->request( 'POST', self::BASE . '/contacts', $body, $this->auth( $connection ) );
	}

	/**
	 * Resolve a contact id by email.
	 *
	 * @param string             $email      Email.
	 * @param ProviderConnection $connection Connection.
	 */
	private function contact_id( string $email, ProviderConnection $connection ): string {
		$result = $this->request( 'GET', self::BASE . '/contacts?query[email]=' . rawurlencode( $email ), [], $this->auth( $connection ) );

		return (string) ( $result->data[0]['contactId'] ?? '' );
	}

	/**
	 * Auth header.
	 *
	 * @param ProviderConnection $connection Connection.
	 * @return array<string, string>
	 */
	private function auth( ProviderConnection $connection ): array {
		return [ 'X-Auth-Token' => 'api-key ' . $connection->credential( 'api_key' ) ];
	}
}
