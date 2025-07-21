<?php
/**
 * Database Sync Class
 * Handles all database synchronization operations
 */

if (!defined('ABSPATH')) {
    exit;
}

class DBFS_DB_Sync {
    
    private $source_url;
    private $chunk_size;
    private $max_execution_time;
    
    public function __construct($source_url = '', $chunk_size = 50, $max_execution_time = 300) {
        $this->source_url = $source_url ?: 'http://sajah.local';
        $this->chunk_size = $chunk_size;
        $this->max_execution_time = $max_execution_time;
    }
    
    /**
     * Get tables to sync from source site
     */
    public function get_tables_to_sync() {
        $url = $this->source_url . '/wp-json/dbsync/v1/tables?' . http_build_query([
            'token' => DBSYNC_SECRET
        ]);
        
        $response = wp_remote_get($url, [
            'timeout' => 60, // Increased from 30 to 60 seconds
            'sslverify' => false
        ]);
        
        if (is_wp_error($response)) {
            error_log('DB Sync: Could not reach source site for table list: ' . $response->get_error_message());
            return $this->get_fallback_tables();
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            error_log('DB Sync: Source site returned error for table list: ' . $body);
            return $this->get_fallback_tables();
        }
        
        $table_data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE || !is_array($table_data)) {
            error_log('DB Sync: Invalid JSON response from source site');
            return $this->get_fallback_tables();
        }
        
        // Extract just the table names
        $tables = array_map(function($item) {
            return $item['name'];
        }, $table_data);
        
