<?php
/**
 * Admin Page for DB & File Sync
 * Unified interface for both database and file synchronization
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main admin page function
 */
function dbfs_admin_page() {
    if (!current_user_can('manage_options')) return;
    
    // Get the source URL from form submission or use default
    $source_url = isset($_POST['source_url']) ? esc_url_raw($_POST['source_url']) : 'http://sajah.local';
    
    // Validate URL format
    $url_error = '';
    if (isset($_POST['source_url']) && !filter_var($source_url, FILTER_VALIDATE_URL)) {
        $url_error = 'Please enter a valid URL (e.g., http://example.com or https://example.com)';
        $source_url = 'http://sajah.local'; // Reset to default on error
    }

    echo '<div class="wrap"><h1>Database & File Sync - COMPLETE SITE REPLICATION</h1>';
    
    // Configuration overview
    echo '<div style="background: #e8f4fd; border: 1px solid #bee5eb; padding: 15px; margin: 10px 0;">';
    echo '<h3>🎯 DATABASE & MEDIA SYNC</h3>';
    echo '<p><strong>This will sync your database and media files from the source site.</strong></p>';
    echo '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">';
    
    // Database sync info
    echo '<div>';
    echo '<h4>📊 Database Sync</h4>';
    echo '<ul>';
    echo '<li>🔧 <strong>Dynamic table discovery:</strong> Gets table list from source site</li>';
    echo '<li>🔧 <strong>Auto table creation:</strong> Missing tables created automatically</li>';
    echo '<li>⚙️ <strong>Chunk size:</strong> ' . DBSYNC_CHUNK_SIZE . ' rows</li>';
    echo '<li>⏱️ <strong>Max execution time:</strong> ' . DBSYNC_MAX_EXECUTION_TIME . ' seconds</li>';
    echo '<li>🔐 <strong>wp_options sync:</strong> ' . (DBSYNC_INCLUDE_OPTIONS ? '<span style="color:red;">ENABLED (DANGEROUS!)</span>' : '<span style="color:green;">DISABLED (SAFE)</span>') . '</li>';
    echo '</ul>';
    echo '</div>';
    
    // Media sync info
    echo '<div>';
    echo '<h4>📁 Media Files Sync</h4>';
    echo '<ul>';
    echo '<li>📷 <strong>Images & Documents:</strong> wp-content/uploads/</li>';
    echo '<li>🔐 <strong>File security:</strong> Type filtering & path validation</li>';
    echo '<li>🔍 <strong>Integrity check:</strong> MD5 hash verification</li>';
    echo '<li>⚡ <strong>Smart sync:</strong> Only transfers changed/new files</li>';
    echo '<li>📁 <strong>Directory structure:</strong> Preserves folder organization</li>';
    echo '</ul>';
    echo '</div>';
    
    echo '</div>';
    echo '<p><strong>Current source site:</strong> ' . esc_html($source_url) . '</p>';
    echo '<p><strong>⚠️ BACKUP YOUR DATABASE AND FILES BEFORE PROCEEDING!</strong></p>';
    echo '</div>';
    
    // Show URL validation error if any
    if ($url_error) {
        echo '<div style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 10px; margin: 10px 0;">';
        echo '<p style="color: red;"><strong>Error:</strong> ' . esc_html($url_error) . '</p>';
        echo '</div>';
    }
    
    // Show sync status and available tables/files
    if (!$url_error) {
        echo '<div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin: 20px 0;">';
        
        // Database tables preview
        echo '<div style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd;">';
        echo '<h3>📊 Database Tables Available</h3>';
        $db_sync = new DBFS_DB_Sync($source_url, DBSYNC_CHUNK_SIZE, DBSYNC_MAX_EXECUTION_TIME);
        $tables = $db_sync->get_tables_to_sync();
        if (!empty($tables)) {
            echo '<p><strong>' . count($tables) . ' tables found</strong></p>';
            echo '<details><summary>View table list</summary>';
            echo '<ul style="columns: 2; margin: 10px 0; font-size: 12px;">';
            foreach (array_slice($tables, 0, 20) as $table) {
                echo '<li>' . esc_html($table) . '</li>';
            }
            if (count($tables) > 20) {
                echo '<li><em>... and ' . (count($tables) - 20) . ' more</em></li>';
            }
            echo '</ul></details>';
        } else {
            echo '<p style="color:red;">❌ Could not connect to source for table list</p>';
        }
        echo '</div>';
        
        // File directories preview - LIGHTWEIGHT VERSION
        echo '<div style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd;">';
        echo '<h3>📁 Media Files Status</h3>';
        echo '<p><small><em>Media file counts will be calculated during sync. This preview shows directory status only.</em></small></p>';
        
        // Get basic directory info without expensive scanning
        $directories = DBFS_Utils::get_sync_directories();
        foreach ($directories as $dir_type => $dir_info) {
            $local_exists = is_dir($dir_info['path']);
            $local_readable = $local_exists && is_readable($dir_info['path']);
            
            if ($local_readable) {
                $status_icon = '✅';
                $status_text = 'Ready';
            } else {
                $status_icon = '❌';
                $status_text = $local_exists ? 'Not readable' : 'Not found';
            }
            
            echo "<p>$status_icon <strong>{$dir_info['name']}:</strong> $status_text</p>";
            echo "<p><small>{$dir_info['description']}</small></p>";
        }
        
        echo '<p><small>💡 <strong>Tip:</strong> Actual file counts and source connectivity will be checked when you run the sync.</small></p>';
        echo '</div>';
        
        echo '</div>';
    }

    // Handle form submissions
    if (!$url_error) {
        if (isset($_POST['run_db_sync'])) {
            $db_sync = new DBFS_DB_Sync($source_url, DBSYNC_CHUNK_SIZE, DBSYNC_MAX_EXECUTION_TIME);
            $db_sync->run_sync();
        }

        if (isset($_POST['run_file_sync'])) {
            $enabled_dirs = ['uploads']; // Only media files
            $file_sync = new DBFS_File_Sync($source_url, 1048576, DBSYNC_MAX_EXECUTION_TIME);
            $file_sync->run_full_sync($enabled_dirs);
        }
        
        if (isset($_POST['run_full_sync'])) {
            echo '<div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 10px 0;">';
            echo '<h3>🚀 Running Complete DB + Media Sync...</h3>';
            echo '</div>';
            
            // Run database sync first
            echo '<div style="margin: 20px 0;">';
            echo '<h3>📊 Step 1: Database Sync</h3>';
            $db_sync = new DBFS_DB_Sync($source_url, DBSYNC_CHUNK_SIZE, DBSYNC_MAX_EXECUTION_TIME);
            $db_sync->run_sync();
            echo '</div>';
            
            // Then run media files sync
            echo '<div style="margin: 20px 0;">';
            echo '<h3>📁 Step 2: Media Files Sync</h3>';
            $enabled_dirs = ['uploads']; // Only media files
            $file_sync = new DBFS_File_Sync($source_url, 1048576, DBSYNC_MAX_EXECUTION_TIME);
            $file_sync->run_full_sync($enabled_dirs);
            echo '</div>';
            
            echo '<div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; margin: 10px 0;">';
            echo '<h3 style="color:green;">🎉 Complete DB + Media Sync Finished!</h3>';
            echo '<p>Your database and media files are now synced with the source site.</p>';
            echo '</div>';
        }

        if (isset($_POST['verify_sync'])) {
            $db_sync = new DBFS_DB_Sync($source_url, DBSYNC_CHUNK_SIZE, DBSYNC_MAX_EXECUTION_TIME);
            $db_sync->verify_sync();
            
            $enabled_dirs = ['uploads']; // Only media files
            $file_sync = new DBFS_File_Sync($source_url, 1048576, DBSYNC_MAX_EXECUTION_TIME);
            $file_sync->verify_sync($enabled_dirs);
        }
    }

    // Configuration and action form
    echo '<form method="post" style="background: #f9f9f9; padding: 20px; margin: 20px 0; border: 1px solid #ddd;" onsubmit="return validateForm()">';
    echo '<h3>🌐 Sync Configuration</h3>';
    
    // Source URL configuration
    echo '<table class="form-table">';
    echo '<tr>';
    echo '<th scope="row"><label for="source_url">Source Site URL</label></th>';
    echo '<td>';
    echo '<input type="url" id="source_url" name="source_url" value="' . esc_attr($source_url) . '" class="regular-text" placeholder="http://example.com" required />';
    echo '<p class="description">Enter the complete URL of the source WordPress site (including http:// or https://)</p>';
    echo '<p class="description"><strong>Note:</strong> The source site must have the DB & File Sync plugin installed and active.</p>';
    echo '</td>';
    echo '</tr>';
    echo '</table>';
    
    // Database sync options
    echo '<h4>📊 Database Sync Options</h4>';
    echo '<fieldset>';
    echo '<legend>Advanced sync settings:</legend>';
    echo '<label style="display: block; margin: 5px 0;">';
    echo '<input type="checkbox" name="skip_empty_tables" value="1" checked> Skip empty tables (recommended for faster sync)';
    echo '</label>';
    echo '<label style="display: block; margin: 5px 0;">';
    echo '<input type="checkbox" name="continue_on_errors" value="1" checked> Continue sync if individual tables fail';
    echo '</label>';
    echo '<label style="display: block; margin: 5px 0;">';
    echo '<input type="checkbox" name="show_detailed_progress" value="1"> Show detailed progress (slower but more info)';
    echo '</label>';
    echo '</fieldset>';
    
    // Action buttons
    echo '<div style="margin-top: 20px; padding: 15px; background: #fff; border: 1px solid #ddd;">';
    echo '<h4>🚀 Sync Actions</h4>';
    echo '<p class="submit" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px;">';
    echo '<button class="button button-hero button-primary" name="run_full_sync" type="submit" onclick="return confirm(\'⚠️ This will sync your database and media files from the source site. Are you sure?\')">🎯 Complete DB + Media Sync</button>';
    echo '<button class="button button-secondary" name="run_db_sync" type="submit" onclick="return confirm(\'⚠️ This will overwrite your database. Continue?\')">📊 Database Only</button>';
    echo '<button class="button button-secondary" name="run_file_sync" type="submit">📁 Media Files Only</button>';
    echo '<button class="button" name="verify_sync" type="submit">🔍 Verify Sync</button>';
    echo '</p>';
    echo '</div>';
    
    echo '</form>';
    
    // Sync logs section
    echo '<div style="background: #f9f9f9; padding: 15px; margin: 20px 0; border: 1px solid #ddd;">';
    echo '<h3>📋 Recent Sync Activity</h3>';
    $logs = DBFS_Utils::get_sync_logs(10);
    if (!empty($logs)) {
        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>Time</th><th>Type</th><th>Message</th></tr></thead>';
        echo '<tbody>';
        foreach ($logs as $log) {
            $type_icon = $log['type'] === 'db' ? '📊' : ($log['type'] === 'file' ? '📁' : '🔧');
            echo '<tr>';
            echo '<td>' . esc_html($log['timestamp']) . '</td>';
            echo '<td>' . $type_icon . ' ' . esc_html(ucfirst($log['type'])) . '</td>';
            echo '<td>' . esc_html($log['message']) . '</td>';
            echo '</tr>';
        }
        echo '</tbody></table>';
    } else {
        echo '<p>No sync activity yet.</p>';
    }
    echo '</div>';
    
    // Add JavaScript for form validation
    echo '<script>
    function validateForm() {
        var url = document.getElementById("source_url").value.trim();
        document.getElementById("source_url").value = url;
        
        if (!url) {
            alert("Please enter a source site URL.");
            return false;
        }
        
        var urlPattern = /^https?:\/\/.+/i;
        if (!urlPattern.test(url)) {
            alert("Please enter a valid URL starting with http:// or https://");
            return false;
        }
        
        if (url.endsWith("/")) {
            document.getElementById("source_url").value = url.slice(0, -1);
        }
        
        return true;
    }
    </script>';
    
    echo '</div>';
} 