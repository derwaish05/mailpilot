<?php
/**
 * Lightweight PSR-4 autoloader.
 *
 * Used only when the Composer autoloader is unavailable, so the plugin runs
 * from a clean checkout without a `composer install` step.
 *
 * @package MailPilot
 */

declare( strict_types=1 );

namespace MailPilot;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Maps a single PSR-4 namespace prefix to a base directory.
 */
final class Autoloader {

	/**
	 * Namespace prefix, with a trailing separator (e.g. "MailPilot\").
	 *
	 * @var string
	 */
	private string $prefix;

	/**
	 * Absolute base directory for the prefix, with a trailing slash.
	 *
	 * @var string
	 */
	private string $base_dir;

	/**
	 * @param string $prefix   Namespace prefix.
	 * @param string $base_dir Base directory for class files.
	 */
	public function __construct( string $prefix, string $base_dir ) {
		$this->prefix   = rtrim( $prefix, '\\' ) . '\\';
		$this->base_dir = rtrim( $base_dir, '/\\' ) . '/';
	}

	/**
	 * Register the autoloader on the SPL stack.
	 */
	public function register(): void {
		spl_autoload_register( [ $this, 'load' ] );
	}

	/**
	 * Resolve and require a class file.
	 *
	 * @param string $class Fully-qualified class name.
	 */
	public function load( string $class ): void {
		if ( ! str_starts_with( $class, $this->prefix ) ) {
			return;
		}

		$relative = substr( $class, strlen( $this->prefix ) );
		$file     = $this->base_dir . str_replace( '\\', '/', $relative ) . '.php';

		if ( is_readable( $file ) ) {
			require $file;
		}
	}
}
