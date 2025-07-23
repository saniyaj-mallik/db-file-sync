<?php
/**
 * File Sync Class
 * Handles all file synchronization operations
 */

if (!defined('ABSPATH')) {
    exit;
}

class DBFS_File_Sync {
    
    protected $source_url;
    protected $chunk_size;
    protected $max_execution_time;
    
    public function __construct($source_url = '', $chunk_size = 1048576, $max_execution_time = 300) { // 1MB chunks
        $this->source_url = $source_url ?: 'http://sajah.local';
        $this->chunk_size = $chunk_size;
        $this->max_execution_time = $max_execution_time;
    }
    
    /**
     * Get directories to sync
     */
    public function get_sync_directories() {
        return DBFS_Utils::get_sync_directories();
    }
    
    /**
     * Get file list from source site for a specific directory
     */
    public function get_source_files($directory_type) {
        $url = $this->source_url . '/wp-json/filesync/v1/files-list?' . http_build_query([
            'token' => DBSYNC_SECRET,
            'directory' => $directory_type
        ]);
        
        $response = wp_remote_get($url, [
            'timeout' => 60,
            'sslverify' => false
        ]);
        
        if (is_wp_error($response)) {
            error_log('File Sync: Could not reach source site for file list: ' . $response->get_error_message());
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            error_log('File Sync: Source site returned error for file list: ' . $body);
            return false;
        }
        
        $file_data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($file_data)) {
            error_log('File Sync: Invalid JSON response from source site');
            return false;
        }
        
