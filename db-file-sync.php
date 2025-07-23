<?php
/*
Plugin Name: WP DB & Media Sync
Description: Sync WordPress database tables and media files from Site A to Site B via REST API. Focused on the essentials: database and uploads.
Version: 2.0
Author: Saniyaj Mallik
*/

defined('ABSPATH') || exit;

// Plugin configuration
define('DBSYNC_SECRET', 'your-super-secret-token'); // Change this and keep it same on both sites

// Database sync configuration
define('DBSYNC_INCLUDE_OPTIONS', false); // Set to true to sync wp_options (DANGEROUS!)
define('DBSYNC_INCLUDE_ALL_PLUGINS', true); // Sync all plugin tables
define('DBSYNC_CHUNK_SIZE', 50); // Reduce chunk size for large tables
define('DBSYNC_MAX_EXECUTION_TIME', 1800); // 30 minutes max execution (increased from 5 minutes)

// Define plugin paths
define('DBFS_PLUGIN_DIR', dirname(__FILE__));

/**
 * Load plugin files when WordPress is ready
 */
function dbfs_load_plugin_files() {
    // Only load files if they exist to prevent errors
    $files = [
        '/includes/class-auth.php',
        '/includes/class-utils.php',
        '/db-sync/class-db-sync.php',
        '/db-sync/class-options-sync.php',
        '/db-sync/db-endpoints.php',
        '/file-sync/class-file-sync.php',
        '/file-sync/stuck-file-handler.php',
        '/file-sync/file-endpoints.php',
        '/admin/admin-page.php'
    ];
    
    foreach ($files as $file) {
        $file_path = DBFS_PLUGIN_DIR . $file;
        if (file_exists($file_path)) {
            require_once $file_path;
        }
    }
}

/**
 * Initialize REST API endpoints
 */
function dbfs_init_rest_endpoints() {
    // Only register if functions exist
    if (function_exists('dbfs_register_db_endpoints')) {
        dbfs_register_db_endpoints();
    }
    
    if (function_exists('dbfs_register_file_endpoints')) {
        dbfs_register_file_endpoints();
    }
}

/**
 * Initialize admin interface
 */
function dbfs_init_admin_menu() {
    if (function_exists('dbfs_admin_page')) {
        add_menu_page(
            'DB & Media Sync', 
            'DB & Media Sync', 
            'manage_options', 
            'dbfs-sync', 
            'dbfs_admin_page',
            'dashicons-update',
            30
        );
    }
}

/**
 * Lightweight plugin activation
 */
function dbfs_activate_plugin() {
    // Minimal activation - just create the option if needed
    add_option('dbfs_sync_logs', []);
}

/**
 * Lightweight plugin deactivation  
 */
function dbfs_deactivate_plugin() {
    // Minimal deactivation - nothing heavy
}

// Hook everything properly to WordPress loading process
add_action('plugins_loaded', 'dbfs_load_plugin_files');
add_action('rest_api_init', 'dbfs_init_rest_endpoints');
add_action('admin_menu', 'dbfs_init_admin_menu');

// Plugin activation/deactivation hooks
register_activation_hook(__FILE__, 'dbfs_activate_plugin');
register_deactivation_hook(__FILE__, 'dbfs_deactivate_plugin');

// Plugin is now fully modular with proper WordPress loading:
// - includes/class-auth.php - Authentication and security
// - includes/class-utils.php - Utility functions
// - db-sync/class-db-sync.php - Database sync functionality
// - db-sync/db-endpoints.php - Database REST API endpoints
// - file-sync/class-file-sync.php - Media file sync functionality  
// - file-sync/file-endpoints.php - Media file sync REST API endpoints
// - admin/admin-page.php - Unified admin interface for DB + Media sync
