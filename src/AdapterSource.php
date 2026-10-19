<?php
/**
 * Detection of a bundled (non-standalone) MCP Adapter copy.
 *
 * @package gn-mcp-bridge
 * @since   1.7.1
 */

namespace GN\McpBridge;

/**
 * Tells whether the loaded MCP Adapter comes from the standalone plugin.
 *
 * Some plugins (BackWPup, Gravity Forms, WooCommerce...) ship their own copy of
 * mcp-adapter in `vendor/` and register a Composer autoloader for `WP\MCP\`.
 * When one of them loads before `mcp-adapter/` (plugins load alphabetically),
 * its copy wins: the standalone plugin detects it, refuses to boot, and never
 * defines `WP_MCP_VERSION` — which would otherwise look like "not installed".
 *
 * @since 1.7.1
 */
class AdapterSource {

	/**
	 * Fully qualified class name of the adapter's main class.
	 *
	 * @since 1.7.1
	 * @var string
	 */
	const ADAPTER_CLASS = 'WP\\MCP\\Core\\McpAdapter';

	/**
	 * Folder slug of the standalone MCP Adapter plugin.
	 *
	 * @since 1.7.1
	 * @var string
	 */
	const STANDALONE_SLUG = 'mcp-adapter';

	/**
	 * Plugin folder slug containing a file, or null when outside the plugins directory.
	 *
	 * @since 1.7.1
	 * @param string $file        Absolute path of a PHP file.
	 * @param string $plugins_dir Absolute path of the plugins directory.
	 * @return string|null Folder slug (e.g. `backwpup`), or null.
	 */
	public static function plugin_slug( $file, $plugins_dir ) {
		$file        = str_replace( '\\', '/', $file );
		$plugins_dir = rtrim( str_replace( '\\', '/', $plugins_dir ), '/' ) . '/';

		if ( 0 !== strpos( $file, $plugins_dir ) ) {
			return null;
		}

		$relative = substr( $file, strlen( $plugins_dir ) );
		$slash    = strpos( $relative, '/' );

		return false === $slash ? null : substr( $relative, 0, $slash );
	}

	/**
	 * Describe the loaded adapter copy when it isn't the standalone plugin's.
	 *
	 * @since 1.7.1
	 * @return array{file: string, slug: string|null, version: string}|null Null when no adapter is loaded, or when it's the standalone one.
	 */
	public static function bundled_copy() {
		if ( ! class_exists( self::ADAPTER_CLASS ) ) {
			return null;
		}

		$file        = (string) ( new \ReflectionClass( self::ADAPTER_CLASS ) )->getFileName();
		$plugins_dir = realpath( WP_PLUGIN_DIR );
		$slug        = self::plugin_slug( $file, false === $plugins_dir ? WP_PLUGIN_DIR : $plugins_dir );

		if ( self::STANDALONE_SLUG === $slug ) {
			return null;
		}

		$class = self::ADAPTER_CLASS;

		return array(
			'file'    => $file,
			'slug'    => $slug,
			'version' => defined( $class . '::VERSION' ) ? (string) constant( $class . '::VERSION' ) : '?',
		);
	}
}
