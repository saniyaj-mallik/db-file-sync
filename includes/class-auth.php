<?php
/**
 * Authentication and Security Class
 * Handles token validation and security for both DB and File sync
 */

if (!defined('ABSPATH')) {
    exit;
}

class DBFS_Auth {
    
    /**
     * Verify the authentication token
     */
    public static function verify_token($request_token) {
        if (!defined('DBSYNC_SECRET') || empty(DBSYNC_SECRET)) {
            return false;
        }
        
        return hash_equals(DBSYNC_SECRET, $request_token);
    }
    
    /**
     * Get authentication headers for requests
     */
    public static function get_auth_headers() {
        return [
            'X-DBFS-Token' => DBSYNC_SECRET,
            'Content-Type' => 'application/json'
        ];
    }
    
    /**
     * Validate file path to prevent directory traversal
     */
    public static function validate_file_path($path) {
        // Remove any attempts at directory traversal
        $path = str_replace(['../', '.\\', '..\\'], '', $path);
        
        // Ensure path starts from WordPress root or content directory
        $wp_content_dir = WP_CONTENT_DIR;
        $abspath = ABSPATH;
        
        $real_path = realpath($path);
        $wp_content_real = realpath($wp_content_dir);
        $abspath_real = realpath($abspath);
        
        // Path must be within WordPress directories
        return (
            $real_path && (
                strpos($real_path, $wp_content_real) === 0 ||
                strpos($real_path, $abspath_real) === 0
            )
        );
    }
    
    /**
     * Check if file type is allowed for sync
     */
    public static function is_file_type_allowed($filename) {
        $allowed_extensions = [
            // Images
            'jpg', 'jpeg', 'png', 'gif', 'webp', 'svg', 'ico', 'bmp',
            // Documents
            'pdf', 'doc', 'docx', 'txt', 'rtf', 'odt',
            // Web files
            'css', 'js', 'html', 'htm', 'xml', 'json',
            // WordPress files
            'php', 'po', 'pot', 'mo',
            // Archives
            'zip', 'tar', 'gz',
            // Media
            'mp4', 'mp3', 'wav', 'avi', 'mov'
        ];
        
        $blocked_extensions = [
            'exe', 'bat', 'cmd', 'com', 'pif', 'scr', 'vbs', 'js'
        ];
        
        $extension = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
        
        // Block dangerous extensions
        if (in_array($extension, $blocked_extensions)) {
            return false;
        }
        
        // Allow common file types
        return in_array($extension, $allowed_extensions);
    }
    
    /**
     * Sanitize file path for safe usage
     */
    public static function sanitize_file_path($path) {
        // Remove dangerous characters
        $path = preg_replace('/[^a-zA-Z0-9\-_\.\/\\\\]/', '', $path);
        
        // Normalize path separators
        $path = str_replace(['\\', '/'], DIRECTORY_SEPARATOR, $path);
        
        return $path;
    }
} 