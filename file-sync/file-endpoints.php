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