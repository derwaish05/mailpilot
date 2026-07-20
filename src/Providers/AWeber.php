<?php
/**
 * AWeber provider adapter.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * AWeber API 1.0 adapter. AWeber uses OAuth2, so the connection stores an
 * access token plus account id; the list id selects the target list.
 */
final class AWeber extends AbstractProvider {

	private const BASE = 'https://api.aweber.com/1.0';

	public function id(): string {
		return 'aweber';
	}

	public function label(): string {
		return 'AWeber';
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
			$this->credential_field( 'access_token', __( 'Access Token', 'mailpilot' ), true ),
			$this->credential_field( 'account_id', __( 'Account ID', 'mailpilot' ), true ),
		];
	}

	public function guide_url(): string {
		return 'https://help.aweber.com/hc/en-us/articles/360045564314-How-do-I-generate-an-OAuth-Access-Token';
	}

	public function create_contact( Contact $contact, ProviderConnection $connection ): SyncResult {
		return $this->upsert( $contact, $connection );
	}

	public function update_contact( Contact $contact, ProviderConnection $connection ): SyncResult {
		return $this->upsert( $contact, $connection );
	}

	public function delete_contact( string $email, ProviderConnection $connection ): SyncResult {
		$subscriber = $this->find_subscriber( $email, $connection );
		if ( null === $subscriber ) {
			// Not subscribed — nothing to delete.
			return SyncResult::success();
		}

		[ $account, $list, $id ] = $subscriber;

		return $this->request(
			'DELETE',
			self::BASE . "/accounts/{$account}/lists/{$list}/subscribers/{$id}",
			[],
			$this->auth( $connection )
		);
	}

	public function apply_tags( string $email, array $tags, ProviderConnection $connection ): SyncResult {
		$subscriber = $this->find_subscriber( $email, $connection );

		if ( null === $subscriber ) {
			// Not subscribed yet: create with these tags.
			return $this->upsert( new Contact( email: $email, tags: $tags ), $connection );
		}

		[ $account, $list, $id, $existing_tags ] = $subscriber;
		$merged = array_values( array_unique( array_merge( $existing_tags, array_map( 'strval', $tags ) ) ) );

		return $this->request(
			'PATCH',
			self::BASE . "/accounts/{$account}/lists/{$list}/subscribers/{$id}",
			[ 'tags' => $merged ],
			$this->auth( $connection )
		);
	}

	public function remove_tags( string $email, array $tags, ProviderConnection $connection ): SyncResult {
		$subscriber = $this->find_subscriber( $email, $connection );
		if ( null === $subscriber ) {
			// Not subscribed — nothing to remove tags from.
			return SyncResult::success();
		}

		[ $account, $list, $id, $existing_tags ] = $subscriber;
		$remove    = array_map( 'strval', $tags );
		$remaining = array_values( array_diff( $existing_tags, $remove ) );

		return $this->request(
			'PATCH',
			self::BASE . "/accounts/{$account}/lists/{$list}/subscribers/{$id}",
			[ 'tags' => $remaining ],
			$this->auth( $connection )
		);
	}

	public function get_lists( ProviderConnection $connection ): array {
		$account = $connection->credential( 'account_id' );
		$result  = $this->request( 'GET', self::BASE . "/accounts/{$account}/lists", [], $this->auth( $connection ) );
		if ( ! $result->success ) {
			$this->list_error = $result->message;
			return [];
		}

		$lists = [];
		foreach ( (array) ( $result->data['entries'] ?? [] ) as $list ) {
			$lists[] = [ 'id' => (string) ( $list['id'] ?? '' ), 'name' => (string) ( $list['name'] ?? '' ) ];
		}

		return $lists;
	}

	/**
	 * Upsert a subscriber into the configured list.
	 *
	 * @param Contact            $contact    Contact.
	 * @param ProviderConnection $connection Connection.
	 */
	private function upsert( Contact $contact, ProviderConnection $connection ): SyncResult {
		$account = $connection->credential( 'account_id' );
		$list    = $connection->lists()[0] ?? (string) $connection->setting( 'list_id', '' );

		if ( '' === $account || '' === $list ) {
			return SyncResult::failure( 'AWeber account or list not configured.' );
		}

		$custom = [];
		foreach ( $contact->fields as $key => $value ) {
			$custom[ $connection->field_map[ $key ] ?? (string) $key ] = $value;
		}

		$body = array_filter(
			[
				'email'         => $contact->email,
				'name'          => trim( (string) $contact->first_name . ' ' . (string) $contact->last_name ) ?: null,
				'tags'          => $contact->tags ?: null,
				'custom_fields' => $custom ?: null,
			],
			static fn ( $v ): bool => null !== $v && '' !== $v
		);

		return $this->request(
			'POST',
			self::BASE . "/accounts/{$account}/lists/{$list}/subscribers",
			$body,
			$this->auth( $connection )
		);
	}

	/**
	 * Find the configured list's subscriber by email.
	 *
	 * AWeber's `tags` field is a full-replace list on the subscriber resource
	 * — there's no separate add/remove-tag endpoint — so every tag operation
	 * needs the subscriber's id and current tags first.
	 *
	 * @param string             $email      Email.
	 * @param ProviderConnection $connection Connection.
	 * @return array{0:string,1:string,2:string,3:array<int,string>}|null Account id, list id, subscriber id, current tags — or null if not subscribed.
	 */
	private function find_subscriber( string $email, ProviderConnection $connection ): ?array {
		$account = $connection->credential( 'account_id' );
		$list    = $connection->lists()[0] ?? (string) $connection->setting( 'list_id', '' );

		if ( '' === $account || '' === $list ) {
			return null;
		}

		$result = $this->request(
			'GET',
			self::BASE . "/accounts/{$account}/lists/{$list}/subscribers?ws.op=find&email=" . rawurlencode( $email ),
			[],
			$this->auth( $connection )
		);

		if ( ! $result->success ) {
			return null;
		}

		$entry = $result->data['entries'][0] ?? null;
		if ( ! is_array( $entry ) || empty( $entry['id'] ) ) {
			return null;
		}

		$tags = array_map( 'strval', (array) ( $entry['tags'] ?? [] ) );

		return [ $account, $list, (string) $entry['id'], $tags ];
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
