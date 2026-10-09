<?php
/**
 * PSR-4 autoloader for the GN\McpBridge namespace.
 *
 * @package gn-mcp-bridge
 * @since   1.0.0
 */

spl_autoload_register( function( $class ) {
	$prefix   = 'GN\\McpBridge\\';
	$base_dir = __DIR__ . '/';
	$len      = strlen( $prefix );

	if ( strncmp( $prefix, $class, $len ) !== 0 ) {
		return;
	}

	$relative_class = substr( $class, $len );
	$file           = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

	if ( file_exists( $file ) ) {
		require $file;
	}
} );
