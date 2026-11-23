<?php
/**
 * Plugin Name: WPCodeBox 2 CLI
 * Description: WP-CLI commands for managing WPCodeBox 2 snippets and folders without the browser UI. Writes directly to the wpcb_snippets/wpcb_folders tables using the same shape WPCodeBox 2's own API layer produces.
 * Version: 1.0.1
 * Author: Michael Wender
 * Author URI: https://mwender.com/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Builds a wpcb_snippets row with every NOT NULL column explicitly set,
 * matching the CREATE TABLE in wpcodebox2.php. WPCodeBox2's own Api class
 * relies on lenient (non-strict) SQL modes to omit several of these; we
 * don't, so every column gets a safe default here.
 */
function wpcb2_cli_base_row() {
	return array(
		'title'             => '',
		'description'       => '',
		'enabled'           => 0,
		'priority'          => 10,
		'runType'           => 'always',
		'code'              => '',
		'original_code'     => '',
		'codeType'          => 'php',
		'conditions'        => '[]',
		'location'          => 'header',
		'tagOptions'        => '',
		'hook'              => '[]',
		'renderType'        => null,
		'minify'            => 0,
		'snippet_order'     => -1,
		'addToQuickActions' => 0,
		'savedToCloud'      => 0,
		'remoteId'          => 0,
		'externalUrl'       => 0,
		'secret'            => '',
		'folderId'          => 0,
		'error'             => 0,
		'errorMessage'      => '',
		'errorTrace'        => '',
		'errorLine'         => 0,
		'devMode'           => 0,
		'lastModified'      => (string) time(),
	);
}

/**
 * WPCodeBox2's hook column is a JSON array of {hook:{value,label}, customAction?, priority?}.
 * `custom_*` aliases map to real WP hooks inside the plugin (Snippet.php::mapCustomHookToWPHooks);
 * anything else is treated as a literal WP hook name via the custom_custom_action passthrough,
 * so any real action name (admin_menu, init, login_head, ...) can be targeted directly.
 */
function wpcb2_cli_build_hook( $hook_alias, $custom_action, $priority ) {
	$aliases = array(
		'plugins_loaded'  => 'custom_plugins_loaded',
		'frontend_header' => 'custom_frontend_header',
		'frontend_footer' => 'custom_frontend_footer',
		'login_header'    => 'custom_login_header',
		'login_footer'    => 'custom_login_footer',
		'admin_header'    => 'custom_admin_header',
		'admin_footer'    => 'custom_admin_footer',
		'root'            => 'root',
	);

	$entry = array();

	if ( $custom_action ) {
		$entry['hook']         = array( 'value' => 'custom_custom_action', 'label' => 'Custom Action' );
		$entry['customAction'] = $custom_action;
	} elseif ( isset( $aliases[ $hook_alias ] ) ) {
		$entry['hook'] = array( 'value' => $aliases[ $hook_alias ] );
	} else {
		// Unknown alias: treat as a literal WP hook name.
		$entry['hook']         = array( 'value' => 'custom_custom_action', 'label' => 'Custom Action' );
		$entry['customAction'] = $hook_alias;
	}

	if ( null !== $priority ) {
		$entry['priority'] = (int) $priority;
	}

	return array( $entry );
}

function wpcb2_cli_default_hook_alias( $code_type ) {
	return 'php' === $code_type ? 'plugins_loaded' : 'frontend_header';
}

function wpcb2_cli_resolve_code( $assoc_args ) {
	$code_file = WP_CLI\Utils\get_flag_value( $assoc_args, 'code-file' );
	if ( $code_file ) {
		if ( ! file_exists( $code_file ) ) {
			WP_CLI::error( "Code file not found: $code_file" );
		}
		return file_get_contents( $code_file );
	}

	$code = WP_CLI\Utils\get_flag_value( $assoc_args, 'code' );
	if ( null !== $code ) {
		return $code;
	}

	WP_CLI::error( 'Provide --code=<code> or --code-file=<path>.' );
}

function wpcb2_cli_snippets_table() {
	global $wpdb;
	return $wpdb->prefix . 'wpcb_snippets';
}

function wpcb2_cli_folders_table() {
	global $wpdb;
	return $wpdb->prefix . 'wpcb_folders';
}

