<?php
/**
 * Subscriber entity.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Subscribers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * In-memory representation of a `wp_mailpilot_subscribers` row.
 *
 * A plain data object: the engine and repository own persistence and rules.
 */
final class Subscriber {

	/**
	 * @param int|null              $id         Primary key, null until persisted.
	 * @param string                $email      Lower-cased email (unique key).
	 * @param string|null           $first_name First name.
	 * @param string|null           $last_name  Last name.
	 * @param string|null           $phone      Phone.
	 * @param string|null           $company    Company.
	 * @param string|null           $country    ISO-3166 alpha-2 country code.
	 * @param Status                $status     Lifecycle status.
	 * @param Source                $source     Origin.
	 * @param string|null           $ip_address Capture IP.
	 * @param string|null           $consent_at GDPR consent timestamp (UTC mysql).
	 * @param array<string, mixed>  $meta       Arbitrary custom fields.
	 * @param string|null           $created_at Created timestamp (UTC mysql).
	 * @param string|null           $updated_at Updated timestamp (UTC mysql).
	 */
	public function __construct(
		public ?int $id = null,
		public string $email = '',
		public ?string $first_name = null,
		public ?string $last_name = null,
		public ?string $phone = null,
		public ?string $company = null,
		public ?string $country = null,
		public Status $status = Status::Pending,
		public Source $source = Source::Manual,
		public ?string $ip_address = null,
		public ?string $consent_at = null,
		public array $meta = [],
		public ?string $created_at = null,
		public ?string $updated_at = null,
	) {}

	/**
	 * Build an entity from a raw DB row.
	 *
	 * @param array<string, mixed>|object $row Row from `wp_mailpilot_subscribers`.
	 */
	public static function fromRow( array|object $row ): self {
		$row  = (array) $row;
		$meta = isset( $row['meta'] ) ? json_decode( (string) $row['meta'], true ) : [];

		return new self(
			id: isset( $row['id'] ) ? (int) $row['id'] : null,
			email: (string) ( $row['email'] ?? '' ),
			first_name: self::nullable( $row['first_name'] ?? null ),
			last_name: self::nullable( $row['last_name'] ?? null ),
			phone: self::nullable( $row['phone'] ?? null ),
			company: self::nullable( $row['company'] ?? null ),
			country: self::nullable( $row['country'] ?? null ),
			status: Status::fromString( $row['status'] ?? null ),
			source: Source::fromString( $row['source'] ?? null ),
			ip_address: self::nullable( $row['ip_address'] ?? null ),
			consent_at: self::nullable( $row['consent_at'] ?? null ),
			meta: is_array( $meta ) ? $meta : [],
			created_at: self::nullable( $row['created_at'] ?? null ),
			updated_at: self::nullable( $row['updated_at'] ?? null ),
		);
	}

	/**
	 * Full name, or the email local-part when no name is set.
	 */
	public function display_name(): string {
		$name = trim( (string) $this->first_name . ' ' . (string) $this->last_name );

		if ( '' !== $name ) {
			return $name;
		}

		return (string) strstr( $this->email . '@', '@', true );
	}

	/**
	 * Column => value map for persistence (excludes id and timestamps).
	 *
	 * @return array<string, mixed>
	 */
	public function toColumns(): array {
		return [
			'email'      => $this->email,
			'first_name' => $this->first_name,
			'last_name'  => $this->last_name,
			'phone'      => $this->phone,
			'company'    => $this->company,
			'country'    => $this->country,
			'status'     => $this->status->value,
			'source'     => $this->source->value,
			'ip_address' => $this->ip_address,
			'consent_at' => $this->consent_at,
			'meta'       => wp_json_encode( $this->meta ),
		];
	}

	/**
	 * Normalise empty/whitespace values to null.
	 *
	 * @param mixed $value Raw value.
	 */
	private static function nullable( mixed $value ): ?string {
		if ( null === $value ) {
			return null;
		}

		$value = (string) $value;

		return '' === trim( $value ) ? null : $value;
	}
}
