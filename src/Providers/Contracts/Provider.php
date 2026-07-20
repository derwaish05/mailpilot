<?php
/**
 * Provider adapter contract.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Providers\Contracts;

use MailPilot\Contracts\Registrable;
use MailPilot\Providers\Capabilities;
use MailPilot\Providers\Contact;
use MailPilot\Providers\ProviderConnection;
use MailPilot\Providers\SyncResult;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * The common interface every provider integration implements (ADR-002).
 *
 * Part of the core's public, versioned extension API: Pro registers premium
 * providers through `mailpilot_register_providers`, and they must conform to
 * this contract with no provider-specific branching in core.
 */
interface Provider extends Registrable {

	/**
	 * Configuration features this provider supports.
	 */
	public function capabilities(): Capabilities;

	/**
	 * Create a contact at the provider.
	 *
	 * @param Contact            $contact    Normalised contact.
	 * @param ProviderConnection $connection Configured connection.
	 */
	public function create_contact( Contact $contact, ProviderConnection $connection ): SyncResult;

	/**
	 * Update an existing contact at the provider.
	 *
	 * @param Contact            $contact    Normalised contact.
	 * @param ProviderConnection $connection Configured connection.
	 */
	public function update_contact( Contact $contact, ProviderConnection $connection ): SyncResult;

	/**
	 * Delete a contact at the provider.
	 *
	 * @param string             $email      Contact email.
	 * @param ProviderConnection $connection Configured connection.
	 */
	public function delete_contact( string $email, ProviderConnection $connection ): SyncResult;

	/**
	 * Apply tags to a contact.
	 *
	 * @param string             $email      Contact email.
	 * @param array<int, string> $tags       Tags to apply.
	 * @param ProviderConnection $connection Configured connection.
	 */
	public function apply_tags( string $email, array $tags, ProviderConnection $connection ): SyncResult;

	/**
	 * Remove tags from a contact.
	 *
	 * @param string             $email      Contact email.
	 * @param array<int, string> $tags       Tags to remove.
	 * @param ProviderConnection $connection Configured connection.
	 */
	public function remove_tags( string $email, array $tags, ProviderConnection $connection ): SyncResult;

	/**
	 * Fetch selectable lists for the connection (for the config UI).
	 *
	 * @param ProviderConnection $connection Configured connection.
	 * @return array<int, array{id:string,name:string}>
	 */
	public function get_lists( ProviderConnection $connection ): array;
}
