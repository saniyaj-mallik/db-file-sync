<?php
/**
 * Stuck File Handler - Utilities for handling problematic downloads
 */

if (!defined('ABSPATH')) {
    exit;
}

class DBFS_Stuck_File_Handler {
    
    /**
     * Get list of files that have been problematic in the past
     */
    public static function get_problem_files() {
        return get_option('dbfs_problem_files', []);
    }
    
    /**
     * Add a file to the problem files list
     */
    public static function mark_as_problem_file($file_path, $reason = '') {
        $problem_files = self::get_problem_files();
        $problem_files[$file_path] = [
            'reason' => $reason,
            'first_seen' => time(),
            'last_attempt' => time(),
            'attempt_count' => isset($problem_files[$file_path]) ? $problem_files[$file_path]['attempt_count'] + 1 : 1
        ];
        update_option('dbfs_problem_files', $problem_files);
    }
    
    /**
     * Check if a file should be skipped based on previous failures
     */
    public static function should_skip_file($file_path, $max_attempts = 5) {
        $problem_files = self::get_problem_files();
        
        if (!isset($problem_files[$file_path])) {
            return false;
        }
        
        $file_data = $problem_files[$file_path];
        
        // Skip if we've tried too many times
        if ($file_data['attempt_count'] >= $max_attempts) {
            return true;
        }
        
        // Skip if last attempt was recent (within 1 hour)
        if ((time() - $file_data['last_attempt']) < 3600) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Clear problem files older than specified days
     */
    public static function cleanup_old_problem_files($days = 7) {
        $problem_files = self::get_problem_files();
        $cutoff_time = time() - ($days * 24 * 3600);
        
        foreach ($problem_files as $file_path => $data) {
            if ($data['first_seen'] < $cutoff_time) {
                unset($problem_files[$file_path]);
            }
        }
        
        update_option('dbfs_problem_files', $problem_files);
    }
    
    /**
     * Get statistics about problem files
     */
    public static function get_problem_file_stats() {
        $problem_files = self::get_problem_files();
        
        if (empty($problem_files)) {
            return [
                'total' => 0,
                'recent' => 0,
                'persistent' => 0
            ];
        }
        
        $recent_cutoff = time() - 3600; // 1 hour ago
        $recent_count = 0;
        $persistent_count = 0;
        
        foreach ($problem_files as $data) {
            if ($data['last_attempt'] > $recent_cutoff) {
                $recent_count++;
            }
            if ($data['attempt_count'] >= 3) {
                $persistent_count++;
            }
        }
        
        return [
            'total' => count($problem_files),
            'recent' => $recent_count,
            'persistent' => $persistent_count
        ];
    }
    
    /**
     * Create a download timeout monitor
     */
    public static function create_timeout_monitor($timeout_seconds = 300) {
        return [
            'start_time' => time(),
            'timeout' => $timeout_seconds,
            'last_activity' => time()
        ];
    }
    
    /**
     * Check if download has timed out
     */
    public static function is_timed_out($monitor) {
        return (time() - $monitor['start_time']) > $monitor['timeout'];
    }
    
    /**
     * Update monitor activity
     */
    public static function update_activity($monitor) {
        $monitor['last_activity'] = time();
        return $monitor;
    }
}

/**
 * Enhanced download function with timeout monitoring
 */
function dbfs_download_file_with_monitoring($file_info, $directory_type, $sync_id = null) {
    // Check if we should skip this file
    if (DBFS_Stuck_File_Handler::should_skip_file($file_info['relative_path'])) {
        if ($sync_id) {
            dbfs_update_sync_progress($sync_id, [
                'status' => 'running',
                'message' => "⏭️ Skipping problematic file: {$file_info['relative_path']}",
            ]);
        }
        return 'skipped';
    }
    
    // Create timeout monitor
    $monitor = DBFS_Stuck_File_Handler::create_timeout_monitor(300); // 5 minutes
    
    try {
        // Update progress to show we're starting
        if ($sync_id) {
            dbfs_update_sync_progress($sync_id, [
                'status' => 'running',
                'message' => "🔄 Starting download: {$file_info['relative_path']}",
                'current_file' => $file_info['relative_path'],
                'download_start_time' => time()
            ]);
        }
        
        // Create an instance of the file sync class
        $file_sync = new DBFS_File_Sync();
        
        // Use the improved download method
        $result = $file_sync->download_file($file_info, $directory_type, 3);
        
        if ($result) {
            return 'success';
        } else {
            // Mark as problem file
            DBFS_Stuck_File_Handler::mark_as_problem_file(
                $file_info['relative_path'], 
                'Download failed after retries'
            );
            return 'failed';
        }
        
    } catch (Exception $e) {
        // Mark as problem file
        DBFS_Stuck_File_Handler::mark_as_problem_file(
            $file_info['relative_path'], 
            'Exception: ' . $e->getMessage()
        );
        
        if ($sync_id) {
            dbfs_update_sync_progress($sync_id, [
                'status' => 'running',
                'message' => "💥 Exception: {$file_info['relative_path']} - {$e->getMessage()}",
            ]);
        }
        
        return 'exception';
    }
}
?> 