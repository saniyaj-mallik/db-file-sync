<?php
/**
 * Integration Tests
 * Tests complete workflows and component interactions
 */

echo "🔗 Testing Integration Scenarios\n";

// Test complete file sync workflow simulation
class MockFileSync extends DBFS_File_Sync {
    public $api_calls = [];
    
    public function get_source_files($directory_type) {
        $this->api_calls[] = "get_source_files:$directory_type";
        
        // Mock source files response
        return [
            [
                'relative_path' => 'integration-test-1.jpg',
                'size' => 1024,
                'hash' => 'abc123def456',
                'modified' => time()
            ],
            [
                'relative_path' => 'folder/integration-test-2.png',
                'size' => 2048,
                'hash' => 'ghi789jkl012',
                'modified' => time()
            ],
            [
                'relative_path' => 'updated-file.txt',
                'size' => 512,
                'hash' => 'new-hash-123',
                'modified' => time()
            ]
        ];
    }
    
    public function download_file($file_info, $directory_type) {
        $this->api_calls[] = "download_file:{$file_info['relative_path']}";
        
        // Mock successful download
        $upload_dir = wp_upload_dir();
        $local_path = $upload_dir['basedir'] . '/' . $file_info['relative_path'];
        
        // Ensure directory exists
        $dir = dirname($local_path);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }
        
        // Create mock file
        file_put_contents($local_path, "Mock content for {$file_info['relative_path']}");
        
        return true;
    }
    
    public function get_api_calls() {
        return $this->api_calls;
    }
}

// Test full sync workflow
$mock_sync = new MockFileSync('http://integration-test.local', 1024, 300);
$results = $mock_sync->sync_directory('uploads');

test_assertNotEmpty($results, 'Integration sync should return results');
test_assert(isset($results['downloaded']), 'Results should include downloaded count');
test_assert(isset($results['errors']), 'Results should include error count');

$api_calls = $mock_sync->get_api_calls();
test_assertNotEmpty($api_calls, 'API calls should be recorded');
test_assert(in_array('get_source_files:uploads', $api_calls), 'Should call get_source_files');

// Count download calls
$download_calls = array_filter($api_calls, function($call) {
    return strpos($call, 'download_file:') === 0;
});
test_assert(count($download_calls) > 0, 'Should make download calls');

// Test authentication integration
$auth_integration_tests = [
    'valid_token' => DBSYNC_SECRET,
    'invalid_token' => 'wrong-token',
    'empty_token' => ''
];

foreach ($auth_integration_tests as $test_name => $token) {
    $request = new WP_REST_Request();
    $request->set_param('token', $token);
    $request->set_param('directory', 'uploads');
    
    $response = dbfs_get_files_list($request);
    
    if ($test_name === 'valid_token') {
        test_assertEqual(200, $response->status, 'Valid token should allow access');
    } else {
        test_assertEqual(403, $response->status, "Invalid token ($test_name) should be rejected");
    }
}

// Test file type security integration
$security_files = [
    'safe-image.jpg' => true,
    'safe-document.pdf' => true,
    'dangerous-script.exe' => false,
    'malicious-file.bat' => false
];

foreach ($security_files as $filename => $should_allow) {
    $is_allowed = DBFS_Auth::is_file_type_allowed($filename);
    
    if ($should_allow) {
        test_assert($is_allowed, "Safe file ($filename) should be allowed");
    } else {
        test_assert(!$is_allowed, "Dangerous file ($filename) should be blocked");
    }
}

// Test complete AJAX workflow simulation
class IntegrationAjaxTest {
    public static function test_complete_ajax_flow() {
        // Step 1: Start sync
        $start_request = new WP_REST_Request();
        $start_request->set_param('source_url', 'http://ajax-test.local');
        $start_request->set_param('sync_type', 'file');
        
        $start_response = dbfs_ajax_start_sync($start_request);
        
        if (!$start_response instanceof WP_REST_Response) {
            return false;
        }
        
        if (!isset($start_response->data['success']) || !$start_response->data['success']) {
            return false;
        }
        
        // Step 2: Check status
        $status_response = dbfs_ajax_get_status(new WP_REST_Request());
        
        if (!$status_response instanceof WP_REST_Response) {
            return false;
        }
        
        // Step 3: Check progress (should show no active sync since we're mocking)
        $progress_response = dbfs_ajax_get_progress(new WP_REST_Request());
        
        if (!$progress_response instanceof WP_REST_Response) {
            return false;
        }
        
        return true;
    }
}

$ajax_flow_success = IntegrationAjaxTest::test_complete_ajax_flow();
test_assert($ajax_flow_success, 'Complete AJAX workflow should succeed');

