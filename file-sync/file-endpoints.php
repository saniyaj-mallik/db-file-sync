<?php
/**
 * File Sync REST API Endpoints
 * Provides REST API endpoints for file synchronization
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Register file sync REST API endpoints
 */
function dbfs_register_file_endpoints() {
    // Get list of files for a directory
    register_rest_route('filesync/v1', '/files-list', [
        'methods' => 'GET',
        'callback' => 'dbfs_get_files_list',
        'permission_callback' => '__return_true',
    ]);
    
    // Get file content
    register_rest_route('filesync/v1', '/file-content', [
        'methods' => 'GET',
        'callback' => 'dbfs_get_file_content',
        'permission_callback' => '__return_true',
    ]);
    
    // Get file info (metadata)
    register_rest_route('filesync/v1', '/file-info', [
        'methods' => 'GET',
        'callback' => 'dbfs_get_file_info',
        'permission_callback' => '__return_true',
    ]);
    
    // AJAX endpoints for sync management
    register_rest_route('filesync/v1', '/start-sync', [
        'methods' => 'POST',
        'callback' => 'dbfs_ajax_start_sync',
        'permission_callback' => 'dbfs_check_admin_permission',
    ]);
    
    register_rest_route('filesync/v1', '/sync-progress', [
        'methods' => 'GET',
        'callback' => 'dbfs_ajax_get_progress',
        'permission_callback' => 'dbfs_check_admin_permission',
    ]);
    
    register_rest_route('filesync/v1', '/sync-status', [
        'methods' => 'GET',
        'callback' => 'dbfs_ajax_get_status',
        'permission_callback' => 'dbfs_check_admin_permission',
    ]);
}

/**
 * Get list of files for a specific directory
 */
function dbfs_get_files_list(WP_REST_Request $request) {
    if (!DBFS_Auth::verify_token($request->get_param('token'))) {
        return new WP_REST_Response(['error' => 'Unauthorized'], 403);
    }

    $directory_type = sanitize_text_field($request->get_param('directory'));
    $directories = DBFS_Utils::get_sync_directories();
    
    if (!isset($directories[$directory_type])) {
        return new WP_REST_Response(['error' => 'Invalid directory type'], 400);
    }
    
    $directory_info = $directories[$directory_type];
    $directory_path = $directory_info['path'];
    
    if (!is_dir($directory_path)) {
        return new WP_REST_Response(['error' => 'Directory does not exist'], 404);
    }
    
    // Scan directory for files
    $files = DBFS_Utils::scan_directory($directory_path, $directory_path);
    
    // Filter files by allowed types for security
    $filtered_files = array_filter($files, function($file) {
        return DBFS_Auth::is_file_type_allowed($file['relative_path']);
    });
    
    return new WP_REST_Response(array_values($filtered_files));
}

/**
 * Get file content for download
 */
function dbfs_get_file_content(WP_REST_Request $request) {
    if (!DBFS_Auth::verify_token($request->get_param('token'))) {
        return new WP_REST_Response(['error' => 'Unauthorized'], 403);
    }

    $directory_type = sanitize_text_field($request->get_param('directory'));
    $file_path = sanitize_text_field($request->get_param('file'));
    
    // Validate directory type
    $directories = DBFS_Utils::get_sync_directories();
    if (!isset($directories[$directory_type])) {
        return new WP_REST_Response(['error' => 'Invalid directory type'], 400);
    }
    
    $directory_info = $directories[$directory_type];
    $full_file_path = $directory_info['path'] . DIRECTORY_SEPARATOR . $file_path;
    
    // Security: Validate file path
    if (!DBFS_Auth::validate_file_path($full_file_path)) {
        return new WP_REST_Response(['error' => 'Invalid file path'], 403);
    }
    
    // Check if file exists and is readable
    if (!file_exists($full_file_path) || !is_readable($full_file_path)) {
        return new WP_REST_Response(['error' => 'File not found or not readable'], 404);
    }
    
    // Check file type is allowed
    if (!DBFS_Auth::is_file_type_allowed($file_path)) {
        return new WP_REST_Response(['error' => 'File type not allowed'], 403);
    }
    
    // Get file size for large file handling
    $file_size = filesize($full_file_path);
    $max_file_size = 50 * 1024 * 1024; // 50MB limit
    
    if ($file_size > $max_file_size) {
        return new WP_REST_Response(['error' => 'File too large for direct download'], 413);
    }
    
    // Read file content
    $content = file_get_contents($full_file_path);
    if ($content === false) {
        return new WP_REST_Response(['error' => 'Could not read file'], 500);
    }
    
    // Determine if we need to base64 encode (for binary files)
    $is_binary = !mb_check_encoding($content, 'UTF-8');
    
    $response_data = [
        'file' => $file_path,
        'size' => $file_size,
        'hash' => md5($content),
        'modified' => filemtime($full_file_path),
        'is_binary' => $is_binary
    ];
    
    if ($is_binary) {
        $response_data['content'] = base64_encode($content);
        $response_data['encoding'] = 'base64';
    } else {
        $response_data['content'] = $content;
        $response_data['encoding'] = 'utf8';
    }
    
    return new WP_REST_Response($response_data);
}

