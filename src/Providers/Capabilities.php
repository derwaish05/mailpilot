<?php
/**
 * Provider capability descriptor.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Providers;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Declares which configuration features a provider supports, so the admin UI
 * can render only the relevant controls.
 *
 * @see doc/06-technical-spec.md (Provider Interface).
 */
final class Capabilities {

	public function __construct(
		public bool $api_key_auth = true,
		public bool $list_selection = true,
		public bool $tag_selection = true,
		public bool $group_selection = false,
		public bool $double_opt_in = true,
		public bool $custom_field_mapping = true,
	) {}

	/**
	 * Export as an array for serialisation / JS.
	 *
	 * @return array<string, bool>
	 */
	public function toArray(): array {
		return [
			'api_key_auth'         => $this->api_key_auth,
			'list_selection'       => $this->list_selection,
			'tag_selection'        => $this->tag_selection,
			'group_selection'      => $this->group_selection,
			'double_opt_in'        => $this->double_opt_in,
			'custom_field_mapping' => $this->custom_field_mapping,
		];
	}
}