function wpcb2_cli_snippet_fields() {
	return array( 'id', 'title', 'codeType', 'runType', 'enabled', 'priority', 'hook', 'folderId', 'lastModified' );
}

/**
 * wp wpcb2 snippet create <title>
 */
function wpcb2_cli_snippet_create( $args, $assoc_args ) {
	global $wpdb;

	list( $title ) = $args;

	$type = WP_CLI\Utils\get_flag_value( $assoc_args, 'type', 'php' );
	$code = wpcb2_cli_resolve_code( $assoc_args );

	$row                  = wpcb2_cli_base_row();
	$row['title']         = $title;
	$row['description']   = WP_CLI\Utils\get_flag_value( $assoc_args, 'description', '' );
	$row['codeType']      = $type;
	$row['code']          = $code;
	$row['original_code'] = $code;
	$row['runType']       = WP_CLI\Utils\get_flag_value( $assoc_args, 'run-type', 'always' );
	$row['location']      = WP_CLI\Utils\get_flag_value( $assoc_args, 'location', 'header' );
	$row['folderId']      = (int) WP_CLI\Utils\get_flag_value( $assoc_args, 'folder', 0 );
	$row['enabled']       = WP_CLI\Utils\get_flag_value( $assoc_args, 'enabled', false ) ? 1 : 0;
	$row['lastModified']  = (string) time();

	$hook_alias    = WP_CLI\Utils\get_flag_value( $assoc_args, 'hook', null );
	$custom_action = WP_CLI\Utils\get_flag_value( $assoc_args, 'custom-action', null );
	$priority      = WP_CLI\Utils\get_flag_value( $assoc_args, 'priority', null );

	if ( ! $hook_alias && ! $custom_action ) {
		$hook_alias = wpcb2_cli_default_hook_alias( $type );
	}

	$row['hook'] = wp_json_encode( wpcb2_cli_build_hook( $hook_alias, $custom_action, $priority ) );

	if ( 'php' === $type ) {
		$row['secret'] = sha1( uniqid( '', true ) . wp_salt() );
	}

	$inserted = $wpdb->insert( wpcb2_cli_snippets_table(), $row );

	if ( false === $inserted ) {
		WP_CLI::error( 'Insert failed: ' . $wpdb->last_error );
	}

	WP_CLI::success( 'Created snippet #' . $wpdb->insert_id . " ($title)" );
}

/**
 * wp wpcb2 snippet update <id>
 */
function wpcb2_cli_snippet_update( $args, $assoc_args ) {
	global $wpdb;

	$id = (int) $args[0];

	$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . wpcb2_cli_snippets_table() . ' WHERE id = %d', $id ), ARRAY_A );
	if ( ! $existing ) {
		WP_CLI::error( "Snippet #$id not found." );
	}

	$row = array();

	if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'title', null ) !== null ) {
		$row['title'] = $assoc_args['title'];
	}
	if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'description', null ) !== null ) {
		$row['description'] = $assoc_args['description'];
	}
	if ( isset( $assoc_args['code'] ) || isset( $assoc_args['code-file'] ) ) {
		$code                  = wpcb2_cli_resolve_code( $assoc_args );
		$row['code']           = $code;
		$row['original_code']  = $code;
	}
	if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'run-type', null ) !== null ) {
		$row['runType'] = $assoc_args['run-type'];
	}
	if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'location', null ) !== null ) {
		$row['location'] = $assoc_args['location'];
	}
	if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'folder', null ) !== null ) {
		$row['folderId'] = (int) $assoc_args['folder'];
	}
	if ( WP_CLI\Utils\get_flag_value( $assoc_args, 'priority', null ) !== null ) {
		$row['priority'] = (int) $assoc_args['priority'];
	}
	if ( isset( $assoc_args['hook'] ) || isset( $assoc_args['custom-action'] ) ) {
		$row['hook'] = wp_json_encode( wpcb2_cli_build_hook(
			WP_CLI\Utils\get_flag_value( $assoc_args, 'hook', null ),
			WP_CLI\Utils\get_flag_value( $assoc_args, 'custom-action', null ),
			WP_CLI\Utils\get_flag_value( $assoc_args, 'priority', null )
		) );
	}
	if ( isset( $assoc_args['enabled'] ) ) {
		$row['enabled'] = 1;
	}
	if ( isset( $assoc_args['disabled'] ) ) {
		$row['enabled'] = 0;
	}

	if ( empty( $row ) ) {
		WP_CLI::error( 'Nothing to update — pass at least one field flag.' );
	}

	$row['lastModified'] = (string) time();

	$updated = $wpdb->update( wpcb2_cli_snippets_table(), $row, array( 'id' => $id ) );

	if ( false === $updated ) {
		WP_CLI::error( 'Update failed: ' . $wpdb->last_error );
	}

	WP_CLI::success( "Updated snippet #$id." );
}