/**
 * Get file metadata without content
 */
function dbfs_get_file_info(WP_REST_Request $request) {
    if (!DBFS_Auth::verify_token($request->get_param('token'))) {
        return new WP_REST_Response(['error' => 'Unauthorized'], 403);
    }

    $directory_type = sanitize_text_field($request->get_param('directory'));
    $file_path = sanitize_text_field($request->get_param('file'));
    
    // Validate directory type
    $directories = DBFS_Utils::get_sync_directories();
    if (!isset($directories[$directory_type])) {
        return new WP_REST_Response(['error' => 'Invalid directory type'], 400);
    }
    
    $directory_info = $directories[$directory_type];
    $full_file_path = $directory_info['path'] . DIRECTORY_SEPARATOR . $file_path;
    
    // Security: Validate file path
    if (!DBFS_Auth::validate_file_path($full_file_path)) {
        return new WP_REST_Response(['error' => 'Invalid file path'], 403);
    }
    
    // Check if file exists
    if (!file_exists($full_file_path)) {
        return new WP_REST_Response(['error' => 'File not found'], 404);
    }
    
    // Check file type is allowed
    if (!DBFS_Auth::is_file_type_allowed($file_path)) {
        return new WP_REST_Response(['error' => 'File type not allowed'], 403);
    }
    
    $file_info = [
        'file' => $file_path,
        'size' => filesize($full_file_path),
        'hash' => DBFS_Utils::get_file_hash($full_file_path),
        'modified' => filemtime($full_file_path),
        'is_readable' => is_readable($full_file_path),
        'permissions' => substr(sprintf('%o', fileperms($full_file_path)), -4)
    ];
    
    return new WP_REST_Response($file_info);
}

/**
 * Check admin permission for AJAX endpoints
 */
function dbfs_check_admin_permission() {
    return current_user_can('manage_options');
}

/**
 * Start file sync via AJAX
 */
function dbfs_ajax_start_sync(WP_REST_Request $request) {
    $source_url = sanitize_url($request->get_param('source_url'));
    $sync_type = sanitize_text_field($request->get_param('sync_type')); // 'file', 'db', or 'full'
    $include_options = (bool) $request->get_param('include_options');
    
    if (empty($source_url)) {
        return new WP_REST_Response(['error' => 'Source URL is required'], 400);
    }
    
    // Initialize progress tracking
    $sync_id = 'dbfs_sync_' . time();
    set_transient($sync_id . '_progress', [
        'status' => 'starting',
        'message' => 'Initializing sync...',
        'progress' => 0,
        'started_at' => time(),
        'files_total' => 0,
        'files_completed' => 0,
        'current_file' => '',
        'errors' => []
    ], 3600); // 1 hour expiry
    
    // Store sync ID for tracking
    set_transient('dbfs_current_sync_id', $sync_id, 3600);
    
    // Start sync immediately instead of using WordPress cron
    // This prevents cron-related issues that cause hanging
    try {
        // Run sync in a separate process to avoid timeout
        dbfs_update_sync_progress($sync_id, [
            'status' => 'running',
            'message' => '🚀 Starting sync process...',
            'progress' => 5
        ]);
        
        // Schedule sync to run immediately but in background to avoid output interference
        wp_schedule_single_event(time() + 1, 'dbfs_background_sync', [$source_url, $sync_type, $sync_id, $include_options]);
        
    } catch (Exception $e) {
        dbfs_update_sync_progress($sync_id, [
            'status' => 'error',
            'message' => '❌ Failed to start sync: ' . $e->getMessage(),
            'progress' => 0,
            'error' => $e->getMessage()
        ]);
        
        return new WP_REST_Response([
            'success' => false,
            'error' => 'Failed to start sync: ' . $e->getMessage()
        ], 500);
    }
    
    return new WP_REST_Response([
        'success' => true,
        'sync_id' => $sync_id,
        'message' => 'Sync started successfully'
    ]);
}

/**
 * Get sync progress via AJAX
 */
function dbfs_ajax_get_progress(WP_REST_Request $request) {
    $sync_id = get_transient('dbfs_current_sync_id');
    
    if (!$sync_id) {
        return new WP_REST_Response([
            'status' => 'no_sync',
            'message' => 'No active sync found'
        ]);
    }
    
    $progress = get_transient($sync_id . '_progress');
    
    if (!$progress) {
        return new WP_REST_Response([
            'status' => 'not_found',
            'message' => 'Sync progress not found'
        ]);
    }
    
    return new WP_REST_Response($progress);
}

/**
 * Get sync status via AJAX
 */
function dbfs_ajax_get_status(WP_REST_Request $request) {
    $sync_id = get_transient('dbfs_current_sync_id');
    
    if (!$sync_id) {
        return new WP_REST_Response([
            'has_active_sync' => false,
            'message' => 'No active sync'
        ]);
    }
    
    $progress = get_transient($sync_id . '_progress');
    
    return new WP_REST_Response([
        'has_active_sync' => $progress !== false,
        'sync_id' => $sync_id,
        'status' => $progress ? $progress['status'] : 'unknown'
    ]);
}

