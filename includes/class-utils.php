<?php
/**
 * Utility Functions Class
 * Common helper functions for DB and File sync operations
 */

if (!defined('ABSPATH')) {
    exit;
}

class DBFS_Utils {
    
    /**
     * Format file size in human readable format
     */
    public static function format_file_size($bytes) {
        if ($bytes >= 1073741824) {
            return number_format($bytes / 1073741824, 2) . ' GB';
        } elseif ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2) . ' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2) . ' KB';
        } else {
            return $bytes . ' bytes';
        }
    }
    
    /**
     * Calculate file hash for integrity verification
     */
    public static function get_file_hash($file_path) {
        if (!file_exists($file_path)) {
            return false;
        }
        
        return md5_file($file_path);
    }
    
    /**
     * Check if a URL is valid and reachable
     */
    public static function is_url_reachable($url) {
        $response = wp_remote_head($url, [
            'timeout' => 10,
            'sslverify' => false
        ]);
        
        return !is_wp_error($response) && wp_remote_retrieve_response_code($response) === 200;
    }
    
    /**
     * Get relative path from WordPress root
     */
    public static function get_relative_path($full_path) {
        $wp_root = ABSPATH;
        
        if (strpos($full_path, $wp_root) === 0) {
            return substr($full_path, strlen($wp_root));
        }
        
        return $full_path;
    }
    
    /**
     * Create directory recursively if it doesn't exist
     */
    public static function ensure_directory_exists($directory) {
        if (!is_dir($directory)) {
            return wp_mkdir_p($directory);
        }
        return true;
    }
    
    /**
     * Get WordPress upload directory info
     */
    public static function get_upload_dir_info() {
        return wp_upload_dir();
    }
    
    /**
     * Get list of WordPress directories to sync
     */
    public static function get_sync_directories() {
        $upload_dir = wp_upload_dir();
        
        return [
            'uploads' => [
                'path' => $upload_dir['basedir'],
                'url' => $upload_dir['baseurl'],
                'name' => 'Media Files (Uploads)',
                'enabled' => true,
                'description' => 'Images, documents, and other uploaded media files'
            ]
        ];
    }
    
    /**
     * Scan directory for files recursively
     */
    public static function scan_directory($directory, $relative_to = '', $calculate_hashes = true) {
        $files = [];
        
        if (!is_dir($directory)) {
            return $files;
        }
        
        $relative_to = $relative_to ?: $directory;
        
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::SELF_FIRST
            );
            
            $file_count = 0;
            $max_files = 10000; // Limit to prevent memory issues
            
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $file_path = $file->getPathname();
                    $relative_path = str_replace($relative_to, '', $file_path);
                    $relative_path = ltrim(str_replace('\\', '/', $relative_path), '/');
                    
                    // Skip hidden files and system files
                    if (strpos(basename($file_path), '.') === 0) {
                        continue;
                    }
                    
                    $file_info = [
                        'path' => $file_path,
                        'relative_path' => $relative_path,
                        'size' => $file->getSize(),
                        'modified' => $file->getMTime(),
                    ];
                    
                    // Only calculate hash if requested (expensive operation)
                    if ($calculate_hashes) {
                        $file_info['hash'] = self::get_file_hash($file_path);
                    } else {
                        $file_info['hash'] = null;
                    }
                    
                    $files[] = $file_info;
                    
                    $file_count++;
                    // Prevent memory exhaustion on huge directories
                    if ($file_count >= $max_files) {
                        error_log("DBFS: Directory scan reached limit of $max_files files in $directory");
                        break;
                    }
                }
            }
        } catch (Exception $e) {
            error_log('DBFS: Error scanning directory ' . $directory . ': ' . $e->getMessage());
            return [];
        }
        
        return $files;
    }
    
    /**
     * Get a quick file count without full scanning
     */
    public static function get_directory_file_count($directory, $max_count = 1000) {
        if (!is_dir($directory)) {
            return 0;
        }
        
        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS),
                RecursiveIteratorIterator::LEAVES_ONLY
            );
            
            $count = 0;
            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $count++;
                    // Stop counting at max to prevent long delays
                    if ($count >= $max_count) {
                        return "$max_count+";
                    }
                }
            }
            
            return $count;
        } catch (Exception $e) {
            error_log('DBFS: Error counting files in ' . $directory . ': ' . $e->getMessage());
            return 'Error';
        }
    }
    
    /**
     * Compare two file arrays and find differences
     */
    public static function compare_file_lists($source_files, $destination_files) {
        $source_map = [];
        $destination_map = [];
        
        // Create maps for easy comparison
        foreach ($source_files as $file) {
            $source_map[$file['relative_path']] = $file;
        }
        
        foreach ($destination_files as $file) {
            $destination_map[$file['relative_path']] = $file;
        }
        
        $to_download = [];
        $to_delete = [];
        
        // Find files to download (new or modified)
        foreach ($source_map as $path => $source_file) {
            if (!isset($destination_map[$path])) {
                // New file
                $to_download[] = $source_file;
            } elseif ($source_file['hash'] !== $destination_map[$path]['hash']) {
                // Modified file
                $to_download[] = $source_file;
            }
        }
        
        // Find files to delete (removed from source)
        foreach ($destination_map as $path => $dest_file) {
            if (!isset($source_map[$path])) {
                $to_delete[] = $dest_file;
            }
        }
        
        return [
            'download' => $to_download,
            'delete' => $to_delete,
            'unchanged' => count($source_map) - count($to_download)
        ];
    }
    
    /**
     * Log sync operation
     */
    public static function log_sync_operation($type, $message, $data = []) {
        $log_entry = [
            'timestamp' => current_time('mysql'),
            'type' => $type, // 'db', 'file', 'error', 'info'
            'message' => $message,
            'data' => $data
        ];
        
        // Store in WordPress options or custom table
        $existing_logs = get_option('dbfs_sync_logs', []);
        $existing_logs[] = $log_entry;
        
        // Keep only last 100 log entries
        if (count($existing_logs) > 100) {
            $existing_logs = array_slice($existing_logs, -100);
        }
        
        update_option('dbfs_sync_logs', $existing_logs);
    }
    
    /**
     * Get sync logs
     */
    public static function get_sync_logs($limit = 50) {
        $logs = get_option('dbfs_sync_logs', []);
        return array_slice(array_reverse($logs), 0, $limit);
    }
} 