        return $tables;
    }
    
    /**
     * Get fallback tables if source is unreachable
     */
    private function get_fallback_tables() {
        global $wpdb;
        
        return [
            "{$wpdb->prefix}posts",
            "{$wpdb->prefix}postmeta",
            "{$wpdb->prefix}users",
            "{$wpdb->prefix}usermeta",
            "{$wpdb->prefix}terms",
            "{$wpdb->prefix}term_taxonomy",
            "{$wpdb->prefix}term_relationships",
            "{$wpdb->prefix}comments",
            "{$wpdb->prefix}commentmeta",
        ];
    }
    
    /**
     * Ensure table exists on destination (create if missing)
     */
    public function ensure_table_exists($table) {
        global $wpdb;
        
        // Check if table already exists
        $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
        
        if ($table_exists) {
            return true;
        }
        
        echo "<p style='color:blue;'>📋 Table '$table' doesn't exist, creating from source...</p>";
        
        // Get table structure from source site
        $url = $this->source_url . '/wp-json/dbsync/v1/table-structure?' . http_build_query([
            'token' => DBSYNC_SECRET,
            'table' => $table
        ]);
        
        $response = wp_remote_get($url, [
            'timeout' => 60, // Increased from 30 to 60 seconds
            'sslverify' => false
        ]);
        
        if (is_wp_error($response)) {
            echo "<p style='color:red;'>❌ Error getting table structure: " . $response->get_error_message() . "</p>";
            return false;
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            echo "<p style='color:red;'>❌ HTTP Error getting table structure: $body</p>";
            return false;
        }
        
        $structure_data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE || !isset($structure_data['create_statement'])) {
            echo "<p style='color:red;'>❌ Invalid response for table structure</p>";
            return false;
        }
        
        // Execute CREATE TABLE statement
        $create_sql = $structure_data['create_statement'];
        $result = $wpdb->query($create_sql);
        
        if ($result === false) {
            echo "<p style='color:red;'>❌ Failed to create table: " . $wpdb->last_error . "</p>";
            return false;
        }
        
        echo "<p style='color:green;'>✅ Successfully created table '$table'</p>";
        return true;
    }
    
    /**
     * Run the main sync operation
     */
    public function run_sync() {
        global $wpdb;
        
        set_time_limit($this->max_execution_time);
        
        $start_time = time();
        $tables_to_sync = $this->get_tables_to_sync();
        $total_tables = count($tables_to_sync);
        $current_table = 0;

        foreach ($tables_to_sync as $table) {
            $current_table++;
            $elapsed = time() - $start_time;
            
            echo "<div style='background: #f9f9f9; padding: 10px; margin: 5px 0; border-left: 3px solid #0073aa;'>";
            echo "<p><strong>[$current_table/$total_tables] Syncing table:</strong> $table</p>";
            echo "<p><small>Elapsed time: {$elapsed}s | Estimated remaining: " . round(($elapsed / $current_table) * ($total_tables - $current_table)) . "s</small></p>";
            
            // Ensure table exists on destination
            if (!$this->ensure_table_exists($table)) {
                echo "<p style='color:red;'>❌ Skipping table due to creation failure</p>";
                echo "</div>";
                continue;
            }
            
            $table_start_time = time();
            $offset = 0;
            $total_synced = 0;
            
            while (true) {
                $url = $this->source_url . '/wp-json/dbsync/v1/table?' . http_build_query([
                    'token' => DBSYNC_SECRET,
                    'table' => $table,
                    'offset' => $offset,
                    'limit' => $this->chunk_size
                ]);

                // Retry logic for network timeouts
                $max_retries = 3;
                $retry_count = 0;
                $success = false;
                
                while ($retry_count < $max_retries && !$success) {
                    $response = wp_remote_get($url, [
                        'timeout' => 90, // Increased from 30 to 90 seconds
                        'sslverify' => false,
                        'httpversion' => '1.1',
                        'user-agent' => 'WordPress DB Sync Plugin'
                    ]);
                    
                    if (is_wp_error($response)) {
                        $error_message = $response->get_error_message();
                        echo "<p style='color:orange;'>⚠️ Attempt " . ($retry_count + 1) . " failed: $error_message</p>";
                        
                        if (strpos($error_message, 'timed out') !== false || strpos($error_message, 'timeout') !== false) {
                            $retry_count++;
                            if ($retry_count < $max_retries) {
                                echo "<p style='color:blue;'>🔄 Retrying in 2 seconds...</p>";
                                sleep(2);
                                flush();
                                continue;
                            }
                        }
                        echo '<p style="color:red;">❌ Final error: ' . $error_message . '</p>';
                        break 2; // Break out of both loops
                    }

                    $response_code = wp_remote_retrieve_response_code($response);
                    $body = wp_remote_retrieve_body($response);
                    
                    if ($response_code !== 200) {
                        echo '<p style="color:red;">HTTP Error ' . $response_code . ': ' . $body . '</p>';
                        break 2;
                    }
                    
                    $success = true;
                }
                
                if (!$success) {
                    echo '<p style="color:red;">❌ Failed after ' . $max_retries . ' attempts, skipping table.</p>';
                    break;
                }

                $data = json_decode($body, true);
                
                if (json_last_error() !== JSON_ERROR_NONE) {
                    echo '<p style="color:red;">Invalid JSON response: ' . $body . '</p>';
                    break;
                }
                
                if (empty($data) || !is_array($data) || isset($data['error'])) {
                    if (isset($data['error'])) {
                        echo '<p style="color:red;">API Error: ' . $data['error'] . '</p>';
                    } else {
                        echo '<p style="color:blue;">No more data to sync for this table.</p>';
                    }
                    break;
                }

                foreach ($data as $row) {
                    $wpdb->replace($table, $row);
                }

                $offset += $this->chunk_size;
                $total_synced += count($data);
                echo "<p>Imported $offset rows so far...</p>";
                flush();
                
                // Safety check: prevent timeout
                if (time() - $start_time > $this->max_execution_time - 60) {
                    echo '<p style="color:orange;">⚠️ Approaching time limit, stopping sync...</p>';
                    break 2;
                }
            }
            
            $table_time = time() - $table_start_time;
            echo "<p style='color:green;'>✅ <strong>Completed $table:</strong> $total_synced records in {$table_time}s</p>";
            echo "</div>";
            flush();
        }

        $total_time = time() - $start_time;
        echo '<div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; margin: 10px 0;">';
        echo '<h3 style="color:green;">🎉 Database Sync Complete!</h3>';
        echo "<p><strong>Total tables synced:</strong> $current_table</p>";
        echo "<p><strong>Total time:</strong> {$total_time} seconds</p>";
        echo "<p><strong>Average time per table:</strong> " . round($total_time / max(1, $current_table), 1) . " seconds</p>";
        echo '</div>';
        
        // Log the sync operation
        DBFS_Utils::log_sync_operation('db', 'Database sync completed', [
            'tables_synced' => $current_table,
            'total_time' => $total_time,
            'source_url' => $this->source_url
        ]);
    }
    
    /**
     * Verify sync results
     */
    public function verify_sync() {
        global $wpdb;
        
        echo '<div style="background: #f0f8ff; padding: 15px; margin: 10px 0; border-left: 4px solid #0073aa;">';
        echo '<h3>📊 Database Sync Verification Results</h3>';
        
        $tables_to_verify = $this->get_tables_to_sync();
        
        foreach ($tables_to_verify as $table) {
            $count = $wpdb->get_var("SELECT COUNT(*) FROM `$table`");
            echo "<p><strong>$table:</strong> $count records</p>";
            
            // Show some sample data for posts and users
            if ($table === $wpdb->prefix . 'posts') {
                $recent_posts = $wpdb->get_results("SELECT post_title, post_date FROM `$table` WHERE post_type = 'post' AND post_status = 'publish' ORDER BY post_date DESC LIMIT 5");
                if ($recent_posts) {
                    echo "<ul style='margin-left: 20px;'>";
                    foreach ($recent_posts as $post) {
                        echo "<li>{$post->post_title} ({$post->post_date})</li>";
                    }
                    echo "</ul>";
                }
            }
            
            if ($table === $wpdb->prefix . 'users') {
                $recent_users = $wpdb->get_results("SELECT user_login, user_email FROM `$table` ORDER BY user_registered DESC LIMIT 5");
                if ($recent_users) {
                    echo "<ul style='margin-left: 20px;'>";
                    foreach ($recent_users as $user) {
                        echo "<li>{$user->user_login} ({$user->user_email})</li>";
                    }
                    echo "</ul>";
                }
            }
        }
        
        // Check for potential issues
        echo '<h4>🔍 Potential Issues Check:</h4>';
        
        // Check for orphaned postmeta
        $orphaned_postmeta = $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->prefix}postmeta pm 
            LEFT JOIN {$wpdb->prefix}posts p ON pm.post_id = p.ID 
            WHERE p.ID IS NULL
        ");
        echo "<p><strong>Orphaned postmeta records:</strong> $orphaned_postmeta " . ($orphaned_postmeta > 0 ? '⚠️' : '✅') . "</p>";
        
        // Check for orphaned usermeta
        $orphaned_usermeta = $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->prefix}usermeta um 
            LEFT JOIN {$wpdb->prefix}users u ON um.user_id = u.ID 
            WHERE u.ID IS NULL
        ");
        echo "<p><strong>Orphaned usermeta records:</strong> $orphaned_usermeta " . ($orphaned_usermeta > 0 ? '⚠️' : '✅') . "</p>";
        
        echo '</div>';
    }
} 