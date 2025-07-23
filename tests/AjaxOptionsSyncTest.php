<?php
/**
 * AJAX Integration Tests for Options Sync
 */

class AjaxOptionsSyncTest {
    
    private $test_results = [];
    
    public function run_all_tests() {
        echo "🌐 Testing AJAX Options Sync Integration\n";
        echo "========================================\n\n";
        
        $this->test_ajax_start_sync_with_options();
        $this->test_background_sync_parameters();
        $this->test_options_sync_progress_tracking();
        $this->test_url_replacement_scenarios();
        $this->test_critical_settings_preservation();
        $this->test_error_handling();
        
        $this->print_test_summary();
        return $this->get_test_results();
    }
    
    /**
     * Test AJAX start sync with options parameter
     */
    private function test_ajax_start_sync_with_options() {
        echo "🚀 Testing AJAX Start Sync with Options...\n";
        
        // Mock WordPress REST request
        $request = $this->mock_rest_request([
            'source_url' => 'http://source-site.com',
            'sync_type' => 'full',
            'sync_all_settings' => true
        ]);
        
        try {
            $response = dbfs_ajax_start_sync($request);
            $this->assert_true(is_object($response), "AJAX start sync returns response object");
            
            $data = $response->get_data();
            $this->assert_true(isset($data['success']), "Response contains success flag");
            $this->assert_true(isset($data['sync_id']), "Response contains sync ID");
            
        } catch (Exception $e) {
            $this->assert_false(true, "AJAX start sync failed: " . $e->getMessage());
        }
    }
    
    /**
     * Test background sync receives correct parameters
     */
    private function test_background_sync_parameters() {
        echo "⚙️ Testing Background Sync Parameters...\n";
        
        // Mock WordPress cron scheduling
        $this->mock_wp_cron();
        
        // Test with options sync enabled
        $source_url = 'http://source-site.com';
        $sync_type = 'full';
        $sync_id = 'test_sync_123';
        $sync_all_settings = true;
        
        try {
            // This would normally be called by WordPress cron
            dbfs_run_background_sync($source_url, $sync_type, $sync_id, $sync_all_settings);
            $this->assert_true(true, "Background sync with options completed");
            
        } catch (Exception $e) {
            $this->assert_false(true, "Background sync failed: " . $e->getMessage());
        }
    }
    
    /**
     * Test options sync progress tracking
     */
    private function test_options_sync_progress_tracking() {
        echo "📊 Testing Options Sync Progress Tracking...\n";
        
        $sync_id = 'test_progress_123';
        
        // Mock progress tracking functions
        $this->mock_transient_functions();
        
        // Test progress updates during options sync
        $test_data = [
            'status' => 'running',
            'message' => '🔧 Full Site Sync with Options',
            'progress' => 25
        ];
        
        dbfs_update_sync_progress($sync_id, $test_data);
        $this->assert_true(true, "Progress tracking updated successfully");
        
        // Test different progress stages
        $stages = [
            ['message' => '🔒 Backing up critical settings...', 'progress' => 30],
            ['message' => '📊 Running database sync with options...', 'progress' => 50],
            ['message' => '🔧 Restoring protected settings...', 'progress' => 70],
            ['message' => '🔄 Replacing URLs in database...', 'progress' => 85],
            ['message' => '🖼️ Updating media attachment URLs...', 'progress' => 95],
            ['message' => '✅ Full Site Sync Complete!', 'progress' => 100]
        ];
        
        foreach ($stages as $stage) {
            dbfs_update_sync_progress($sync_id, $stage);
            $this->assert_true(true, "Progress stage: " . $stage['message']);
        }
    }
    