/**
 * wp wpcb2 snippet get <id>
 */
function wpcb2_cli_snippet_get( $args, $assoc_args ) {
	global $wpdb;

	$id      = (int) $args[0];
	$snippet = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM ' . wpcb2_cli_snippets_table() . ' WHERE id = %d', $id ), ARRAY_A );

	if ( ! $snippet ) {
		WP_CLI::error( "Snippet #$id not found." );
	}

	$field = WP_CLI\Utils\get_flag_value( $assoc_args, 'field', null );
	if ( $field ) {
		if ( ! array_key_exists( $field, $snippet ) ) {
			WP_CLI::error( "Unknown field: $field" );
		}
		WP_CLI::line( $snippet[ $field ] );
		return;
	}

	$formatter = new WP_CLI\Formatter( $assoc_args, array_keys( $snippet ), 'snippet' );
	$formatter->display_item( $snippet );
}

/**
 * wp wpcb2 snippet list
 */
function wpcb2_cli_snippet_list( $args, $assoc_args ) {
	global $wpdb;

	$where = array( '1=1' );
	if ( isset( $assoc_args['folder'] ) ) {
		$where[] = $wpdb->prepare( 'folderId = %d', (int) $assoc_args['folder'] );
	}
	if ( isset( $assoc_args['enabled'] ) ) {
		$where[] = $wpdb->prepare( 'enabled = %d', $assoc_args['enabled'] ? 1 : 0 );
	}

	$sql      = 'SELECT * FROM ' . wpcb2_cli_snippets_table() . ' WHERE ' . implode( ' AND ', $where ) . ' ORDER BY id';
	$snippets = $wpdb->get_results( $sql, ARRAY_A );

	$fields = WP_CLI\Utils\get_flag_value( $assoc_args, 'fields', implode( ',', wpcb2_cli_snippet_fields() ) );

	$formatter = new WP_CLI\Formatter( $assoc_args, explode( ',', $fields ), 'snippet' );
	$formatter->display_items( $snippets );
}

/**
 * wp wpcb2 snippet delete <id>
 */
function wpcb2_cli_snippet_delete( $args, $assoc_args ) {
	global $wpdb;

	$id = (int) $args[0];

	$existing = $wpdb->get_row( $wpdb->prepare( 'SELECT id FROM ' . wpcb2_cli_snippets_table() . ' WHERE id = %d', $id ) );
	if ( ! $existing ) {
		WP_CLI::error( "Snippet #$id not found." );
	}

	$wpdb->delete( wpcb2_cli_snippets_table(), array( 'id' => $id ) );
	$wpdb->delete( $wpdb->prefix . 'wpcb_revisions', array( 'snippet_id' => $id ) );

	WP_CLI::success( "Deleted snippet #$id." );
}

function wpcb2_cli_snippet_enable( $args, $assoc_args ) {
	global $wpdb;
	$id = (int) $args[0];
	$wpdb->update( wpcb2_cli_snippets_table(), array( 'enabled' => 1, 'lastModified' => (string) time() ), array( 'id' => $id ) );
	WP_CLI::success( "Enabled snippet #$id." );
}

function wpcb2_cli_snippet_disable( $args, $assoc_args ) {
	global $wpdb;
	$id = (int) $args[0];
	$wpdb->update( wpcb2_cli_snippets_table(), array( 'enabled' => 0, 'lastModified' => (string) time() ), array( 'id' => $id ) );
	WP_CLI::success( "Disabled snippet #$id." );
}

