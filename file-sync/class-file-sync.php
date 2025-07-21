<?php
/**
 * File Sync Class
 * Handles all file synchronization operations
 */

if (!defined('ABSPATH')) {
    exit;
}

class DBFS_File_Sync {
    
    private $source_url;
    private $chunk_size;
    private $max_execution_time;
    
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
     * Download a single file from source
     */
    public function download_file($file_info, $directory_type) {
        $directories = $this->get_sync_directories();
        
        if (!isset($directories[$directory_type])) {
            return false;
        }
        
        $local_directory = $directories[$directory_type]['path'];
        $local_file_path = $local_directory . DIRECTORY_SEPARATOR . $file_info['relative_path'];
        
        // Ensure directory exists
        $local_file_dir = dirname($local_file_path);
        if (!DBFS_Utils::ensure_directory_exists($local_file_dir)) {
            echo "<p style='color:red;'>❌ Could not create directory: $local_file_dir</p>";
            return false;
        }
        
        // Download file content
        $url = $this->source_url . '/wp-json/filesync/v1/file-content?' . http_build_query([
            'token' => DBSYNC_SECRET,
            'directory' => $directory_type,
            'file' => $file_info['relative_path']
        ]);
        
        $response = wp_remote_get($url, [
            'timeout' => 120,
            'sslverify' => false
        ]);
        
        if (is_wp_error($response)) {
            echo "<p style='color:red;'>❌ Error downloading file {$file_info['relative_path']}: " . $response->get_error_message() . "</p>";
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            echo "<p style='color:red;'>❌ HTTP Error downloading file {$file_info['relative_path']}: $body</p>";
            return false;
        }
        
        // Decode base64 content if present
        $file_data = json_decode($body, true);
        if (json_last_error() === JSON_ERROR_NONE && isset($file_data['content'])) {
            $content = base64_decode($file_data['content']);
        } else {
            $content = $body;
        }
        
        // Write file
        $result = file_put_contents($local_file_path, $content);
        
        if ($result === false) {
            echo "<p style='color:red;'>❌ Failed to write file: $local_file_path</p>";
            return false;
        }
        
        // Verify file integrity
        $local_hash = DBFS_Utils::get_file_hash($local_file_path);
        if ($local_hash !== $file_info['hash']) {
            echo "<p style='color:orange;'>⚠️ Hash mismatch for file: {$file_info['relative_path']}</p>";
        }
        
        return true;
    }
    
    /**
     * Run file sync for a specific directory type
     */
    public function sync_directory($directory_type) {
        $directories = $this->get_sync_directories();
        
        if (!isset($directories[$directory_type])) {
            echo "<p style='color:red;'>❌ Invalid directory type: $directory_type</p>";
            return false;
        }
        
        $directory_info = $directories[$directory_type];
        echo "<div style='background: #f9f9f9; padding: 10px; margin: 5px 0; border-left: 3px solid #0073aa;'>";
        echo "<h4>📁 Syncing: {$directory_info['name']}</h4>";
        
        // Get file lists
        $source_files = $this->get_source_files($directory_type);
        if ($source_files === false) {
            echo "<p style='color:red;'>❌ Could not get source file list</p>";
            echo "</div>";
            return false;
        }
        
        $local_files = $this->get_local_files($directory_type);
        
        // Compare file lists
        $comparison = DBFS_Utils::compare_file_lists($source_files, $local_files);
        
        echo "<p>📊 <strong>Files to download:</strong> " . count($comparison['download']) . "</p>";
        echo "<p>📊 <strong>Files to delete:</strong> " . count($comparison['delete']) . "</p>";
        echo "<p>📊 <strong>Unchanged files:</strong> " . $comparison['unchanged'] . "</p>";
        
        $start_time = time();
        $downloaded = 0;
        $errors = 0;
        
        // Download new/modified files
        foreach ($comparison['download'] as $file_info) {
            // Check time limit
            if (time() - $start_time > $this->max_execution_time - 60) {
                echo '<p style="color:orange;">⚠️ Approaching time limit, stopping file sync...</p>';
                break;
            }
            
            echo "<p>⬇️ Downloading: {$file_info['relative_path']} (" . DBFS_Utils::format_file_size($file_info['size']) . ")</p>";
            flush();
            
            if ($this->download_file($file_info, $directory_type)) {
                $downloaded++;
            } else {
                $errors++;
            }
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
        set_time_limit($this->max_execution_time);
        
        $start_time = time();
        $total_stats = ['downloaded' => 0, 'errors' => 0, 'deleted' => 0];
        
        echo '<div style="background: #e8f4fd; border: 1px solid #bee5eb; padding: 15px; margin: 10px 0;">';
        echo '<h3>📁 File Sync Started</h3>';
        echo '<p>Source: ' . esc_html($this->source_url) . '</p>';
        echo '</div>';
        
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
} 