// Test error handling integration
class ErrorHandlingTest {
    public static function test_error_scenarios() {
        $error_scenarios = [];
        
        // Test invalid directory
        $invalid_dir_request = new WP_REST_Request();
        $invalid_dir_request->set_param('token', DBSYNC_SECRET);
        $invalid_dir_request->set_param('directory', 'non-existent-directory');
        
        $invalid_dir_response = dbfs_get_files_list($invalid_dir_request);
        $error_scenarios['invalid_directory'] = $invalid_dir_response->status === 400;
        
        // Test missing parameters
        $missing_param_request = new WP_REST_Request();
        $missing_param_response = dbfs_ajax_start_sync($missing_param_request);
        $error_scenarios['missing_params'] = $missing_param_response->status === 400;
        
        // Test invalid file path
        $invalid_path = '../../../etc/passwd';
        $error_scenarios['invalid_path'] = !DBFS_Auth::validate_file_path($invalid_path);
        
        return $error_scenarios;
    }
}

$error_tests = ErrorHandlingTest::test_error_scenarios();
test_assert($error_tests['invalid_directory'], 'Invalid directory should be handled');
test_assert($error_tests['missing_params'], 'Missing parameters should be handled');
test_assert($error_tests['invalid_path'], 'Invalid file paths should be rejected');

// Test utility integration
$integration_utils_tests = [
    'file_size_formatting' => DBFS_Utils::format_file_size(1536) === '1.50 KB',
    'directory_creation' => DBFS_Utils::ensure_directory_exists('/tmp/wp-test/integration-test-dir'),
    'file_comparison' => function() {
        $source = [['relative_path' => 'test.txt', 'hash' => 'abc123']];
        $local = [['relative_path' => 'test.txt', 'hash' => 'different']];
        $comparison = DBFS_Utils::compare_file_lists($source, $local);
        return count($comparison['download']) === 1;
    }
];

foreach ($integration_utils_tests as $test_name => $test_result) {
    if (is_callable($test_result)) {
        $test_result = $test_result();
    }
    test_assert($test_result, "Utils integration test: $test_name");
}

// Test memory and performance considerations
$performance_tests = [
    'large_file_list' => function() {
        $large_list = [];
        for ($i = 0; $i < 1000; $i++) {
            $large_list[] = [
                'relative_path' => "file-$i.txt",
                'hash' => md5("content-$i"),
                'size' => $i * 100
            ];
        }
        
        $start_time = microtime(true);
        $comparison = DBFS_Utils::compare_file_lists($large_list, []);
        $end_time = microtime(true);
        
        // Should complete in reasonable time (< 1 second)
        return ($end_time - $start_time) < 1.0 && count($comparison['download']) === 1000;
    },
    
    'memory_usage' => function() {
        $start_memory = memory_get_usage();
        
        // Create and process data
        $data = [];
        for ($i = 0; $i < 100; $i++) {
            $data[] = DBFS_Utils::format_file_size($i * 1024);
        }
        
        $end_memory = memory_get_usage();
        $memory_used = $end_memory - $start_memory;
        
        // Should not use excessive memory (< 1MB for this test)
        return $memory_used < 1024 * 1024;
    }
];

foreach ($performance_tests as $test_name => $test_func) {
    $result = $test_func();
    test_assert($result, "Performance test: $test_name");
}

// Test plugin constants and configuration
$config_tests = [
    'secret_defined' => defined('DBSYNC_SECRET') && !empty(DBSYNC_SECRET),
    'plugin_dir_defined' => defined('DBFS_PLUGIN_DIR'),
    'abspath_defined' => defined('ABSPATH'),
    'content_dir_defined' => defined('WP_CONTENT_DIR')
];

foreach ($config_tests as $test_name => $test_result) {
    test_assert($test_result, "Configuration test: $test_name");
}

// Test cleanup and resource management
$cleanup_tests = [
    'temp_files_cleanup' => function() {
        $temp_file = '/tmp/wp-test/temp-integration-test.txt';
        file_put_contents($temp_file, 'temporary content');
        
        $exists_before = file_exists($temp_file);
        unlink($temp_file);
        $exists_after = file_exists($temp_file);
        
        return $exists_before && !$exists_after;
    },
    
    'directory_cleanup' => function() {
        $temp_dir = '/tmp/wp-test/temp-integration-dir';
        mkdir($temp_dir, 0755, true);
        
        $exists_before = is_dir($temp_dir);
        rmdir($temp_dir);
        $exists_after = is_dir($temp_dir);
        
        return $exists_before && !$exists_after;
    }
];

foreach ($cleanup_tests as $test_name => $test_func) {
    $result = $test_func();
    test_assert($result, "Cleanup test: $test_name");
}

echo "✅ Integration tests completed\n"; 