        return $file_data;
    }
    
    /**
     * Get local file list for a directory
     */
    public function get_local_files($directory_type) {
        $directories = $this->get_sync_directories();
        
        if (!isset($directories[$directory_type])) {
            return [];
        }
        
        $directory_info = $directories[$directory_type];
        $directory_path = $directory_info['path'];
        
        if (!is_dir($directory_path)) {
            return [];
        }
        
        return DBFS_Utils::scan_directory($directory_path, $directory_path);
    }
    
    /**
     * Download a single file from source with retry logic and timeout handling
     */
    public function download_file($file_info, $directory_type, $max_retries = 3) {
        $directories = $this->get_sync_directories();
        
        if (!isset($directories[$directory_type])) {
            return false;
        }
        
        $local_directory = $directories[$directory_type]['path'];
        $local_file_path = $local_directory . DIRECTORY_SEPARATOR . $file_info['relative_path'];
        
        // Ensure directory exists
        $local_file_dir = dirname($local_file_path);
        if (!DBFS_Utils::ensure_directory_exists($local_file_dir)) {
            error_log("DBFS: Could not create directory: $local_file_dir");
            return false;
        }
        
        // Download file content with retry logic
        $url = $this->source_url . '/wp-json/filesync/v1/file-content?' . http_build_query([
            'token' => DBSYNC_SECRET,
            'directory' => $directory_type,
            'file' => $file_info['relative_path']
        ]);
        
        $file_size = isset($file_info['size']) ? $file_info['size'] : 0;
        $timeout = $this->calculate_timeout($file_size);
        $retry_count = 0;
        
        while ($retry_count < $max_retries) {
            $download_start = microtime(true);
            
            $response = wp_remote_get($url, [
                'timeout' => $timeout,
                'sslverify' => false,
                'headers' => [
                    'Connection' => 'close' // Prevent connection reuse issues
                ]
            ]);
            
            $download_time = microtime(true) - $download_start;
            
            if (is_wp_error($response)) {
                $retry_count++;
                $error_msg = $response->get_error_message();
                
                if ($retry_count < $max_retries) {
                    error_log("DBFS: Retry $retry_count/$max_retries for {$file_info['relative_path']}: $error_msg");
                    sleep(1); // Brief pause before retry
                    continue;
                } else {
                    error_log("DBFS: Failed after $max_retries attempts: {$file_info['relative_path']}: $error_msg");
                    return false;
                }
            }
            
            $response_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            if ($response_code !== 200) {
                $retry_count++;
                
                if ($retry_count < $max_retries) {
                    error_log("DBFS: HTTP $response_code retry $retry_count/$max_retries for {$file_info['relative_path']}");
                    sleep(1);
                    continue;
                } else {
                    error_log("DBFS: HTTP Error $response_code after $max_retries attempts: {$file_info['relative_path']}");
                    return false;
                }
            }
            
            // Success - break out of retry loop
            break;
        }
        
        // Check if we have content
        if (empty($body)) {
            error_log("DBFS: Empty response for file: {$file_info['relative_path']}");
            return false;
        }
        
        // Decode base64 content if present
        $file_data = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($file_data['content'])) {
            $content = base64_decode($file_data['content']);
            if ($content === false) {
                error_log("DBFS: Failed to decode base64 content for: {$file_info['relative_path']}");
                return false;
            }
        } else {
            $content = $body;
        }
        
        // Write file with error checking
        $write_start = microtime(true);
        $result = file_put_contents($local_file_path, $content);
        $write_time = microtime(true) - $write_start;
        
        if ($result === false) {
            error_log("DBFS: Failed to write file: $local_file_path");
            return false;
        }
        
        // Verify file integrity if hash is available
        if (isset($file_info['hash'])) {
            $local_hash = DBFS_Utils::get_file_hash($local_file_path);
            if ($local_hash !== $file_info['hash']) {
                error_log("DBFS: Hash mismatch for file: {$file_info['relative_path']} (expected: {$file_info['hash']}, got: $local_hash)");
                // Don't return false for hash mismatch, just warn
            }
        }
        
        // Performance logging for debugging
        if ($download_time > 10) {
            error_log("DBFS: Slow download: {$file_info['relative_path']} took " . round($download_time, 2) . "s");
        }
        
        return true;
    }
    
    /**
     * Calculate appropriate timeout based on file size
     */
    private function calculate_timeout($file_size) {
        // Base timeout of 30 seconds
        $base_timeout = 30;
        
        // Add 1 second per 100KB (assuming slow connection)
        $size_timeout = ceil($file_size / 102400);
        
        // Max timeout of 180 seconds (3 minutes)
        return min($base_timeout + $size_timeout, 180);
    }
    
    /**
     * Run file sync for a specific directory type
     */
    public function sync_directory($directory_type) {
        // Disable output buffering for real-time feedback
        while (ob_get_level()) {
            ob_end_flush();
        }
        
        $directories = $this->get_sync_directories();
        
        if (!isset($directories[$directory_type])) {
            echo "<p style='color:red;'>❌ Invalid directory type: $directory_type</p>";
            $this->flush_output();
            return false;
        }
        
        $directory_info = $directories[$directory_type];
        echo "<div style='background: #f9f9f9; padding: 10px; margin: 5px 0; border-left: 3px solid #0073aa;'>";
        echo "<h4>📁 Syncing: {$directory_info['name']}</h4>";
        
        // Add progress bar HTML and CSS
        echo '<style>
        .dbfs-progress-container {
            width: 100%;
            background-color: #f1f1f1;
            border-radius: 10px;
            margin: 15px 0;
            box-shadow: inset 0 1px 3px rgba(0,0,0,0.2);
        }
        .dbfs-progress-bar {
            width: 0%;
            height: 30px;
            background: linear-gradient(45deg, #0073aa, #005a87);
            border-radius: 10px;
            text-align: center;
            line-height: 30px;
            color: white;
            font-weight: bold;
            transition: width 0.3s ease;
            position: relative;
            overflow: hidden;
        }
        .dbfs-progress-bar::after {
            content: "";
            position: absolute;
            top: 0;
            left: 0;
            bottom: 0;
            right: 0;
            background-image: linear-gradient(
                -45deg,
                rgba(255, 255, 255, .2) 25%,
                transparent 25%,
                transparent 50%,
                rgba(255, 255, 255, .2) 50%,
                rgba(255, 255, 255, .2) 75%,
                transparent 75%,
                transparent
            );
            z-index: 1;
            background-size: 50px 50px;
            animation: move 2s linear infinite;
        }
        @keyframes move {
            0% { background-position: 0 0; }
            100% { background-position: 50px 50px; }
        }
        .dbfs-status {
            background: #e8f4fd;
            border: 1px solid #bee5eb;
            padding: 10px;
            margin: 10px 0;
            border-radius: 5px;
            font-weight: bold;
        }
        </style>';
        
        // Status paragraph
        echo '<div id="dbfs-status" class="dbfs-status">🔍 Initializing file sync...</div>';
        
        // Progress bar
        echo '<div class="dbfs-progress-container">';
        echo '<div id="dbfs-progress-bar" class="dbfs-progress-bar">0%</div>';
        echo '</div>';
        
        $this->flush_output();
        
        // Get file lists
        $this->update_status("📋 Getting file list from source site...", 5);
        $source_files = $this->get_source_files($directory_type);
        if ($source_files === false) {
            echo "<p style='color:red;'>❌ Could not get source file list</p>";
            echo "</div>";
            return false;
        }
        
        $this->update_status("📋 Scanning local files...", 10);
        $local_files = $this->get_local_files($directory_type);
        
        // Compare file lists
        $this->update_status("🔍 Comparing files...", 15);
        $comparison = DBFS_Utils::compare_file_lists($source_files, $local_files);
        
        $total_files = count($comparison['download']);
        
        echo "<p>📊 <strong>Files to download:</strong> " . $total_files . "</p>";
        echo "<p>📊 <strong>Files to delete:</strong> " . count($comparison['delete']) . "</p>";
        echo "<p>📊 <strong>Unchanged files:</strong> " . $comparison['unchanged'] . "</p>";
        $this->flush_output();
        
        if ($total_files === 0) {
            $this->update_status("✅ All files are already up to date!", 100);
            echo "<p style='color:green;'>✅ No files need to be downloaded.</p>";
            echo "</div>";
            return ['downloaded' => 0, 'errors' => 0, 'deleted' => 0];
        }
        
        $start_time = time();
        $downloaded = 0;
        $errors = 0;
        
        // Download new/modified files
        foreach ($comparison['download'] as $index => $file_info) {
            // Check time limit
            if (time() - $start_time > $this->max_execution_time - 60) {
                $this->update_status("⚠️ Time limit reached, stopping sync...", $this->calculate_progress($index, $total_files));
                echo '<p style="color:orange;">⚠️ Approaching time limit, stopping file sync...</p>';
                break;
            }
            
            $progress = $this->calculate_progress($index, $total_files);
            $this->update_status("⬇️ Downloading: {$file_info['relative_path']} (" . DBFS_Utils::format_file_size($file_info['size']) . ")", $progress);
            
            if ($this->download_file($file_info, $directory_type)) {
                $downloaded++;
            } else {
                $errors++;
            }
            
            // Update progress after each file
            $completed_progress = $this->calculate_progress($index + 1, $total_files);
            $this->update_status("✅ Downloaded: {$file_info['relative_path']}", $completed_progress);
        }
        
        // Delete removed files (optional - can be disabled for safety)
        $deleted = 0;
        if (apply_filters('dbfs_delete_removed_files', false)) {
            foreach ($comparison['delete'] as $file_info) {
                $file_path = $directory_info['path'] . DIRECTORY_SEPARATOR . $file_info['relative_path'];
                if (file_exists($file_path) && unlink($file_path)) {
                    echo "<p>🗑️ Deleted: {$file_info['relative_path']}</p>";
                    $deleted++;
                }
            }
        }
        
        $sync_time = time() - $start_time;
        
        // Update final progress
        $this->update_status("🎉 Directory sync completed! Downloaded: $downloaded files, Errors: $errors, Time: {$sync_time}s", 100);
        
        echo "<p style='color:green;'>✅ <strong>Directory sync completed:</strong></p>";
        echo "<ul>";
        echo "<li>Downloaded: $downloaded files</li>";
        echo "<li>Errors: $errors files</li>";
        echo "<li>Deleted: $deleted files</li>";
        echo "<li>Time: {$sync_time}s</li>";
        echo "</ul>";
        echo "</div>";
        
        // Log the sync operation
        DBFS_Utils::log_sync_operation('file', "File sync completed for $directory_type", [
            'directory_type' => $directory_type,
            'downloaded' => $downloaded,
            'errors' => $errors,
            'deleted' => $deleted,
            'sync_time' => $sync_time,
            'source_url' => $this->source_url
        ]);
        
        return ['downloaded' => $downloaded, 'errors' => $errors, 'deleted' => $deleted];
    }
    
    /**
     * Run full file sync for all enabled directories
     */
    public function run_full_sync($enabled_directories = []) {
        // Disable output buffering for real-time feedback
        while (ob_get_level()) {
            ob_end_flush();
        }
        
        set_time_limit($this->max_execution_time);
        
        $start_time = time();
        $total_stats = ['downloaded' => 0, 'errors' => 0, 'deleted' => 0];
        
        echo '<div style="background: #e8f4fd; border: 1px solid #bee5eb; padding: 15px; margin: 10px 0;">';
        echo '<h3>📁 File Sync Started</h3>';
        echo '<p>Source: ' . htmlspecialchars($this->source_url) . '</p>';
        echo '</div>';
        
        $this->flush_output();
        
        $directories = $this->get_sync_directories();
        
        foreach ($directories as $dir_type => $dir_info) {
            if (empty($enabled_directories) || in_array($dir_type, $enabled_directories)) {
                $stats = $this->sync_directory($dir_type);
                if ($stats) {
                    $total_stats['downloaded'] += $stats['downloaded'];
                    $total_stats['errors'] += $stats['errors'];
                    $total_stats['deleted'] += $stats['deleted'];
                }
            }
        }
        
        $total_time = time() - $start_time;
        echo '<div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; margin: 10px 0;">';
        echo '<h3 style="color:green;">🎉 File Sync Complete!</h3>';
        echo "<p><strong>Total files downloaded:</strong> {$total_stats['downloaded']}</p>";
        echo "<p><strong>Total errors:</strong> {$total_stats['errors']}</p>";
        echo "<p><strong>Total files deleted:</strong> {$total_stats['deleted']}</p>";
        echo "<p><strong>Total time:</strong> {$total_time} seconds</p>";
        echo '</div>';
        
        return $total_stats;
    }
    
    /**
     * Verify file sync by comparing file counts and hashes
     */
    public function verify_sync($directory_types = []) {
        echo '<div style="background: #f0f8ff; padding: 15px; margin: 10px 0; border-left: 4px solid #0073aa;">';
        echo '<h3>📊 File Sync Verification Results</h3>';
        
        $directories = $this->get_sync_directories();
        
        foreach ($directories as $dir_type => $dir_info) {
            if (empty($directory_types) || in_array($dir_type, $directory_types)) {
                echo "<h4>📁 {$dir_info['name']}</h4>";
                
                $local_files = $this->get_local_files($dir_type);
                echo "<p><strong>Local files:</strong> " . count($local_files) . "</p>";
                
                $source_files = $this->get_source_files($dir_type);
                if ($source_files !== false) {
                    echo "<p><strong>Source files:</strong> " . count($source_files) . "</p>";
                    
                    $comparison = DBFS_Utils::compare_file_lists($source_files, $local_files);
                    echo "<p><strong>Files needing sync:</strong> " . count($comparison['download']) . "</p>";
                    echo "<p><strong>Files to delete:</strong> " . count($comparison['delete']) . "</p>";
                    
                    if (count($comparison['download']) === 0 && count($comparison['delete']) === 0) {
                        echo "<p style='color:green;'>✅ Directory is fully synced</p>";
                    } else {
                        echo "<p style='color:orange;'>⚠️ Directory needs sync</p>";
                    }
                } else {
                    echo "<p style='color:red;'>❌ Could not connect to source</p>";
                }
                
                echo "<hr>";
            }
        }
        
        echo '</div>';
    }
    
    /**
     * Flush output to browser for real-time updates
     */
    private function flush_output() {
        if (ob_get_level()) {
            ob_flush();
        }
        flush();
    }
    
    /**
     * Update progress bar and status message
     */
    private function update_status($message, $progress) {
        $progress = max(0, min(100, $progress)); // Ensure progress is between 0-100
        
        // Safely escape message for JavaScript
        $escaped_message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $escaped_message = str_replace(array("\r", "\n"), '', $escaped_message);
        
        echo '<script>
        document.getElementById("dbfs-status").innerHTML = "' . $escaped_message . '";
        document.getElementById("dbfs-progress-bar").style.width = "' . $progress . '%";
        document.getElementById("dbfs-progress-bar").innerHTML = "' . round($progress, 1) . '%";
        </script>';
        
        $this->flush_output();
        
        // Small delay to make progress visible
        usleep(100000); // 0.1 seconds
    }
    
    /**
     * Calculate progress percentage
     */
    protected function calculate_progress($current, $total) {
        if ($total === 0) {
            return 100;
        }
        
        // Reserve 15% for initial setup (getting file lists, comparing)
        $base_progress = 15;
        $download_progress = 85;
        
        $file_progress = ($current / $total) * $download_progress;
        return $base_progress + $file_progress;
    }
} 

