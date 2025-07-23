<?php
/**
 * Diagnostic script for DB File Sync AJAX issues
 * Visit this file in your browser to run diagnostics
 */

// Load WordPress
if (file_exists('../../../wp-load.php')) {
    require_once('../../../wp-load.php');
} else {
    die('WordPress not found. Place this file in wp-content/plugins/db-file-sync/');
}

echo "<!DOCTYPE html><html><head><title>DB File Sync - Diagnostics</title></head><body>";
echo "<h1>🔧 DB File Sync - Diagnostic Tool</h1>";

// Check 1: Plugin Status
echo "<h2>1. Plugin Status</h2>";
if (is_plugin_active('db-file-sync/db-file-sync.php')) {
    echo "<p style='color:green;'>✅ Plugin is active</p>";
} else {
    echo "<p style='color:red;'>❌ Plugin is NOT active</p>";
}

// Check 2: User Permissions
echo "<h2>2. User Permissions</h2>";
if (current_user_can('manage_options')) {
    echo "<p style='color:green;'>✅ Current user has admin privileges</p>";
} else {
    echo "<p style='color:red;'>❌ Current user does NOT have admin privileges</p>";
    echo "<p><a href='" . wp_login_url() . "'>Please log in as admin</a></p>";
}

// Check 3: Functions Loaded
echo "<h2>3. Required Functions</h2>";
$required_functions = [
    'dbfs_register_file_endpoints',
    'dbfs_ajax_start_sync', 
    'dbfs_ajax_get_progress',
    'dbfs_ajax_get_status',
    'dbfs_check_admin_permission'
];

foreach ($required_functions as $func) {
    $exists = function_exists($func);
    $color = $exists ? 'green' : 'red';
    $status = $exists ? '✅' : '❌';
    echo "<p style='color:$color;'>$status $func()</p>";
}

// Check 4: Classes Loaded
echo "<h2>4. Required Classes</h2>";
$required_classes = [
    'DBFS_File_Sync',
    'DBFS_File_Sync_Ajax',
    'DBFS_Auth',
    'DBFS_Utils'
];

foreach ($required_classes as $class) {
    $exists = class_exists($class);
    $color = $exists ? 'green' : 'red';
    $status = $exists ? '✅' : '❌';
    echo "<p style='color:$color;'>$status $class</p>";
}

// Check 5: REST API Test
echo "<h2>5. REST API Endpoint Test</h2>";

if (current_user_can('manage_options')) {
    $endpoints = [
        'start-sync' => rest_url('filesync/v1/start-sync'),
        'sync-progress' => rest_url('filesync/v1/sync-progress'),
        'sync-status' => rest_url('filesync/v1/sync-status')
    ];
    
    foreach ($endpoints as $name => $url) {
        echo "<h3>Testing: $name</h3>";
        echo "<p>URL: <code>$url</code></p>";
        
        $response = wp_remote_get($url, [
            'headers' => [
                'X-WP-Nonce' => wp_create_nonce('wp_rest')
            ]
        ]);
        
        if (is_wp_error($response)) {
            echo "<p style='color:red;'>❌ Error: " . $response->get_error_message() . "</p>";
        } else {
            $status = wp_remote_retrieve_response_code($response);
            $body = wp_remote_retrieve_body($response);
            
            if ($status == 200 || $status == 400) {
                echo "<p style='color:green;'>✅ Endpoint accessible (Status: $status)</p>";
            } else {
                echo "<p style='color:orange;'>⚠️ Unexpected status: $status</p>";
            }
            
            $data = json_decode($body, true);
            if ($data) {
                echo "<p>Response: <code>" . htmlspecialchars(json_encode($data, JSON_PRETTY_PRINT)) . "</code></p>";
            }
        }
        echo "<hr>";
    }
}

// Check 6: JavaScript Test
echo "<h2>6. JavaScript Test</h2>";
echo "<button id='test-sync-btn' onclick='testSync()' style='background:#0073aa; color:white; padding:10px; border:none; border-radius:5px; cursor:pointer;'>Test AJAX Sync Call</button>";
echo "<div id='test-result' style='margin-top:10px; padding:10px; background:#f0f0f0; display:none;'></div>";

echo "<script>
function testSync() {
    const resultDiv = document.getElementById('test-result');
    resultDiv.style.display = 'block';
    resultDiv.innerHTML = '🔄 Testing AJAX call...';
    
    const url = '" . rest_url('filesync/v1/sync-status') . "';
    console.log('Testing URL:', url);
    
    fetch(url, {
        method: 'GET',
        headers: {
            'Content-Type': 'application/json',
            'X-WP-Nonce': '" . wp_create_nonce('wp_rest') . "'
        }
    })
    .then(response => {
        console.log('Response:', response);
        if (!response.ok) {
            throw new Error('HTTP ' + response.status + ': ' + response.statusText);
        }
        return response.json();
    })
    .then(data => {
        console.log('Data:', data);
        resultDiv.innerHTML = '✅ AJAX Success: <pre>' + JSON.stringify(data, null, 2) + '</pre>';
    })
    .catch(error => {
        console.error('Error:', error);
        resultDiv.innerHTML = '❌ AJAX Error: ' + error.message;
    });
}
</script>";

// Check 7: Configuration
echo "<h2>7. Configuration Check</h2>";
echo "<p><strong>DBSYNC_SECRET:</strong> " . (defined('DBSYNC_SECRET') ? '✅ Defined' : '❌ Not defined') . "</p>";
echo "<p><strong>WordPress REST API:</strong> " . (get_option('permalink_structure') ? '✅ Pretty permalinks enabled' : '⚠️ Plain permalinks (may cause issues)') . "</p>";
echo "<p><strong>Site URL:</strong> " . home_url() . "</p>";
echo "<p><strong>WordPress Version:</strong> " . get_bloginfo('version') . "</p>";

// Instructions
echo "<h2>8. Troubleshooting Steps</h2>";
echo "<ol>";
echo "<li><strong>Open browser console:</strong> Press F12 → Console tab</li>";
echo "<li><strong>Go to admin page:</strong> <a href='" . admin_url('admin.php?page=dbfs-sync') . "'>DB & File Sync</a></li>";
echo "<li><strong>Look for messages:</strong> Check console for 'DB File Sync:' messages</li>";
echo "<li><strong>Click sync button:</strong> Watch console for button click and AJAX errors</li>";
echo "<li><strong>Check this diagnostic:</strong> All items above should show ✅</li>";
echo "</ol>";

echo "<p><strong>💡 If you see errors:</strong></p>";
echo "<ul>";
echo "<li>❌ Plugin not active → Activate the plugin</li>";
echo "<li>❌ Functions missing → Check file permissions and plugin loading</li>";
echo "<li>❌ AJAX errors → Check WordPress REST API settings</li>";
echo "<li>❌ Permission errors → Log in as administrator</li>";
echo "</ul>";

echo "</body></html>";
?> 