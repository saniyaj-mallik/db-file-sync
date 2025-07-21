<?php
/*
Plugin Name: WP DB File-by-File Sync
Description: Sync selected WordPress database tables from Site A to Site B via REST API.
Version: 1.0
Author: Saniyaj Mallik
*/

defined('ABSPATH') || exit;

define('DBSYNC_SECRET', 'your-super-secret-token'); // Change this and keep it same on both sites

// 🔧 Full Database Sync Configuration
define('DBSYNC_INCLUDE_OPTIONS', false); // Set to true to sync wp_options (DANGEROUS!)
define('DBSYNC_INCLUDE_ALL_PLUGINS', true); // Sync all plugin tables
define('DBSYNC_CHUNK_SIZE', 50); // Reduce chunk size for large tables
define('DBSYNC_MAX_EXECUTION_TIME', 300); // 5 minutes max execution

add_action('rest_api_init', function () {
    register_rest_route('dbsync/v1', '/table', [
        'methods' => 'GET',
        'callback' => 'dbsync_export_table',
        'permission_callback' => '__return_true',
    ]);
    
    // New endpoint to get list of available tables from source
    register_rest_route('dbsync/v1', '/tables', [
        'methods' => 'GET',
        'callback' => 'dbsync_get_tables_list',
        'permission_callback' => '__return_true',
    ]);
    
    // New endpoint to get table structure from source
    register_rest_route('dbsync/v1', '/table-structure', [
        'methods' => 'GET',
        'callback' => 'dbsync_get_table_structure',
        'permission_callback' => '__return_true',
    ]);
});

// 📤 Site A: REST Endpoint to serve table data in chunks
function dbsync_export_table(WP_REST_Request $request) {
    if ($request->get_param('token') !== DBSYNC_SECRET) {
        return new WP_REST_Response(['error' => 'Unauthorized'], 403);
    }

    global $wpdb;
    $table = sanitize_text_field($request->get_param('table'));
    $offset = intval($request->get_param('offset', 0));
    $limit = intval($request->get_param('limit', 100));

    $valid_tables = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}%'");
    if (!in_array($table, $valid_tables)) {
        return new WP_REST_Response(['error' => 'Invalid table'], 400);
    }

    $rows = $wpdb->get_results("SELECT * FROM `$table` LIMIT $offset, $limit", ARRAY_A);
    return new WP_REST_Response($rows);
}

// 📋 New endpoint to get list of tables from source site
function dbsync_get_tables_list(WP_REST_Request $request) {
    if ($request->get_param('token') !== DBSYNC_SECRET) {
        return new WP_REST_Response(['error' => 'Unauthorized'], 403);
    }

    global $wpdb;
    
    // Get all WordPress tables from source site
    $all_tables = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}%'");
    
    // Always exclude problematic tables
    $exclude_tables = [
        "{$wpdb->prefix}actionscheduler_actions",
        "{$wpdb->prefix}actionscheduler_claims", 
        "{$wpdb->prefix}actionscheduler_failures",
        "{$wpdb->prefix}actionscheduler_groups",
        "{$wpdb->prefix}actionscheduler_logs",
        "{$wpdb->prefix}wc_admin_notes",
        "{$wpdb->prefix}wc_admin_note_actions",
    ];
    
    // Conditionally exclude wp_options
    if (!DBSYNC_INCLUDE_OPTIONS) {
        $exclude_tables[] = "{$wpdb->prefix}options";
    }
    
    $tables_to_sync = array_diff($all_tables, $exclude_tables);
    
    // Return with table sizes for better planning
    $table_info = [];
    foreach ($tables_to_sync as $table) {
        $count = $wpdb->get_var("SELECT COUNT(*) FROM `$table`");
        $table_info[] = [
            'name' => $table,
            'count' => (int)$count
        ];
    }
    
    // Sort by size (smaller first)
    usort($table_info, function($a, $b) {
        return $a['count'] <=> $b['count'];
    });
    
    return new WP_REST_Response($table_info);
}

