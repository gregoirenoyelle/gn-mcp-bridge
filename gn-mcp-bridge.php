<?php
/**
 * Plugin bootstrap: registers the MCP server and its abilities.
 *
 * @package     gn-mcp-bridge
 * @author      Gregoire Noyelle
 * @license     GPL-2.0-or-later
 * @link        https://www.gregoirenoyelle.com/
 * @since       1.0.0
 *
 * Plugin Name: GN MCP Bridge
 * Plugin URI:  https://www.gregoirenoyelle.com/
 * Description: Exposes WordPress content abilities as an MCP server for Claude Code, with per-role capability filters. To adjust what's exposed and for whom, open a Claude Code session in this plugin's folder and run /gnmcp-setup-site.
 * Version:     1.5.1
 * Author:      Gregoire Noyelle
 * Author URI:  https://www.gregoirenoyelle.com/
 * License:     GPL-2.0-or-later
 * Text Domain: gn-mcp-bridge
 * Requires Plugins: mcp-adapter
 */

namespace GN\McpBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GN_MCP_BRIDGE_PATH', plugin_dir_path( __FILE__ ) );
define( 'GN_MCP_BRIDGE_URL', plugin_dir_url( __FILE__ ) );

require_once __DIR__ . '/src/autoload.php';
require_once __DIR__ . '/src/Server.php';

use GN\McpBridge\Abilities\SearchPostsAbility;
use GN\McpBridge\Abilities\GetPostAbility;
use GN\McpBridge\Abilities\GetPostOutlineAbility;
use GN\McpBridge\Abilities\GetBlockAbility;
use GN\McpBridge\Abilities\CreatePostAbility;
use GN\McpBridge\Abilities\UpdatePostAbility;
use GN\McpBridge\Abilities\UpdateBlockAbility;
use GN\McpBridge\Abilities\DeletePostAbility;
use GN\McpBridge\Abilities\GetPostMetaAbility;
use GN\McpBridge\Abilities\UpdatePostMetaAbility;
use GN\McpBridge\Abilities\ListCategoriesAbility;

/**
 * Register the gn-mcp ability category.
 *
 * @since 1.0.0
 * @return void
 */
function register_ability_category() {
	wp_register_ability_category( 'gn-mcp', array(
		'label'       => __( 'GN MCP Bridge', 'gn-mcp-bridge' ),
		'description' => __( 'Content management abilities exposed to Claude Code via MCP.', 'gn-mcp-bridge' ),
	) );
}

/**
 * Register all plugin abilities.
 *
 * @since 1.0.0
 * @return void
 */
function register_abilities() {
	SearchPostsAbility::register();
	GetPostAbility::register();
	GetPostOutlineAbility::register();
	GetBlockAbility::register();
	CreatePostAbility::register();
	UpdatePostAbility::register();
	UpdateBlockAbility::register();
	DeletePostAbility::register();
	GetPostMetaAbility::register();
	UpdatePostMetaAbility::register();
	ListCategoriesAbility::register();
}

/**
 * Minimum mcp-adapter version this plugin was verified against.
 *
 * @since 1.0.0
 * @var string
 */
const GN_MCP_BRIDGE_MIN_ADAPTER_VERSION = '0.7.0';

/**
 * Check that mcp-adapter is active and meets the minimum version.
 *
 * `Requires Plugins: mcp-adapter` in the header already blocks activation
 * when mcp-adapter is missing, but not: mcp-adapter being deactivated
 * afterward, or an older/incompatible version being active.
 *
 * @since 1.0.0
 * @return void
 */
function check_mcp_adapter_dependency() {
	if ( ! defined( 'WP_MCP_VERSION' ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\notice_mcp_adapter_missing' );
		return;
	}

	if ( version_compare( WP_MCP_VERSION, GN_MCP_BRIDGE_MIN_ADAPTER_VERSION, '<' ) ) {
		add_action( 'admin_notices', __NAMESPACE__ . '\notice_mcp_adapter_outdated' );
	}
}

/**
 * Admin notice: mcp-adapter is missing or inactive.
 *
 * @since 1.0.0
 * @return void
 */
function notice_mcp_adapter_missing() {
	printf(
		'<div class="notice notice-error"><p>%s</p></div>',
		esc_html__( 'GN MCP Bridge requires the "MCP Adapter" plugin to be installed and active.', 'gn-mcp-bridge' )
	);
}

/**
 * Admin notice: mcp-adapter is active but below the verified minimum version.
 *
 * @since 1.0.0
 * @return void
 */
function notice_mcp_adapter_outdated() {
	printf(
		'<div class="notice notice-warning"><p>%s</p></div>',
		esc_html(
			sprintf(
				/* translators: 1: active mcp-adapter version, 2: minimum verified version */
				__( 'GN MCP Bridge: the active "MCP Adapter" version (%1$s) is older than the minimum verified (%2$s). The MCP transport behavior (protocol revisions, required headers) may differ — see CLAUDE.md.', 'gn-mcp-bridge' ),
				WP_MCP_VERSION,
				GN_MCP_BRIDGE_MIN_ADAPTER_VERSION
			)
		)
	);
}

// Hooks.
add_action( 'admin_init', __NAMESPACE__ . '\check_mcp_adapter_dependency' );
add_action( 'wp_abilities_api_categories_init', __NAMESPACE__ . '\register_ability_category' );
add_action( 'wp_abilities_api_init', __NAMESPACE__ . '\register_abilities' );