/**
 * AJAX-compatible File Sync Class
 * Tracks progress in WordPress transients for real-time updates
 */
class DBFS_File_Sync_Ajax extends DBFS_File_Sync {
    
    private $sync_id;
    
    public function __construct($source_url = '', $chunk_size = 1048576, $max_execution_time = 300, $sync_id = '') {
        parent::__construct($source_url, $chunk_size, $max_execution_time);
        $this->sync_id = $sync_id;
    }
    
    /**
     * Update progress in transient for AJAX polling
     */
    private function update_progress($status, $message, $progress, $extra_data = []) {
        $data = array_merge([
            'status' => $status,
            'message' => $message,
            'progress' => max(0, min(100, $progress)),
            'updated_at' => time()
        ], $extra_data);
        
        dbfs_update_sync_progress($this->sync_id, $data);
    }
    
    /**
     * Run file sync for a specific directory type with AJAX progress tracking
     */
    public function sync_directory($directory_type) {
        $directories = $this->get_sync_directories();
        
        if (!isset($directories[$directory_type])) {
            $this->update_progress('error', "❌ Invalid directory type: $directory_type", 0);
            return false;
        }
        
        $directory_info = $directories[$directory_type];
        
        // Get file lists
        $this->update_progress('running', "📋 Getting file list from source site...", 5);
        $source_files = $this->get_source_files($directory_type);
        if ($source_files === false) {
            $this->update_progress('error', '❌ Could not get source file list', 5);
            return false;
        }
        
        $this->update_progress('running', "📋 Scanning local files...", 10);
        $local_files = $this->get_local_files($directory_type);
        
        // Compare file lists
        $this->update_progress('running', "🔍 Comparing files...", 15);
        $comparison = DBFS_Utils::compare_file_lists($source_files, $local_files);
        
        $total_files = count($comparison['download']);
        
        $this->update_progress('running', "📊 Found $total_files files to download", 15, [
            'files_total' => $total_files,
            'files_to_delete' => count($comparison['delete']),
            'unchanged_files' => $comparison['unchanged']
        ]);
        
        if ($total_files === 0) {
            $this->update_progress('completed', "✅ All files are already up to date!", 100);
            return ['downloaded' => 0, 'errors' => 0, 'deleted' => 0];
        }
        
        $start_time = time();
        $downloaded = 0;
        $errors = 0;
        
        // Download new/modified files
        foreach ($comparison['download'] as $index => $file_info) {
            // Check time limit
            if (time() - $start_time > $this->max_execution_time - 60) {
                $progress = $this->calculate_progress($index, $total_files);
                $this->update_progress('timeout', "⚠️ Time limit reached, stopping sync...", $progress, [
                    'files_completed' => $downloaded,
                    'files_errors' => $errors
                ]);
                break;
            }
            
            $progress = $this->calculate_progress($index, $total_files);
            $file_size = DBFS_Utils::format_file_size($file_info['size']);
            $this->update_progress('running', "⬇️ Downloading: {$file_info['relative_path']} ($file_size)", $progress, [
                'current_file' => $file_info['relative_path'],
                'files_completed' => $downloaded,
                'files_errors' => $errors,
                'download_attempt' => 'starting'
            ]);
            
            // Track download time to detect stuck downloads
            $download_start_time = time();
            $max_file_time = 300; // 5 minutes max per file
            
            try {
                // Use improved download_file method with retry logic
                $download_result = $this->download_file($file_info, $directory_type, 3);
                $download_elapsed = time() - $download_start_time;
                
                if ($download_result) {
                    $downloaded++;
                    $this->update_progress('running', "✅ Downloaded: {$file_info['relative_path']} ({$download_elapsed}s)", $this->calculate_progress($index + 1, $total_files), [
                        'current_file' => '',
                        'files_completed' => $downloaded,
                        'files_errors' => $errors,
                        'last_success' => $file_info['relative_path']
                    ]);
                } else {
                    $errors++;
                    $this->update_progress('running', "❌ Failed: {$file_info['relative_path']} (after {$download_elapsed}s)", $this->calculate_progress($index + 1, $total_files), [
                        'current_file' => '',
                        'files_completed' => $downloaded,
                        'files_errors' => $errors,
                        'last_error' => $file_info['relative_path']
                    ]);
                }
                
            } catch (Exception $e) {
                $errors++;
                $download_elapsed = time() - $download_start_time;
                $this->update_progress('running', "💥 Exception downloading {$file_info['relative_path']}: {$e->getMessage()}", $this->calculate_progress($index + 1, $total_files), [
                    'current_file' => '',
                    'files_completed' => $downloaded,
                    'files_errors' => $errors,
                    'last_exception' => $file_info['relative_path'] . ': ' . $e->getMessage()
                ]);
            }
            
            // Force memory cleanup every 50 files
            if (($index + 1) % 50 === 0) {
                if (function_exists('gc_collect_cycles')) {
                    gc_collect_cycles();
                }
                $this->update_progress('running', "🧹 Memory cleanup after " . ($index + 1) . " files", $this->calculate_progress($index + 1, $total_files), [
                    'files_completed' => $downloaded,
                    'files_errors' => $errors
                ]);
            }
        }
        
        // Delete removed files (optional - can be disabled for safety)
        $deleted = 0;
        if (apply_filters('dbfs_delete_removed_files', false)) {
            foreach ($comparison['delete'] as $file_info) {
                $file_path = $directory_info['path'] . DIRECTORY_SEPARATOR . $file_info['relative_path'];
                if (file_exists($file_path) && unlink($file_path)) {
                    $deleted++;
                    $this->update_progress('running', "🗑️ Deleted: {$file_info['relative_path']}", 95, [
                        'files_deleted' => $deleted
                    ]);
                }
            }
        }
        
        $sync_time = time() - $start_time;
        
        // Final progress update
        $this->update_progress('completed', "🎉 Directory sync completed! Downloaded: $downloaded files, Errors: $errors, Time: {$sync_time}s", 100, [
            'files_completed' => $downloaded,
            'files_errors' => $errors,
            'files_deleted' => $deleted,
            'sync_time' => $sync_time
        ]);
        
        // Log the sync operation
        DBFS_Utils::log_sync_operation('file', "File sync completed for $directory_type", [
            'directory_type' => $directory_type,
            'downloaded' => $downloaded,
            'errors' => $errors,
            'deleted' => $deleted,
            'sync_time' => $sync_time,
            'source_url' => $this->source_url
        ]);
        
        return ['downloaded' => $downloaded, 'errors' => $errors, 'deleted' => $deleted];
    }
    
    /**
     * Run full file sync for all enabled directories with AJAX progress tracking
     */
    public function run_full_sync($enabled_directories = []) {
        set_time_limit($this->max_execution_time);
        
        $start_time = time();
        $total_stats = ['downloaded' => 0, 'errors' => 0, 'deleted' => 0];
        
        $this->update_progress('running', '📁 File Sync Started', 0, [
            'source_url' => $this->source_url
        ]);
        
        $directories = $this->get_sync_directories();
        
        foreach ($directories as $dir_type => $dir_info) {
            if (empty($enabled_directories) || in_array($dir_type, $enabled_directories)) {
                $stats = $this->sync_directory($dir_type);
                if ($stats) {
                    $total_stats['downloaded'] += $stats['downloaded'];
                    $total_stats['errors'] += $stats['errors'];
                    $total_stats['deleted'] += $stats['deleted'];
                }
            }
        }
        
        $total_time = time() - $start_time;
        
        $this->update_progress('completed', '🎉 File Sync Complete!', 100, [
            'total_downloaded' => $total_stats['downloaded'],
            'total_errors' => $total_stats['errors'],
            'total_deleted' => $total_stats['deleted'],
            'total_time' => $total_time
        ]);
        
        return $total_stats;
    }
} 