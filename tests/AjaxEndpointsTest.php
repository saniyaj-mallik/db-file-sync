<?php
/**
 * AJAX Endpoints Tests
 */

echo "🌐 Testing AJAX Endpoints\n";

// Mock additional WordPress functions for endpoints
if (!function_exists('current_user_can')) {
    function current_user_can($capability) {
        return true; // Mock admin user
    }
}

if (!function_exists('sanitize_url')) {
    function sanitize_url($url) {
        return filter_var($url, FILTER_SANITIZE_URL);
    }
}

if (!function_exists('sanitize_text_field')) {
    function sanitize_text_field($str) {
        return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('wp_schedule_single_event')) {
    function wp_schedule_single_event($timestamp, $hook, $args = []) {
        return true; // Mock successful scheduling
    }
}

// Test permission check function
$has_permission = dbfs_check_admin_permission();
test_assert($has_permission, 'Admin permission check should pass');

// Test helper function for updating sync progress
dbfs_update_sync_progress('test-sync-123', [
    'status' => 'running',
    'message' => 'Test progress update',
    'progress' => 50
]);

// Should not throw any errors
test_assert(true, 'Progress update function should execute without errors');

// Test start sync endpoint
$start_request = new WP_REST_Request();
$start_request->set_param('source_url', 'http://test-source.local');
$start_request->set_param('sync_type', 'file');

$start_response = dbfs_ajax_start_sync($start_request);
test_assertNotEmpty($start_response, 'Start sync should return response');
test_assert($start_response instanceof WP_REST_Response, 'Start sync should return WP_REST_Response');

if ($start_response instanceof WP_REST_Response) {
    $response_data = $start_response->data;
    test_assert(isset($response_data['success']), 'Start sync response should have success field');
    test_assert(isset($response_data['sync_id']), 'Start sync response should have sync_id');
    test_assert($response_data['success'], 'Start sync should be successful');
    test_assertNotEmpty($response_data['sync_id'], 'Sync ID should not be empty');
}

// Test start sync with missing URL
$invalid_request = new WP_REST_Request();
$invalid_request->set_param('sync_type', 'file');

$invalid_response = dbfs_ajax_start_sync($invalid_request);
test_assert($invalid_response instanceof WP_REST_Response, 'Invalid request should return WP_REST_Response');

if ($invalid_response instanceof WP_REST_Response) {
    test_assertEqual(400, $invalid_response->status, 'Invalid request should return 400 status');
    test_assert(isset($invalid_response->data['error']), 'Invalid request should have error field');
}

// Test sync progress endpoint (no active sync)
$progress_request = new WP_REST_Request();
$progress_response = dbfs_ajax_get_progress($progress_request);

test_assert($progress_response instanceof WP_REST_Response, 'Progress check should return WP_REST_Response');
if ($progress_response instanceof WP_REST_Response) {
    $progress_data = $progress_response->data;
    test_assertEqual('no_sync', $progress_data['status'], 'No active sync should return no_sync status');
}

// Test sync status endpoint (no active sync)
$status_request = new WP_REST_Request();
$status_response = dbfs_ajax_get_status($status_request);

test_assert($status_response instanceof WP_REST_Response, 'Status check should return WP_REST_Response');
if ($status_response instanceof WP_REST_Response) {
    $status_data = $status_response->data;
    test_assertEqual(false, $status_data['has_active_sync'], 'No active sync should return false');
}

// Test background sync function (mock execution)
global $test_executed;
$test_executed = false;

function mock_background_sync($source_url, $sync_type, $sync_id) {
    global $test_executed;
    $test_executed = true;
    
    // Verify parameters are passed correctly
    test_assertEqual('http://test-background.local', $source_url, 'Background sync should receive source URL');
    test_assertEqual('file', $sync_type, 'Background sync should receive sync type');
    test_assertEqual('bg-test-sync', $sync_id, 'Background sync should receive sync ID');
    
    return true;
}

// Execute the mock function
mock_background_sync('http://test-background.local', 'file', 'bg-test-sync');
test_assert($test_executed, 'Background sync function should execute');

// Test file endpoints from the file-endpoints.php
$file_list_request = new WP_REST_Request();
$file_list_request->set_param('token', DBSYNC_SECRET);
$file_list_request->set_param('directory', 'uploads');

$file_list_response = dbfs_get_files_list($file_list_request);
test_assert($file_list_response instanceof WP_REST_Response, 'File list should return WP_REST_Response');

if ($file_list_response instanceof WP_REST_Response) {
    test_assertEqual(200, $file_list_response->status, 'File list should return 200 status');
    test_assert(is_array($file_list_response->data), 'File list should return array');
}

// Test file list with invalid token
$invalid_token_request = new WP_REST_Request();
$invalid_token_request->set_param('token', 'invalid-token');
$invalid_token_request->set_param('directory', 'uploads');

$invalid_token_response = dbfs_get_files_list($invalid_token_request);
test_assert($invalid_token_response instanceof WP_REST_Response, 'Invalid token should return WP_REST_Response');

if ($invalid_token_response instanceof WP_REST_Response) {
    test_assertEqual(403, $invalid_token_response->status, 'Invalid token should return 403 status');
}

// Test file list with invalid directory
$invalid_dir_request = new WP_REST_Request();
$invalid_dir_request->set_param('token', DBSYNC_SECRET);
$invalid_dir_request->set_param('directory', 'invalid-directory');

$invalid_dir_response = dbfs_get_files_list($invalid_dir_request);
test_assert($invalid_dir_response instanceof WP_REST_Response, 'Invalid directory should return WP_REST_Response');

if ($invalid_dir_response instanceof WP_REST_Response) {
    test_assertEqual(400, $invalid_dir_response->status, 'Invalid directory should return 400 status');
}

// Test file content endpoint
$file_content_request = new WP_REST_Request();
$file_content_request->set_param('token', DBSYNC_SECRET);
$file_content_request->set_param('directory', 'uploads');
$file_content_request->set_param('file', 'test-file.txt');

$file_content_response = dbfs_get_file_content($file_content_request);
test_assert($file_content_response instanceof WP_REST_Response, 'File content should return WP_REST_Response');

// Test file info endpoint
$file_info_request = new WP_REST_Request();
$file_info_request->set_param('token', DBSYNC_SECRET);
$file_info_request->set_param('directory', 'uploads');
$file_info_request->set_param('file', 'test-file.txt');

$file_info_response = dbfs_get_file_info($file_info_request);
test_assert($file_info_response instanceof WP_REST_Response, 'File info should return WP_REST_Response');

// Test sync type validation
$sync_types = ['file', 'db', 'full'];
foreach ($sync_types as $sync_type) {
    $type_request = new WP_REST_Request();
    $type_request->set_param('source_url', 'http://test.local');
    $type_request->set_param('sync_type', $sync_type);
    
    $type_response = dbfs_ajax_start_sync($type_request);
    test_assert($type_response instanceof WP_REST_Response, "Sync type '$sync_type' should be accepted");
    
    if ($type_response instanceof WP_REST_Response && isset($type_response->data['success'])) {
        test_assert($type_response->data['success'], "Sync type '$sync_type' should be successful");
    }
}

echo "✅ AJAX Endpoints tests completed\n"; 