/**
 * Idempotent create-or-update by title, driven by a `meta.json` + `code.txt`
 * pair in $dir. This is what makes deploy.sh safe to rerun: syncing the same
 * directory twice updates the existing row instead of duplicating it.
 *
 * meta.json fields: title (required), type, description, runType, enabled,
 * hook, customAction, priority — same vocabulary as `snippet create`'s flags.
 */
function wpcb2_cli_snippet_sync( $args, $assoc_args ) {
	global $wpdb;

	$dir       = rtrim( $args[0], '/' );
	$meta_path = $dir . '/meta.json';
	$code_path = $dir . '/code.txt';

	if ( ! file_exists( $meta_path ) ) {
		WP_CLI::error( "Missing meta.json in $dir" );
	}
	if ( ! file_exists( $code_path ) ) {
		WP_CLI::error( "Missing code.txt in $dir" );
	}

	$meta = json_decode( file_get_contents( $meta_path ), true );
	if ( ! is_array( $meta ) || empty( $meta['title'] ) ) {
		WP_CLI::error( "Invalid meta.json in $dir (needs at least a \"title\")" );
	}

	$code = file_get_contents( $code_path );

	$type          = isset( $meta['type'] ) ? $meta['type'] : 'php';
	$hook_alias    = isset( $meta['hook'] ) ? $meta['hook'] : null;
	$custom_action = isset( $meta['customAction'] ) ? $meta['customAction'] : null;
	$priority      = isset( $meta['priority'] ) ? (int) $meta['priority'] : null;

	if ( ! $hook_alias && ! $custom_action ) {
		$hook_alias = wpcb2_cli_default_hook_alias( $type );
	}

	$hook_json = wp_json_encode( wpcb2_cli_build_hook( $hook_alias, $custom_action, $priority ) );

	$existing = $wpdb->get_row( $wpdb->prepare(
		'SELECT id FROM ' . wpcb2_cli_snippets_table() . ' WHERE title = %s',
		$meta['title']
	), ARRAY_A );

	if ( $existing ) {
		$row = array(
			'code'          => $code,
			'original_code' => $code,
			'codeType'      => $type,
			'description'   => isset( $meta['description'] ) ? $meta['description'] : '',
			'runType'       => isset( $meta['runType'] ) ? $meta['runType'] : 'always',
			'hook'          => $hook_json,
			'enabled'       => ! empty( $meta['enabled'] ) ? 1 : 0,
			'lastModified'  => (string) time(),
		);

		$wpdb->update( wpcb2_cli_snippets_table(), $row, array( 'id' => $existing['id'] ) );
		WP_CLI::success( "Synced (updated) snippet #{$existing['id']}: {$meta['title']}" );
		return;
	}

	$row                  = wpcb2_cli_base_row();
	$row['title']         = $meta['title'];
	$row['description']   = isset( $meta['description'] ) ? $meta['description'] : '';
	$row['codeType']      = $type;
	$row['code']          = $code;
	$row['original_code'] = $code;
	$row['runType']       = isset( $meta['runType'] ) ? $meta['runType'] : 'always';
	$row['enabled']       = ! empty( $meta['enabled'] ) ? 1 : 0;
	$row['hook']          = $hook_json;
	$row['lastModified']  = (string) time();

	if ( 'php' === $type ) {
		$row['secret'] = sha1( uniqid( '', true ) . wp_salt() );
	}

	$wpdb->insert( wpcb2_cli_snippets_table(), $row );
	WP_CLI::success( 'Synced (created) snippet #' . $wpdb->insert_id . ": {$meta['title']}" );
}

/**
 * wp wpcb2 snippet sync-all <base-dir>
 * Runs `sync` for every immediate subdirectory of $base — one snippet per dir.
 */
function wpcb2_cli_snippet_sync_all( $args, $assoc_args ) {
	$base = rtrim( $args[0], '/' );

	if ( ! is_dir( $base ) ) {
		WP_CLI::error( "Not a directory: $base" );
	}

	$dirs = glob( $base . '/*', GLOB_ONLYDIR );

	if ( empty( $dirs ) ) {
		WP_CLI::warning( "No snippet directories found in $base" );
		return;
	}

	foreach ( $dirs as $dir ) {
		wpcb2_cli_snippet_sync( array( $dir ), array() );
	}
}

