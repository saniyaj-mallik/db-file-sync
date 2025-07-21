<?php
/**
 * Database Sync REST API Endpoints
 * Provides REST API endpoints for database synchronization
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register database sync REST API endpoints
 */
function dbfs_register_db_endpoints() {
    // Export table data in chunks
    register_rest_route('dbsync/v1', '/table', [
        'methods' => 'GET',
        'callback' => 'dbfs_export_table',
        'permission_callback' => '__return_true',
    ]);
    
    // Get list of available tables from source
    register_rest_route('dbsync/v1', '/tables', [
        'methods' => 'GET',
        'callback' => 'dbfs_get_tables_list',
        'permission_callback' => '__return_true',
    ]);
    
    // Get table structure from source
    register_rest_route('dbsync/v1', '/table-structure', [
        'methods' => 'GET',
        'callback' => 'dbfs_get_table_structure',
        'permission_callback' => '__return_true',
    ]);
}

/**
 * Export table data in chunks
 */
function dbfs_export_table(WP_REST_Request $request) {
    if (!DBFS_Auth::verify_token($request->get_param('token'))) {
        return new WP_REST_Response(['error' => 'Unauthorized'], 403);
    }

    global $wpdb;
    $table = sanitize_text_field($request->get_param('table'));
    $offset = intval($request->get_param('offset', 0));
    $limit = intval($request->get_param('limit', 100));

    $valid_tables = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}%'");
    if (!in_array($table, $valid_tables)) {
        return new WP_REST_Response(['error' => 'Invalid table'], 400);
    }

    $rows = $wpdb->get_results("SELECT * FROM `$table` LIMIT $offset, $limit", ARRAY_A);
    return new WP_REST_Response($rows);
}

/**
 * Get list of tables from source site
 */
function dbfs_get_tables_list(WP_REST_Request $request) {
    if (!DBFS_Auth::verify_token($request->get_param('token'))) {
        return new WP_REST_Response(['error' => 'Unauthorized'], 403);
    }

    global $wpdb;
    
    // Get all WordPress tables from source site
    $all_tables = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}%'");
    
    // Always exclude problematic tables
    $exclude_tables = [
        "{$wpdb->prefix}actionscheduler_actions",
        "{$wpdb->prefix}actionscheduler_claims", 
        "{$wpdb->prefix}actionscheduler_failures",
        "{$wpdb->prefix}actionscheduler_groups",
        "{$wpdb->prefix}actionscheduler_logs",
        "{$wpdb->prefix}wc_admin_notes",
        "{$wpdb->prefix}wc_admin_note_actions",
    ];
    
    // Conditionally exclude wp_options
    if (!defined('DBSYNC_INCLUDE_OPTIONS') || !DBSYNC_INCLUDE_OPTIONS) {
        $exclude_tables[] = "{$wpdb->prefix}options";
    }
    
    $tables_to_sync = array_diff($all_tables, $exclude_tables);
    
    // Return with table sizes for better planning
    $table_info = [];
    foreach ($tables_to_sync as $table) {
        $count = $wpdb->get_var("SELECT COUNT(*) FROM `$table`");
        $table_info[] = [
            'name' => $table,
            'count' => (int)$count
        ];
    }
    
    // Sort by size (smaller first)
    usort($table_info, function($a, $b) {
        return $a['count'] <=> $b['count'];
    });
    
    return new WP_REST_Response($table_info);
}

/**
 * Get table structure (CREATE TABLE statement)
 */
function dbfs_get_table_structure(WP_REST_Request $request) {
    if (!DBFS_Auth::verify_token($request->get_param('token'))) {
        return new WP_REST_Response(['error' => 'Unauthorized'], 403);
    }

    global $wpdb;
    $table = sanitize_text_field($request->get_param('table'));
    
    // Validate table exists
    $valid_tables = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}%'");
    if (!in_array($table, $valid_tables)) {
        return new WP_REST_Response(['error' => 'Invalid table'], 400);
    }
    
    // Get CREATE TABLE statement
    $create_table = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_A);
    
    if (!$create_table) {
        return new WP_REST_Response(['error' => 'Could not get table structure'], 500);
    }
    
    return new WP_REST_Response([
        'table' => $table,
        'create_statement' => $create_table['Create Table']
    ]);
} 