// 🔧 New endpoint to get table structure (CREATE TABLE statement)
function dbsync_get_table_structure(WP_REST_Request $request) {
    if ($request->get_param('token') !== DBSYNC_SECRET) {
        return new WP_REST_Response(['error' => 'Unauthorized'], 403);
    }

    global $wpdb;
    $table = sanitize_text_field($request->get_param('table'));
    
    // Validate table exists
    $valid_tables = $wpdb->get_col("SHOW TABLES LIKE '{$wpdb->prefix}%'");
    if (!in_array($table, $valid_tables)) {
        return new WP_REST_Response(['error' => 'Invalid table'], 400);
    }
    
    // Get CREATE TABLE statement
    $create_table = $wpdb->get_row("SHOW CREATE TABLE `$table`", ARRAY_A);
    
    if (!$create_table) {
        return new WP_REST_Response(['error' => 'Could not get table structure'], 500);
    }
    
    return new WP_REST_Response([
        'table' => $table,
        'create_statement' => $create_table['Create Table']
    ]);
}

// 📥 Site B: Admin Page to pull and import data
add_action('admin_menu', function () {
    add_menu_page('DB Sync', 'DB Sync', 'manage_options', 'dbsync', 'dbsync_admin_page');
});

function dbsync_admin_page() {
    if (!current_user_can('manage_options')) return;
    
    // Get the source URL from form submission or use default
    $source_url = isset($_POST['source_url']) ? esc_url_raw($_POST['source_url']) : 'http://sajah.local';
    
    // Validate URL format
    $url_error = '';
    if (isset($_POST['source_url']) && !filter_var($source_url, FILTER_VALIDATE_URL)) {
        $url_error = 'Please enter a valid URL (e.g., http://example.com or https://example.com)';
        $source_url = 'http://sajah.local'; // Reset to default on error
    }

    echo '<div class="wrap"><h1>Database Sync - COMPLETE REPLICA MODE</h1>';
    echo '<div style="background: #e8f4fd; border: 1px solid #bee5eb; padding: 15px; margin: 10px 0;">';
    echo '<h3>🎯 COMPLETE REPLICA SYNC</h3>';
    echo '<p><strong>This will create a complete replica of the source site on this site.</strong></p>';
    echo '<p>📋 <strong>Dynamic table discovery:</strong> Gets table list directly from source site</p>';
    echo '<p>🔧 <strong>Auto table creation:</strong> Missing tables will be created automatically</p>';
    echo '<ul>';
    echo '<li><strong>Current source site:</strong> ' . esc_html($source_url) . '</li>';
    echo '<li>wp_options sync: ' . (DBSYNC_INCLUDE_OPTIONS ? '<span style="color:red;">ENABLED (DANGEROUS!)</span>' : '<span style="color:green;">DISABLED (SAFE)</span>') . '</li>';
    echo '<li>Auto-create missing tables: <span style="color:blue;">ENABLED</span></li>';
    echo '<li>Chunk size: ' . DBSYNC_CHUNK_SIZE . ' rows</li>';
    echo '<li>Max execution time: ' . DBSYNC_MAX_EXECUTION_TIME . ' seconds</li>';
    echo '</ul>';
    echo '<p><strong>⚠️ BACKUP YOUR DATABASE BEFORE PROCEEDING!</strong></p>';
    echo '</div>';
    
    // Show URL validation error if any
    if ($url_error) {
        echo '<div style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 10px; margin: 10px 0;">';
        echo '<p style="color: red;"><strong>Error:</strong> ' . esc_html($url_error) . '</p>';
        echo '</div>';
    }
    
    // Show tables that will be synced (only if we have a valid URL)
    if (!$url_error) {
        $tables = dbsync_tables_to_sync($source_url);
        if (!empty($tables)) {
            echo '<details><summary><strong>Tables from Source Site (' . count($tables) . ' total)</strong></summary>';
            echo '<ul style="columns: 3; margin: 10px 0;">';
            foreach ($tables as $table) {
                echo '<li>' . esc_html($table) . '</li>';
            }
            echo '</ul></details>';
        } else {
            echo '<div style="background: #f8d7da; border: 1px solid #f5c6cb; padding: 10px; margin: 10px 0;">';
            echo '<p><strong>⚠️ Could not connect to source site to get table list.</strong></p>';
            echo '<p>Please check the source URL and ensure the DB Sync plugin is installed and active on the source site.</p>';
            echo '<p>Will use fallback core tables for sync if you proceed.</p>';
            echo '</div>';
        }
    }

    if (isset($_POST['run_sync']) && !$url_error) {
        dbsync_run_sync($source_url);
    }

    if (isset($_POST['verify_sync']) && !$url_error) {
        dbsync_verify_sync($source_url);
    }

    echo '<form method="post" style="background: #f9f9f9; padding: 20px; margin: 20px 0; border: 1px solid #ddd;" onsubmit="return validateForm()">';
    echo '<h3>🌐 Source Site Configuration</h3>';
    echo '<table class="form-table">';
    echo '<tr>';
    echo '<th scope="row"><label for="source_url">Source Site URL</label></th>';
    echo '<td>';
    echo '<input type="url" id="source_url" name="source_url" value="' . esc_attr($source_url) . '" class="regular-text" placeholder="http://example.com" required />';
    echo '<p class="description">Enter the complete URL of the source WordPress site (including http:// or https://)</p>';
    echo '<p class="description"><strong>Note:</strong> The source site must have the DB Sync plugin installed and active.</p>';
    echo '<p class="description"><strong>Examples:</strong> http://localhost/mysite, https://staging.example.com, http://192.168.1.100:8080</p>';
    echo '</td>';
    echo '</tr>';
    echo '</table>';
    
    echo '<p class="submit">';
    echo '<button class="button button-primary" name="run_sync" type="submit" onclick="return confirm(\'⚠️ This will overwrite your current database with data from the source site. Are you sure you want to continue?\')">🚀 Run Sync Now</button>';
    echo ' <button class="button button-secondary" name="verify_sync" type="submit">🔍 Verify Sync Results</button>';
    echo '</p>';
    echo '</form>';
    
    // Add JavaScript for form validation
    echo '<script>
    function validateForm() {
        var url = document.getElementById("source_url").value.trim();
        document.getElementById("source_url").value = url;
        
        if (!url) {
            alert("Please enter a source site URL.");
            return false;
        }
        
        // Basic URL validation
        var urlPattern = /^https?:\/\/.+/i;
        if (!urlPattern.test(url)) {
            alert("Please enter a valid URL starting with http:// or https://");
            return false;
        }
        
        // Remove trailing slash if present
        if (url.endsWith("/")) {
            document.getElementById("source_url").value = url.slice(0, -1);
        }
        
        return true;
    }
    </script>';
    
    echo '</div>';
}

