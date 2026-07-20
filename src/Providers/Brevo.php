<?php
/**
 * Brevo provider adapter.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Brevo (formerly Sendinblue) API v3 adapter.
 *
 * Auth: an `api-key` header. Contacts are upserted by email with
 * `updateEnabled`. Brevo has no first-class tags, so tags are stored in a
 * `TAGS` contact attribute.
 */
final class Brevo extends AbstractProvider {

	private const BASE = 'https://api.brevo.com/v3';

	public function id(): string {
		return 'brevo';
	}

	public function label(): string {
		return 'Brevo';
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
		return 'https://help.brevo.com/hc/en-us/articles/209467485-Create-and-manage-your-API-keys';
	}

	public function create_contact( Contact $contact, ProviderConnection $connection ): SyncResult {
		return $this->upsert( $contact, $connection, $contact->tags );
	}

	public function update_contact( Contact $contact, ProviderConnection $connection ): SyncResult {
		return $this->upsert( $contact, $connection, $contact->tags );
	}

	public function delete_contact( string $email, ProviderConnection $connection ): SyncResult {
		return $this->request(
			'DELETE',
			self::BASE . '/contacts/' . rawurlencode( $email ),
			[],
			$this->auth( $connection )
		);
	}

	public function apply_tags( string $email, array $tags, ProviderConnection $connection ): SyncResult {
		return $this->write_tags( $email, $tags, $connection, false );
	}

	public function remove_tags( string $email, array $tags, ProviderConnection $connection ): SyncResult {
		return $this->write_tags( $email, $tags, $connection, true );
	}

	public function get_lists( ProviderConnection $connection ): array {
		$result = $this->request(
			'GET',
			self::BASE . '/contacts/lists?limit=50',
			[],
			$this->auth( $connection )
		);

		if ( ! $result->success ) {
			$this->list_error = $result->message;
			return [];
		}

		$this->list_error = null;
		$lists            = [];
		foreach ( (array) ( $result->data['lists'] ?? [] ) as $list ) {
			$lists[] = [
				'id'   => (string) ( $list['id'] ?? '' ),
				'name' => (string) ( $list['name'] ?? '' ),
			];
		}

		return $lists;
	}

	/**
	 * Upsert a contact.
	 *
	 * @param Contact            $contact    Contact.
	 * @param ProviderConnection $connection Connection.
	 * @param array<int, string> $tags       Tags to store in the TAGS attribute.
	 */
	private function upsert( Contact $contact, ProviderConnection $connection, array $tags = [] ): SyncResult {
		$attributes = $this->attributes( $contact, $connection );

		if ( $tags ) {
			$attributes['TAGS'] = implode( ',', array_values( $tags ) );
		}

		$body = [
			'email'         => $contact->email,
			'attributes'    => $attributes,
			'updateEnabled' => true,
		];

		$list_ids = array_map( 'intval', $this->list_ids( $connection ) );
		if ( $list_ids ) {
			$body['listIds'] = $list_ids;
		}

		if ( $connection->double_opt_in() ) {
			$body['emailBlacklisted'] = false;
		}

		return $this->request( 'POST', self::BASE . '/contacts', $body, $this->auth( $connection ) );
	}

	/**
	 * Merge or clear the TAGS attribute.
	 *
	 * @param string             $email      Email.
	 * @param array<int, string> $tags       Tags.
	 * @param ProviderConnection $connection Connection.
	 * @param bool               $remove     Whether to remove the given tags.
	 */
	private function write_tags( string $email, array $tags, ProviderConnection $connection, bool $remove ): SyncResult {
		if ( ! $tags ) {
			return SyncResult::success();
		}

		// Fetch current tags to merge non-destructively.
		$current = $this->request(
			'GET',
			self::BASE . '/contacts/' . rawurlencode( $email ),
			[],
			$this->auth( $connection )
		);

		$existing = [];
		if ( $current->success ) {
			$raw      = (string) ( $current->data['attributes']['TAGS'] ?? '' );
			$existing = array_filter( array_map( 'trim', explode( ',', $raw ) ) );
		}

		$next = $remove
			? array_diff( $existing, $tags )
			: array_unique( array_merge( $existing, $tags ) );

		$body = [
			'attributes' => [ 'TAGS' => implode( ',', $next ) ],
		];

		return $this->request(
			'PUT',
			self::BASE . '/contacts/' . rawurlencode( $email ),
			$body,
			$this->auth( $connection )
		);
	}

	/**
	 * Build Brevo attributes from the contact.
	 *
	 * @param Contact            $contact    Contact.
	 * @param ProviderConnection $connection Connection.
	 * @return array<string, mixed>
	 */
	private function attributes( Contact $contact, ProviderConnection $connection ): array {
		$attributes = array_filter(
			[
				'FIRSTNAME' => $contact->first_name,
				'LASTNAME'  => $contact->last_name,
				// Brevo validates SMS as a strict international (E.164) number and
				// rejects the whole contact otherwise — normalise using the
				// contact's country, and drop it when it can't be
				// internationalised so the contact still syncs.
				'SMS'       => PhoneNumber::to_e164( $contact->phone, $contact->country ),
				'COMPANY'   => $contact->company,
			],
			static fn ( $v ): bool => null !== $v && '' !== $v
		);

		foreach ( $contact->fields as $local_key => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}

			$attr_key                = $connection->field_map[ $local_key ] ?? strtoupper( (string) $local_key );
			$attributes[ $attr_key ] = $value;
		}

		return $attributes;
	}

	/**
	 * Configured list ids.
	 *
	 * @param ProviderConnection $connection Connection.
	 * @return array<int, string>
	 */
	private function list_ids( ProviderConnection $connection ): array {
		return $connection->lists();
	}

	/**
	 * Brevo auth header.
	 *
	 * @param ProviderConnection $connection Connection.
	 * @return array<string, string>
	 */
	private function auth( ProviderConnection $connection ): array {
		return [
			'api-key' => $connection->credential( 'api_key' ),
			'Accept'  => 'application/json',
		];
	}
}