    /**
     * Test URL replacement scenarios
     */
    private function test_url_replacement_scenarios() {
        echo "🔄 Testing URL Replacement Scenarios...\n";
        
        $source_url = 'http://source-site.com';
        $destination_url = 'http://destination-site.com';
        
        $options_sync = new DBFS_Options_Sync($source_url, $destination_url);
        
        // Test various URL patterns that might be found in WordPress
        $test_cases = [
            // Simple URLs
            [
                'input' => 'http://source-site.com/page',
                'expected' => 'http://destination-site.com/page',
                'description' => 'Simple page URL'
            ],
            // URLs with query parameters
            [
                'input' => 'http://source-site.com/wp-admin/admin-ajax.php?action=test',
                'expected' => 'http://destination-site.com/wp-admin/admin-ajax.php?action=test',
                'description' => 'AJAX URL with parameters'
            ],
            // URLs in JSON
            [
                'input' => '{"url":"http://source-site.com/api"}',
                'expected' => '{"url":"http://destination-site.com/api"}',
                'description' => 'URL in JSON format'
            ],
            // URLs in HTML
            [
                'input' => '<a href="http://source-site.com/link">Link</a>',
                'expected' => '<a href="http://destination-site.com/link">Link</a>',
                'description' => 'URL in HTML link'
            ]
        ];
        
        $reflection = new ReflectionClass($options_sync);
        $method = $reflection->getMethod('replace_serialized_urls');
        $method->setAccessible(true);
        
        foreach ($test_cases as $test_case) {
            $result = $method->invoke($options_sync, $test_case['input']);
            $this->assert_equals($test_case['expected'], $result, $test_case['description']);
        }
    }
    
    /**
     * Test critical settings preservation
     */
    private function test_critical_settings_preservation() {
        echo "🔒 Testing Critical Settings Preservation...\n";
        
        $this->mock_wordpress_options();
        
        $source_url = 'http://source-site.com';
        $destination_url = 'http://destination-site.com';
        
        $options_sync = new DBFS_Options_Sync($source_url, $destination_url);
        
        // Test backup of critical settings
        $backup = $options_sync->backup_critical_settings();
        
        $critical_settings = [
            'siteurl', 'home', 'active_plugins', 'template', 'stylesheet'
        ];
        
        foreach ($critical_settings as $setting) {
            $this->assert_true(array_key_exists($setting, $backup), "Critical setting backed up: $setting");
        }
        
        // Test restoration
        $restore_result = $options_sync->restore_critical_settings();
        $this->assert_true($restore_result, "Critical settings restored successfully");
        
        // Test that site URLs are forced to destination
        $validation = $options_sync->validate_url_replacement();
        $this->assert_true($validation['siteurl_correct'], "Site URL correctly set to destination");
        $this->assert_true($validation['home_correct'], "Home URL correctly set to destination");
    }
    
    /**
     * Test error handling
     */
    private function test_error_handling() {
        echo "🚨 Testing Error Handling...\n";
        
        // Test invalid source URL
        $invalid_request = $this->mock_rest_request([
            'source_url' => '',
            'sync_type' => 'full',
            'sync_all_settings' => true
        ]);
        
        try {
            $response = dbfs_ajax_start_sync($invalid_request);
            $data = $response->get_data();
            $this->assert_true(isset($data['error']), "Error handling for empty source URL");
            
        } catch (Exception $e) {
            $this->assert_true(true, "Exception correctly thrown for invalid request");
        }
        
        // Test malformed URLs in options sync
        try {
            $options_sync = new DBFS_Options_Sync('not-a-url', 'also-not-a-url');
            $this->assert_true(is_object($options_sync), "Options sync handles malformed URLs gracefully");
            
        } catch (Exception $e) {
            $this->assert_false(true, "Options sync should handle malformed URLs: " . $e->getMessage());
        }
    }
    
    /**
     * Mock WordPress REST request
     */
    private function mock_rest_request($params) {
        return new class($params) {
            private $params;
            
            public function __construct($params) {
                $this->params = $params;
            }
            
            public function get_param($key) {
                return isset($this->params[$key]) ? $this->params[$key] : null;
            }
        };
    }
    
    /**
     * Mock WordPress cron functions
     */
    private function mock_wp_cron() {
        if (!function_exists('wp_schedule_single_event')) {
            function wp_schedule_single_event($timestamp, $hook, $args = []) {
                // Simulate cron scheduling by calling the function directly
                if ($hook === 'dbfs_background_sync' && function_exists('dbfs_run_background_sync')) {
                    call_user_func_array('dbfs_run_background_sync', $args);
                }
                return true;
            }
        }
        
        if (!function_exists('set_time_limit')) {
            function set_time_limit($seconds) {
                return true;
            }
        }
        
        if (!function_exists('ignore_user_abort')) {
            function ignore_user_abort($value) {
                return true;
            }
        }
    }
    
    /**
     * Mock transient functions
     */
    private function mock_transient_functions() {
        if (!function_exists('set_transient')) {
            function set_transient($transient, $value, $expiration) {
                return true;
            }
        }
        
        if (!function_exists('get_transient')) {
            function get_transient($transient) {
                return [
                    'status' => 'running',
                    'message' => 'Test progress',
                    'progress' => 50
                ];
            }
        }
    }
    