// 🔍 Verification function
function dbsync_verify_sync($source_site_url = 'http://sajah.local') {
    global $wpdb;
    
    echo '<div style="background: #f0f8ff; padding: 15px; margin: 10px 0; border-left: 4px solid #0073aa;">';
    echo '<h3>📊 Sync Verification Results</h3>';
    
    foreach (dbsync_tables_to_sync($source_site_url) as $table) {
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

// 💡 Tables to sync - Get from SOURCE site (Complete Replica Mode)
function dbsync_tables_to_sync($source_site_url = 'http://sajah.local') {
    // Get table list from SOURCE site
    $url = $source_site_url . '/wp-json/dbsync/v1/tables?' . http_build_query([
        'token' => DBSYNC_SECRET
    ]);
    
    $response = wp_remote_get($url, [
        'timeout' => 30,
        'sslverify' => false
    ]);
    
    if (is_wp_error($response)) {
        // Fallback to local tables if source is unreachable
        error_log('DB Sync: Could not reach source site for table list: ' . $response->get_error_message());
        return dbsync_fallback_tables();
    }
    
    $response_code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    
    if ($response_code !== 200) {
        error_log('DB Sync: Source site returned error for table list: ' . $body);
        return dbsync_fallback_tables();
    }
    
    $table_data = json_decode($body, true);
    
    if (json_last_error() !== JSON_ERROR_NONE || !is_array($table_data)) {
        error_log('DB Sync: Invalid JSON response from source site');
        return dbsync_fallback_tables();
    }
    
    // Extract just the table names
    $tables = array_map(function($item) {
        return $item['name'];
    }, $table_data);
    
    return $tables;
}

// 🔧 Function to ensure table exists on destination (create if missing)
function dbsync_ensure_table_exists($table, $source_site_url) {
    global $wpdb;
    
    // Check if table already exists
    $table_exists = $wpdb->get_var("SHOW TABLES LIKE '$table'");
    
    if ($table_exists) {
        return true; // Table already exists
    }
    
    echo "<p style='color:blue;'>📋 Table '$table' doesn't exist, creating from source...</p>";
    
    // Get table structure from source site
    $url = $source_site_url . '/wp-json/dbsync/v1/table-structure?' . http_build_query([
        'token' => DBSYNC_SECRET,
        'table' => $table
    ]);
    
    $response = wp_remote_get($url, [
        'timeout' => 30,
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

// 🔄 Fallback function if source site is unreachable
function dbsync_fallback_tables() {
    global $wpdb;
    
    // Return core WordPress tables as fallback
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

// 🔄 Main sync logic - Complete Replica Mode
function dbsync_run_sync($source_site_url = 'http://sajah.local') {
    global $wpdb;
    $limit = DBSYNC_CHUNK_SIZE;
    
    // Set maximum execution time
    set_time_limit(DBSYNC_MAX_EXECUTION_TIME);
    
    $start_time = time();
    $total_tables = count(dbsync_tables_to_sync($source_site_url));
    $current_table = 0;

    foreach (dbsync_tables_to_sync($source_site_url) as $table) {
        $current_table++;
        $elapsed = time() - $start_time;
        
        echo "<div style='background: #f9f9f9; padding: 10px; margin: 5px 0; border-left: 3px solid #0073aa;'>";
        echo "<p><strong>[$current_table/$total_tables] Syncing table:</strong> $table</p>";
        echo "<p><small>Elapsed time: {$elapsed}s | Estimated remaining: " . round(($elapsed / $current_table) * ($total_tables - $current_table)) . "s</small></p>";
        
        // 🔧 Ensure table exists on destination (create if missing)
        if (!dbsync_ensure_table_exists($table, $source_site_url)) {
            echo "<p style='color:red;'>❌ Skipping table due to creation failure</p>";
            echo "</div>";
            continue; // Skip to next table
        }
        
        $table_start_time = time();
        $offset = 0;
        $total_synced = 0;
        
        while (true) {
            $url = $source_site_url . '/wp-json/dbsync/v1/table?' . http_build_query([
                'token' => DBSYNC_SECRET,
                'table' => $table,
                'offset' => $offset,
                'limit' => $limit
            ]);

            $response = wp_remote_get($url, [
                'timeout' => 30, // Increase timeout to 30 seconds
                'sslverify' => false // For local development
            ]);
            
            if (is_wp_error($response)) {
                echo '<p style="color:red;">Error: ' . $response->get_error_message() . '</p>';
                break;
            }

            $response_code = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            if ($response_code !== 200) {
                echo '<p style="color:red;">HTTP Error ' . $response_code . ': ' . $body . '</p>';
                break;
            }

            $data = json_decode($body, true);
            
            // Check for JSON decode errors
            if (json_last_error() !== JSON_ERROR_NONE) {
                echo '<p style="color:red;">Invalid JSON response: ' . $body . '</p>';
                break;
            }
            
            // Check if data is valid
            if (empty($data) || !is_array($data) || isset($data['error'])) {
                if (isset($data['error'])) {
                    echo '<p style="color:red;">API Error: ' . $data['error'] . '</p>';
                } else {
                    echo '<p style="color:blue;">No more data to sync for this table.</p>';
                }
                break;
            }

            foreach ($data as $row) {
                $wpdb->replace($table, $row); // REPLACE = insert or update
            }

            $offset += $limit;
            $total_synced += count($data);
            echo "<p>Imported $offset rows so far...</p>";
            flush(); // Show real-time progress
            
            // Safety check: prevent timeout
            if (time() - $start_time > DBSYNC_MAX_EXECUTION_TIME - 30) {
                echo '<p style="color:orange;">⚠️ Approaching time limit, stopping sync...</p>';
                break 2; // Break out of both loops
            }
        }
        
        $table_time = time() - $table_start_time;
        echo "<p style='color:green;'>✅ <strong>Completed $table:</strong> $total_synced records in {$table_time}s</p>";
        echo "</div>";
        flush();
    }

    $total_time = time() - $start_time;
    echo '<div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; margin: 10px 0;">';
    echo '<h3 style="color:green;">🎉 Complete Replica Sync Complete!</h3>';
    echo "<p><strong>Total tables synced:</strong> $current_table</p>";
    echo "<p><strong>Total time:</strong> {$total_time} seconds</p>";
    echo "<p><strong>Average time per table:</strong> " . round($total_time / $current_table, 1) . " seconds</p>";
    echo '</div>';
}
