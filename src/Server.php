<?php
/**
 * Declares the gn-mcp MCP server on top of mcp-adapter.
 *
 * @package gn-mcp-bridge
 * @since   1.0.0
 */

namespace GN\McpBridge;

use WP\MCP\Core\McpAdapter;
use WP\MCP\Transport\HttpTransport;
use WP\MCP\Infrastructure\ErrorHandling\ErrorLogMcpErrorHandler;
use WP\MCP\Infrastructure\Observability\NullMcpObservabilityHandler;

/**
 * Registers the gn-mcp custom MCP server.
 *
 * @since 1.0.0
 */
class Server {

	/**
	 * Register the gn-mcp server with mcp-adapter.
	 *
	 * @since 1.0.0
	 * @param McpAdapter $adapter The MCP adapter instance.
	 * @return void
	 */
	public static function register( McpAdapter $adapter ) {
		$adapter->create_server(
			'gn-mcp',
			'gn-mcp',
			'mcp',
			__( 'GN MCP Bridge', 'gn-mcp-bridge' ),
			__( 'Content management abilities exposed to Claude Code via MCP.', 'gn-mcp-bridge' ),
			'1.0.0',
			array( HttpTransport::class ),
			ErrorLogMcpErrorHandler::class,
			NullMcpObservabilityHandler::class,
			Config::exposed_abilities()
		);
	}
}

/*
 * Transport-level guard: the endpoint defaults to requiring only the `read`
 * capability (any logged-in user). Routed through Config::transport_capability()
 * rather than left to that default, so a theme can raise the bar in code.
 */
add_filter( 'mcp_adapter_default_transport_permission_user_capability', array( __NAMESPACE__ . '\Config', 'transport_capability' ) );

/*
 * mcp-adapter auto-creates its own default server (mcp-adapter-default-server,
 * /wp-json/mcp/mcp-adapter-default-server, plus a WP-CLI STDIO transport)
 * exposing any ability marked meta.public. Disabled: this site is meant to
 * expose abilities only through the gn-mcp server declared above, with its
 * own filterable capability guards — not through a second, unmanaged surface.
 */
add_filter( 'mcp_adapter_create_default_server', '__return_false' );

add_action( 'mcp_adapter_init', array( __NAMESPACE__ . '\Server', 'register' ) );
