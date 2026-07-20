<?php
/**
 * Normalised contact passed to provider adapters.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Providers;

use MailPilot\Subscribers\Subscriber;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provider-agnostic contact shape. Adapters translate this into each
 * provider's own payload via the connection's field map.
 */
final class Contact {

	/**
	 * @param string                $email       Email address.
	 * @param string|null           $first_name  First name.
	 * @param string|null           $last_name   Last name.
	 * @param string|null           $phone       Phone.
	 * @param string|null           $company     Company.
	 * @param string|null           $country     ISO country code.
	 * @param array<int, string>    $tags        Tags to apply.
	 * @param array<int, string>    $lists       Provider list ids.
	 * @param array<string, mixed>  $fields      Custom field values keyed by local field key.
	 */
	public function __construct(
		public string $email,
		public ?string $first_name = null,
		public ?string $last_name = null,
		public ?string $phone = null,
		public ?string $company = null,
		public ?string $country = null,
		public array $tags = [],
		public array $lists = [],
		public array $fields = [],
	) {}

	/**
	 * Build a contact from a subscriber entity.
	 *
	 * @param Subscriber         $subscriber Source subscriber.
	 * @param array<int, string> $tags       Tags to apply.
	 * @param array<int, string> $lists      Provider list ids.
	 */
	public static function fromSubscriber( Subscriber $subscriber, array $tags = [], array $lists = [] ): self {
		return new self(
			email: $subscriber->email,
			first_name: $subscriber->first_name,
			last_name: $subscriber->last_name,
			phone: $subscriber->phone,
			company: $subscriber->company,
			country: $subscriber->country,
			tags: $tags,
			lists: $lists,
			fields: $subscriber->meta,
		);
	}
}