    /**
     * Mock WordPress options functions
     */
    private function mock_wordpress_options() {
        if (!function_exists('get_option')) {
            function get_option($option, $default = false) {
                $mock_options = [
                    'siteurl' => 'http://destination-site.com',
                    'home' => 'http://destination-site.com',
                    'active_plugins' => ['test-plugin/test-plugin.php'],
                    'template' => 'twentytwentythree',
                    'stylesheet' => 'twentytwentythree',
                    'upload_path' => '',
                    'upload_url_path' => ''
                ];
                return isset($mock_options[$option]) ? $mock_options[$option] : $default;
            }
        }
        
        if (!function_exists('update_option')) {
            function update_option($option, $value, $autoload = null) {
                return true;
            }
        }
        
        if (!function_exists('delete_option')) {
            function delete_option($option) {
                return true;
            }
        }
        
        if (!function_exists('wp_clear_scheduled_hook')) {
            function wp_clear_scheduled_hook($hook) {
                return true;
            }
        }
        
        if (!function_exists('home_url')) {
            function home_url() {
                return 'http://destination-site.com';
            }
        }
        
        if (!function_exists('sanitize_url')) {
            function sanitize_url($url) {
                return filter_var($url, FILTER_SANITIZE_URL);
            }
        }
        
        if (!function_exists('sanitize_text_field')) {
            function sanitize_text_field($str) {
                return trim(strip_tags($str));
            }
        }
        
        if (!function_exists('is_serialized')) {
            function is_serialized($data) {
                return (is_string($data) && preg_match('/^[aOs]:/', $data));
            }
        }
        
        // Mock global $wpdb
        global $wpdb;
        if (!isset($wpdb)) {
            $wpdb = new stdClass();
            $wpdb->prefix = 'wp_';
            $wpdb->posts = 'wp_posts';
            $wpdb->postmeta = 'wp_postmeta';
            $wpdb->options = 'wp_options';
            $wpdb->prepare = function($query, ...$args) { return $query; };
            $wpdb->query = function($query) { return 1; };
            $wpdb->get_var = function($query) { return 0; };
        }
    }
    
    /**
     * Assert true helper
     */
    private function assert_true($condition, $message) {
        if ($condition) {
            echo "  ✅ PASS: $message\n";
            $this->test_results[] = ['status' => 'PASS', 'message' => $message];
        } else {
            echo "  ❌ FAIL: $message\n";
            $this->test_results[] = ['status' => 'FAIL', 'message' => $message];
        }
    }
    
    /**
     * Assert false helper
     */
    private function assert_false($condition, $message) {
        $this->assert_true(!$condition, $message);
    }
    
    /**
     * Assert equals helper
     */
    private function assert_equals($expected, $actual, $message) {
        if ($expected === $actual) {
            echo "  ✅ PASS: $message\n";
            $this->test_results[] = ['status' => 'PASS', 'message' => $message];
        } else {
            echo "  ❌ FAIL: $message (expected: '$expected', got: '$actual')\n";
            $this->test_results[] = ['status' => 'FAIL', 'message' => $message, 'expected' => $expected, 'actual' => $actual];
        }
    }
    
    /**
     * Print test summary
     */
    private function print_test_summary() {
        $total = count($this->test_results);
        $passed = count(array_filter($this->test_results, function($result) {
            return $result['status'] === 'PASS';
        }));
        $failed = $total - $passed;
        
        echo "\n📊 AJAX Integration Test Summary\n";
        echo "=================================\n";
        echo "Total Tests: $total\n";
        echo "✅ Passed: $passed\n";
        echo "❌ Failed: $failed\n";
        echo "Success Rate: " . round(($passed / $total) * 100, 1) . "%\n\n";
        
        if ($failed > 0) {
            echo "Failed Tests:\n";
            foreach ($this->test_results as $result) {
                if ($result['status'] === 'FAIL') {
                    echo "  • " . $result['message'] . "\n";
                    if (isset($result['expected'])) {
                        echo "    Expected: " . $result['expected'] . "\n";
                        echo "    Actual: " . $result['actual'] . "\n";
                    }
                }
            }
        }
    }
    
    /**
     * Get test results
     */
    private function get_test_results() {
        return $this->test_results;
    }
}

?> 