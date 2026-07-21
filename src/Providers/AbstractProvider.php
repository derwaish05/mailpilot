<?php
/**
 * Base provider adapter.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Providers;

use MailPilot\Providers\Contracts\Provider;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Shared machinery for provider adapters: HTTP requests, field mapping, and
 * error normalisation into {@see SyncResult}. Concrete providers implement the
 * endpoint-specific calls and `id()`/`label()`.
 */
abstract class AbstractProvider implements Provider {

	/**
	 * Default capabilities; override per provider as needed.
	 */
	public function capabilities(): Capabilities {
		return new Capabilities();
	}

	/**
	 * Credential fields this provider needs in the connection UI, in order.
	 *
	 * The default is a single API key. Providers needing more (an API URL, an
	 * account id, an OAuth token, …) override this so the admin form can render
	 * exactly the right inputs for the selected provider.
	 *
	 * @return array<int, array{key:string,label:string,required:bool,placeholder:string}>
	 */
	public function credential_fields(): array {
		// Local integrations (same-site plugins) authenticate in-process and need
		// no credentials entered.
		if ( ! $this->capabilities()->api_key_auth ) {
			return [];
		}

		return [ $this->credential_field( 'api_key', __( 'API Key', 'brainstudioz-mailpilot' ), true ) ];
	}

	/**
	 * URL to the provider's "where do I find my API key" documentation, shown as
	 * a help link beside the credentials. Empty hides the link.
	 */
	public function guide_url(): string {
		return '';
	}

	/**
	 * The noun this provider uses for its primary audience grouping — "List",
	 * "Audience", "Group", "Campaign", … — used to label the list selector.
	 */
	public function list_label(): string {
		return __( 'List', 'brainstudioz-mailpilot' );
	}

	/**
	 * Build a credential-field descriptor for {@see credential_fields()}.
	 *
	 * @param string $key         Credential key stored on the connection.
	 * @param string $label       Field label.
	 * @param bool   $required    Whether the field is required.
	 * @param string $placeholder Placeholder/help text.
	 * @return array{key:string,label:string,required:bool,placeholder:string}
	 */
	protected function credential_field( string $key, string $label, bool $required = false, string $placeholder = '' ): array {
		return [
			'key'         => $key,
			'label'       => $label,
			'required'    => $required,
			'placeholder' => $placeholder,
		];
	}

	/**
	 * The last error from a get_lists() call, for the config UI. Null on success.
	 *
	 * @var string|null
	 */
	protected ?string $list_error = null;

	/**
	 * The last get_lists() error message (e.g. an auth failure), or null.
	 */
	public function last_list_error(): ?string {
		return $this->list_error;
	}

	/**
	 * Providers with list selection should override; default is no remote lists.
	 *
	 * @param ProviderConnection $connection Connection.
	 * @return array<int, array{id:string,name:string}>
	 */
	public function get_lists( ProviderConnection $connection ): array {
		return [];
	}

	/**
	 * Map a contact to a provider field payload using the connection field map.
	 *
	 * The map is `local_key => provider_key`. Standard fields fall back to
	 * sensible provider-agnostic keys when unmapped.
	 *
	 * @param Contact            $contact    Contact.
	 * @param ProviderConnection $connection Connection (holds field_map).
	 * @return array<string, mixed>
	 */
	protected function map_fields( Contact $contact, ProviderConnection $connection ): array {
		$standard = array_filter(
			[
				'first_name' => $contact->first_name,
				'last_name'  => $contact->last_name,
				'phone'      => PhoneNumber::to_e164( $contact->phone, $contact->country ) ?? $contact->phone,
				'company'    => $contact->company,
				'country'    => $contact->country,
			],
			static fn ( $v ): bool => null !== $v && '' !== $v
		);

		$all    = array_merge( $standard, $contact->fields );
		$mapped = [];

		foreach ( $all as $local_key => $value ) {
			$provider_key            = $connection->field_map[ $local_key ] ?? $local_key;
			$mapped[ $provider_key ] = $value;
		}

		return $mapped;
	}

	/**
	 * Perform an authenticated HTTP request and normalise the outcome.
	 *
	 * Network errors and 429/5xx responses become *transient* failures (the
	 * queue will retry); 4xx become permanent failures.
	 *
	 * @param string                $method  HTTP method.
	 * @param string                $url     Endpoint URL.
	 * @param array<string, mixed>  $body    Request body (JSON-encoded).
	 * @param array<string, string> $headers Request headers.
	 * @return SyncResult
	 */
	protected function request( string $method, string $url, array $body = [], array $headers = [] ): SyncResult {
		$args = [
			'method'     => strtoupper( $method ),
			'timeout'    => 15,
			// Identify the client explicitly. Some provider edges (Cloudflare bot
			// protection, WAFs) challenge or block the generic default WordPress
			// user agent; a clear product UA reduces false positives.
			'user-agent' => 'MailPilot/' . ( defined( 'MAILPILOT_VERSION' ) ? MAILPILOT_VERSION : '1.0' ) . ' (+' . home_url( '/' ) . ')',
			'headers'    => array_merge(
				[
					'Content-Type' => 'application/json',
					'Accept'       => 'application/json',
				],
				$headers
			),
		];

		if ( $body && 'GET' !== $args['method'] ) {
			$args['body'] = (string) wp_json_encode( $body );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			/* translators: %s: underlying network/TLS error message. */
			return SyncResult::transient( sprintf( __( 'Could not reach the provider: %s', 'brainstudioz-mailpilot' ), $response->get_error_message() ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		$raw    = (string) wp_remote_retrieve_body( $response );
		$data   = json_decode( $raw, true );
		$data   = is_array( $data ) ? $data : [];

		if ( $status >= 200 && $status < 300 ) {
			return SyncResult::success( '', $data );
		}

		$message = (string) ( $data['detail'] ?? $data['message'] ?? $data['title'] ?? '' );

		// No structured error field: surface the HTTP status (and a short body
		// snippet) so the real cause — auth, plan limits, a non-JSON error page,
		// or a blocked request — is visible instead of a generic failure.
		if ( '' === $message ) {
			$snippet = trim( (string) wp_strip_all_tags( $raw ) );
			$snippet = '' !== $snippet ? ' — ' . ( function_exists( 'mb_substr' ) ? mb_substr( $snippet, 0, 120 ) : substr( $snippet, 0, 120 ) ) : '';

			$message = 0 === $status
				? __( 'Could not reach the provider (no response — check the site can make outbound HTTPS requests).', 'brainstudioz-mailpilot' )
				/* translators: 1: HTTP status code, 2: optional response snippet. */
				: sprintf( __( 'Provider request failed (HTTP %1$d)%2$s', 'brainstudioz-mailpilot' ), $status, $snippet );
		}

		// 429 (rate limit) and 5xx are worth retrying; other 4xx are permanent.
		if ( 429 === $status || $status >= 500 ) {
			return SyncResult::transient( $message, $status );
		}

		return SyncResult::failure( $message, $status );
	}
}
