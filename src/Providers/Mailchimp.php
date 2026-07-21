<?php
/**
 * Mailchimp provider adapter.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Mailchimp Marketing API v3 adapter.
 *
 * Auth: an API key of the form `key-dc` (the datacenter suffix selects the
 * regional host). Members are upserted by the MD5 of the lowercased email.
 */
final class Mailchimp extends AbstractProvider {

	public function id(): string {
		return 'mailchimp';
	}

	public function label(): string {
		return 'Mailchimp';
	}

	public function capabilities(): Capabilities {
		return new Capabilities(
			api_key_auth: true,
			list_selection: true,
			tag_selection: true,
			group_selection: true,
			double_opt_in: true,
			custom_field_mapping: true,
		);
	}

	public function credential_fields(): array {
		return [ $this->credential_field( 'api_key', __( 'API Key', 'brainstudioz-mailpilot' ), true, 'xxxxxxxxxxxxxxxxxxxxxxxxxxxxxxxx-us20' ) ];
	}

	public function guide_url(): string {
		return 'https://mailchimp.com/help/about-api-keys/';
	}

	public function list_label(): string {
		return __( 'Audience', 'brainstudioz-mailpilot' );
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
			return SyncResult::failure( 'No Mailchimp list configured.' );
		}

		return $this->request(
			'DELETE',
			$this->endpoint( $connection, "/lists/{$list}/members/" . $this->hash( $email ) ),
			[],
			$this->auth( $connection )
		);
	}

	public function apply_tags( string $email, array $tags, ProviderConnection $connection ): SyncResult {
		return $this->tag( $email, $tags, 'active', $connection );
	}

	public function remove_tags( string $email, array $tags, ProviderConnection $connection ): SyncResult {
		return $this->tag( $email, $tags, 'inactive', $connection );
	}

	public function get_lists( ProviderConnection $connection ): array {
		$result = $this->request(
			'GET',
			$this->endpoint( $connection, '/lists?count=100&fields=lists.id,lists.name' ),
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
	 * Upsert a member into the configured list.
	 *
	 * @param Contact            $contact    Contact.
	 * @param ProviderConnection $connection Connection.
	 */
	private function upsert( Contact $contact, ProviderConnection $connection ): SyncResult {
		$list = $this->list_id( $connection );
		if ( '' === $list ) {
			return SyncResult::failure( 'No Mailchimp list configured.' );
		}

		$status = $connection->double_opt_in() ? 'pending' : 'subscribed';

		$body = [
			'email_address' => $contact->email,
			'status_if_new' => $status,
			'merge_fields'  => $this->merge_fields( $contact, $connection ),
		];

		if ( $contact->tags ) {
			$body['tags'] = array_values( $contact->tags );
		}

		return $this->request(
			'PUT',
			$this->endpoint( $connection, "/lists/{$list}/members/" . $this->hash( $contact->email ) ),
			$body,
			$this->auth( $connection )
		);
	}

	/**
	 * Add or remove tags on a member.
	 *
	 * @param string             $email      Email.
	 * @param array<int, string> $tags       Tags.
	 * @param string             $state      `active` to add, `inactive` to remove.
	 * @param ProviderConnection $connection Connection.
	 */
	private function tag( string $email, array $tags, string $state, ProviderConnection $connection ): SyncResult {
		$list = $this->list_id( $connection );
		if ( '' === $list || ! $tags ) {
			return SyncResult::success();
		}

		$body = [
			'tags' => array_map(
				static fn ( string $t ): array => [
					'name'   => $t,
					'status' => $state,
				],
				array_values( $tags )
			),
		];

		return $this->request(
			'POST',
			$this->endpoint( $connection, "/lists/{$list}/members/" . $this->hash( $email ) . '/tags' ),
			$body,
			$this->auth( $connection )
		);
	}

	/**
	 * Build Mailchimp merge fields (FNAME/LNAME/etc.) from the mapped contact.
	 *
	 * @param Contact            $contact    Contact.
	 * @param ProviderConnection $connection Connection.
	 * @return array<string, mixed>
	 */
	private function merge_fields( Contact $contact, ProviderConnection $connection ): array {
		// Default Mailchimp merge tags for standard fields.
		$fields = array_filter(
			[
				'FNAME'   => $contact->first_name,
				'LNAME'   => $contact->last_name,
				'PHONE'   => PhoneNumber::to_e164( $contact->phone, $contact->country ) ?? $contact->phone,
				'COMPANY' => $contact->company,
			],
			static fn ( $v ): bool => null !== $v && '' !== $v
		);

		// Add custom fields, mapping each local key to its Mailchimp merge tag.
		foreach ( $contact->fields as $local_key => $value ) {
			if ( null === $value || '' === $value ) {
				continue;
			}

			$merge_tag            = $connection->field_map[ $local_key ] ?? strtoupper( (string) $local_key );
			$fields[ $merge_tag ] = $value;
		}

		return $fields;
	}

	/**
	 * Configured list id.
	 *
	 * @param ProviderConnection $connection Connection.
	 */
	private function list_id( ProviderConnection $connection ): string {
		$lists = $connection->lists();

		return $lists[0] ?? (string) $connection->setting( 'list_id', '' );
	}

	/**
	 * MD5 hash of the lowercased email (Mailchimp subscriber hash).
	 *
	 * @param string $email Email.
	 */
	private function hash( string $email ): string {
		return md5( strtolower( trim( $email ) ) );
	}

	/**
	 * Build a full API URL for the connection's datacenter.
	 *
	 * @param ProviderConnection $connection Connection.
	 * @param string             $path       API path beginning with `/`.
	 */
	private function endpoint( ProviderConnection $connection, string $path ): string {
		$dc = $this->datacenter( $connection );

		return "https://{$dc}.api.mailchimp.com/3.0{$path}";
	}

	/**
	 * Extract the datacenter suffix from the API key.
	 *
	 * @param ProviderConnection $connection Connection.
	 */
	private function datacenter( ProviderConnection $connection ): string {
		$key   = $connection->credential( 'api_key' );
		$parts = explode( '-', $key );

		return end( $parts ) ?: 'us1';
	}

	/**
	 * Basic auth header for Mailchimp.
	 *
	 * @param ProviderConnection $connection Connection.
	 * @return array<string, string>
	 */
	private function auth( ProviderConnection $connection ): array {
		$token = base64_encode( 'mailpilot:' . $connection->credential( 'api_key' ) ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode

		return [ 'Authorization' => 'Basic ' . $token ];
	}
}