/**
 * Background sync hook - runs the actual sync process
 */
add_action('dbfs_background_sync', 'dbfs_run_background_sync', 10, 4);

function dbfs_run_background_sync($source_url, $sync_type, $sync_id, $include_options = false) {
    // Prevent timeout
    set_time_limit(0);
    ignore_user_abort(true);
    
    // Add comprehensive debugging
    error_log("DBFS: Background sync started - Type: $sync_type, Source: $source_url, Options: " . ($include_options ? 'yes' : 'no'));
    
    try {
        if ($sync_type === 'file' || $sync_type === 'full') {
            dbfs_update_sync_progress($sync_id, [
                'status' => 'running',
                'message' => '📁 Starting file sync...',
                'progress' => 10
            ]);
            
            $file_sync = new DBFS_File_Sync_Ajax($source_url, 1048576, 1800, $sync_id);
            $file_sync->run_full_sync(['uploads']);
            
            error_log("DBFS: File sync completed successfully");
        }
        
        if ($sync_type === 'db' || $sync_type === 'full') {
            error_log("DBFS: Starting database sync phase");
            
            dbfs_update_sync_progress($sync_id, [
                'status' => 'running',
                'message' => '📊 Starting database sync...',
                'progress' => 50
            ]);
            
            try {
                // Check if classes exist
                if (!class_exists('DBFS_DB_Sync')) {
                    throw new Exception('DBFS_DB_Sync class not found');
                }
                
                if ($include_options && !class_exists('DBFS_DB_Sync_With_Options')) {
                    throw new Exception('DBFS_DB_Sync_With_Options class not found');
                }
                
                if ($include_options) {
                    error_log("DBFS: Using DBFS_DB_Sync_With_Options class");
                    $db_sync = new DBFS_DB_Sync_With_Options($source_url, DBSYNC_CHUNK_SIZE, DBSYNC_MAX_EXECUTION_TIME, true);
                } else {
                    error_log("DBFS: Using DBFS_DB_Sync class");
                    $db_sync = new DBFS_DB_Sync($source_url, DBSYNC_CHUNK_SIZE, DBSYNC_MAX_EXECUTION_TIME);
                }
                
                // Test connection before starting sync
                dbfs_update_sync_progress($sync_id, [
                    'status' => 'running',
                    'message' => '🔌 Testing database connection...',
                    'progress' => 55
                ]);
                
                error_log("DBFS: Testing database connection to source");
                $tables = $db_sync->get_tables_to_sync();
                
                if (empty($tables)) {
                    throw new Exception('Could not get table list from source site. Check if source site has the plugin installed and active with matching secret token.');
                }
                
                error_log("DBFS: Found " . count($tables) . " tables to sync");
                
                dbfs_update_sync_progress($sync_id, [
                    'status' => 'running',
                    'message' => '📋 Found ' . count($tables) . ' tables to sync',
                    'progress' => 60
                ]);
                
                // Run database sync with timeout monitoring
                $db_start_time = time();
                
                error_log("DBFS: Starting actual database sync");
                dbfs_update_sync_progress($sync_id, [
                    'status' => 'running',
                    'message' => '🔄 Syncing database tables...',
                    'progress' => 65
                ]);
                
                // Don't buffer output to allow progress updates
                $db_sync->run_sync();
                
                $db_elapsed = time() - $db_start_time;
                error_log("DBFS: Database sync completed in {$db_elapsed}s");
                
                dbfs_update_sync_progress($sync_id, [
                    'status' => 'running',
                    'message' => "✅ Database sync completed in {$db_elapsed}s",
                    'progress' => 90
                ]);
                
            } catch (Exception $db_error) {
                error_log("DBFS: Database sync error: " . $db_error->getMessage());
                
                dbfs_update_sync_progress($sync_id, [
                    'status' => 'error',
                    'message' => '❌ Database sync failed: ' . $db_error->getMessage(),
                    'progress' => 50,
                    'error' => $db_error->getMessage()
                ]);
                
                throw $db_error; // Re-throw to be caught by outer try-catch
            }
        }
        
        // Mark as completed
        error_log("DBFS: Sync completed successfully");
        dbfs_update_sync_progress($sync_id, [
            'status' => 'completed',
            'message' => 'Sync completed successfully!',
            'progress' => 100,
            'completed_at' => time()
        ]);
        
    } catch (Exception $e) {
        error_log("DBFS: Sync failed with error: " . $e->getMessage());
        
        dbfs_update_sync_progress($sync_id, [
            'status' => 'error',
            'message' => 'Sync failed: ' . $e->getMessage(),
            'progress' => 0,
            'error' => $e->getMessage()
        ]);
    }
}

/**
 * Helper function to update sync progress
 */
function dbfs_update_sync_progress($sync_id, $data) {
    $existing = get_transient($sync_id . '_progress');
    if ($existing) {
        $data = array_merge($existing, $data);
    }
    set_transient($sync_id . '_progress', $data, 3600);
} 