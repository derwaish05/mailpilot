<?php
/**
 * Generic service registry.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot\Registry;

use MailPilot\Contracts\Registrable;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Keyed collection of Registrable items.
 *
 * Backs the core extension points (provider/integration/routing/module
 * registries). Add-ons register through these during the registration hooks —
 * they never edit core files. Part of the public, versioned extension API.
 */
final class Registry {

	/**
	 * Registered items, keyed by id.
	 *
	 * @var array<string, Registrable>
	 */
	private array $items = [];

	/**
	 * Human-readable registry name, used in error messages.
	 */
	public function __construct( private string $name ) {}

	/**
	 * Register an item. Later registrations override earlier ones with the same id.
	 *
	 * @param Registrable $item Item to register.
	 * @return Registry Self, for chaining.
	 */
	public function add( Registrable $item ): self {
		$this->items[ $item->id() ] = $item;

		return $this;
	}

	/**
	 * Whether an item with the given id is registered.
	 *
	 * @param string $id Item id.
	 */
	public function has( string $id ): bool {
		return isset( $this->items[ $id ] );
	}

	/**
	 * Retrieve a registered item, or null if absent.
	 *
	 * @param string $id Item id.
	 */
	public function get( string $id ): ?Registrable {
		return $this->items[ $id ] ?? null;
	}

	/**
	 * Remove an item (e.g. when an add-on deactivates).
	 *
	 * @param string $id Item id.
	 */
	public function remove( string $id ): void {
		unset( $this->items[ $id ] );
	}

	/**
	 * All registered items, keyed by id.
	 *
	 * @return array<string, Registrable>
	 */
	public function all(): array {
		return $this->items;
	}

	/**
	 * Registered ids.
	 *
	 * @return array<int, string>
	 */
	public function ids(): array {
		return array_keys( $this->items );
	}

	/**
	 * Number of registered items.
	 */
	public function count(): int {
		return count( $this->items );
	}

	/**
	 * The registry name.
	 */
	public function name(): string {
		return $this->name;
	}
}