/**
 * wp wpcb2 folder list
 */
function wpcb2_cli_folder_list( $args, $assoc_args ) {
	global $wpdb;
	$folders   = $wpdb->get_results( 'SELECT * FROM ' . wpcb2_cli_folders_table() . ' ORDER BY id', ARRAY_A );
	$formatter = new WP_CLI\Formatter( $assoc_args, array( 'id', 'name', 'folder_order' ), 'folder' );
	$formatter->display_items( $folders );
}

/**
 * wp wpcb2 folder create <name>
 */
function wpcb2_cli_folder_create( $args, $assoc_args ) {
	global $wpdb;
	list( $name ) = $args;

	$wpdb->insert( wpcb2_cli_folders_table(), array(
		'name'         => $name,
		'savedToCloud' => 0,
		'remoteId'     => 0,
		'folder_order' => -1,
	) );

	WP_CLI::success( 'Created folder #' . $wpdb->insert_id . " ($name)" );
}

$wpcb2_snippet_synopsis_common = array(
	array( 'type' => 'assoc', 'name' => 'type', 'optional' => true, 'description' => 'php|css|scss|js|html|json|txt|md' ),
	array( 'type' => 'assoc', 'name' => 'code', 'optional' => true ),
	array( 'type' => 'assoc', 'name' => 'code-file', 'optional' => true ),
	array( 'type' => 'assoc', 'name' => 'description', 'optional' => true ),
	array( 'type' => 'assoc', 'name' => 'run-type', 'optional' => true, 'description' => 'always|once|never|external' ),
	array( 'type' => 'assoc', 'name' => 'location', 'optional' => true, 'description' => 'header|footer' ),
	array( 'type' => 'assoc', 'name' => 'folder', 'optional' => true ),
	array( 'type' => 'assoc', 'name' => 'hook', 'optional' => true, 'description' => 'plugins_loaded|frontend_header|frontend_footer|login_header|login_footer|admin_header|admin_footer|root' ),
	array( 'type' => 'assoc', 'name' => 'custom-action', 'optional' => true, 'description' => 'Any real WP hook name, e.g. admin_menu, init' ),
	array( 'type' => 'assoc', 'name' => 'priority', 'optional' => true ),
	array( 'type' => 'flag', 'name' => 'enabled', 'optional' => true ),
);

WP_CLI::add_command( 'wpcb2 snippet create', 'wpcb2_cli_snippet_create', array(
	'synopsis' => array_merge(
		array( array( 'type' => 'positional', 'name' => 'title', 'optional' => false ) ),
		$wpcb2_snippet_synopsis_common
	),
) );

WP_CLI::add_command( 'wpcb2 snippet update', 'wpcb2_cli_snippet_update', array(
	'synopsis' => array_merge(
		array( array( 'type' => 'positional', 'name' => 'id', 'optional' => false ) ),
		$wpcb2_snippet_synopsis_common,
		array(
			array( 'type' => 'assoc', 'name' => 'title', 'optional' => true ),
			array( 'type' => 'flag', 'name' => 'disabled', 'optional' => true ),
		)
	),
) );

WP_CLI::add_command( 'wpcb2 snippet get', 'wpcb2_cli_snippet_get' );
WP_CLI::add_command( 'wpcb2 snippet list', 'wpcb2_cli_snippet_list' );
WP_CLI::add_command( 'wpcb2 snippet delete', 'wpcb2_cli_snippet_delete' );
WP_CLI::add_command( 'wpcb2 snippet enable', 'wpcb2_cli_snippet_enable' );
WP_CLI::add_command( 'wpcb2 snippet disable', 'wpcb2_cli_snippet_disable' );
WP_CLI::add_command( 'wpcb2 snippet sync', 'wpcb2_cli_snippet_sync' );
WP_CLI::add_command( 'wpcb2 snippet sync-all', 'wpcb2_cli_snippet_sync_all' );
WP_CLI::add_command( 'wpcb2 folder list', 'wpcb2_cli_folder_list' );
WP_CLI::add_command( 'wpcb2 folder create', 'wpcb2_cli_folder_create' );
