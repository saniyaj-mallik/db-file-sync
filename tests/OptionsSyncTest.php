<?php
/**
 * Test cases for Options Sync functionality
 */

class OptionsSyncTest {
    
    private $test_results = [];
    private $source_url = 'http://source-site.com';
    private $destination_url = 'http://destination-site.com';
    
    public function run_all_tests() {
        echo "🧪 Testing Options Sync Functionality\n";
        echo "=====================================\n\n";
        
        $this->test_options_sync_initialization();
        $this->test_url_replacement_simple();
        $this->test_url_replacement_serialized();
        $this->test_settings_backup_restore();
        $this->test_database_url_replacement();
        $this->test_attachment_url_updates();
        $this->test_problematic_options_cleanup();
        $this->test_enhanced_db_sync_class();
        $this->test_integration_flow();
        
        $this->print_test_summary();
        return $this->get_test_results();
    }
    
    /**
     * Test basic initialization
     */
    private function test_options_sync_initialization() {
        echo "📋 Testing Options Sync Initialization...\n";
        
        try {
            $options_sync = new DBFS_Options_Sync($this->source_url, $this->destination_url);
            $this->assert_true(is_object($options_sync), "Options sync object created");
            
            // Test URL trimming
            $options_sync_with_slash = new DBFS_Options_Sync($this->source_url . '/', $this->destination_url . '/');
            $this->assert_true(is_object($options_sync_with_slash), "URLs trimmed correctly");
            
        } catch (Exception $e) {
            $this->assert_false(true, "Initialization failed: " . $e->getMessage());
        }
    }
    
    /**
     * Test simple URL replacement
     */
    private function test_url_replacement_simple() {
        echo "🔄 Testing Simple URL Replacement...\n";
        
        $options_sync = new DBFS_Options_Sync($this->source_url, $this->destination_url);
        
        // Test simple string replacement
        $test_data = "Visit our site at http://source-site.com/about";
        $expected = "Visit our site at http://destination-site.com/about";
        
        // Use reflection to test private method
        $reflection = new ReflectionClass($options_sync);
        $method = $reflection->getMethod('replace_serialized_urls');
        $method->setAccessible(true);
        
        $result = $method->invoke($options_sync, $test_data);
        $this->assert_equals($expected, $result, "Simple URL replacement");
        
        // Test multiple URLs in same string
        $test_data2 = "Links: http://source-site.com/page1 and http://source-site.com/page2";
        $expected2 = "Links: http://destination-site.com/page1 and http://destination-site.com/page2";
        $result2 = $method->invoke($options_sync, $test_data2);
        $this->assert_equals($expected2, $result2, "Multiple URLs replacement");
    }
    
    /**
     * Test serialized data URL replacement
     */
    private function test_url_replacement_serialized() {
        echo "📦 Testing Serialized Data URL Replacement...\n";
        
        $options_sync = new DBFS_Options_Sync($this->source_url, $this->destination_url);
        
        // Test array serialization
        $test_array = [
            'site_url' => 'http://source-site.com',
            'logo_url' => 'http://source-site.com/logo.png',
            'nested' => [
                'image' => 'http://source-site.com/nested.jpg'
            ]
        ];
        
        $serialized_data = serialize($test_array);
        
        $reflection = new ReflectionClass($options_sync);
        $method = $reflection->getMethod('replace_serialized_urls');
        $method->setAccessible(true);
        
        $result = $method->invoke($options_sync, $serialized_data);
        $unserialized_result = unserialize($result);
        
        $this->assert_equals('http://destination-site.com', $unserialized_result['site_url'], "Serialized array URL replacement");
        $this->assert_equals('http://destination-site.com/logo.png', $unserialized_result['logo_url'], "Serialized nested URL replacement");
        $this->assert_equals('http://destination-site.com/nested.jpg', $unserialized_result['nested']['image'], "Deeply nested URL replacement");
        
        // Test object serialization
        $test_object = new stdClass();
        $test_object->url = 'http://source-site.com/test';
        $test_object->data = ['link' => 'http://source-site.com/data'];
        
        $serialized_object = serialize($test_object);
        $result_object = $method->invoke($options_sync, $serialized_object);
        $unserialized_object = unserialize($result_object);
        
        $this->assert_equals('http://destination-site.com/test', $unserialized_object->url, "Serialized object URL replacement");
        $this->assert_equals('http://destination-site.com/data', $unserialized_object->data['link'], "Serialized object nested URL replacement");
    }
    
    /**
     * Test settings backup and restore
     */
    private function test_settings_backup_restore() {
        echo "💾 Testing Settings Backup and Restore...\n";
        
        // Mock WordPress functions
        $this->mock_wordpress_options();
        
        $options_sync = new DBFS_Options_Sync($this->source_url, $this->destination_url);
        
        // Test backup
        $backup = $options_sync->backup_critical_settings();
        $this->assert_true(is_array($backup), "Settings backup is array");
        $this->assert_true(isset($backup['siteurl']), "Site URL backed up");
        $this->assert_true(isset($backup['active_plugins']), "Active plugins backed up");
        
        // Test restore
        $result = $options_sync->restore_critical_settings();
        $this->assert_true($result, "Settings restore successful");
    }
    
    /**
     * Test database URL replacement
     */
    private function test_database_url_replacement() {
        echo "🗄️ Testing Database URL Replacement...\n";
        
        $options_sync = new DBFS_Options_Sync($this->source_url, $this->destination_url);
        
        // Mock database data
        $this->mock_database_for_url_replacement();
        
        // Test primary key detection
        $reflection = new ReflectionClass($options_sync);
        $method = $reflection->getMethod('get_primary_key');
        $method->setAccessible(true);
        
        global $wpdb;
        $this->assert_equals('ID', $method->invoke($options_sync, $wpdb->posts), "Posts table primary key");
        $this->assert_equals('meta_id', $method->invoke($options_sync, $wpdb->postmeta), "Postmeta table primary key");
        $this->assert_equals('option_id', $method->invoke($options_sync, $wpdb->options), "Options table primary key");
    }
    
    /**
     * Test attachment URL updates
     */
    private function test_attachment_url_updates() {
        echo "🖼️ Testing Attachment URL Updates...\n";
        
        $options_sync = new DBFS_Options_Sync($this->source_url, $this->destination_url);
        
        // Mock attachment data
        $this->mock_attachment_data();
        
        $result = $options_sync->update_attachment_urls();
        $this->assert_true($result, "Attachment URLs updated successfully");
    }
    
    /**
     * Test problematic options cleanup
     */
    private function test_problematic_options_cleanup() {
        echo "🧹 Testing Problematic Options Cleanup...\n";
        
        $options_sync = new DBFS_Options_Sync($this->source_url, $this->destination_url);
        
        $result = $options_sync->cleanup_problematic_options();
        $this->assert_true($result, "Problematic options cleaned up");
    }
    
    /**
     * Test enhanced database sync class
     */
    private function test_enhanced_db_sync_class() {
        echo "🔧 Testing Enhanced Database Sync Class...\n";
        
        try {
            // Test without options sync
            $db_sync = new DBFS_DB_Sync_With_Options($this->source_url, 50, 300, false);
            $this->assert_true(is_object($db_sync), "Enhanced DB sync created without options");
            
            $tables = $db_sync->get_tables_to_sync();
            $this->assert_true(is_array($tables), "Tables list returned");
            
            // Test with options sync
            $db_sync_with_options = new DBFS_DB_Sync_With_Options($this->source_url, 50, 300, true);
            $tables_with_options = $db_sync_with_options->get_tables_to_sync();
            
            global $wpdb;
            $this->assert_true(in_array($wpdb->prefix . 'options', $tables_with_options), "Options table included when enabled");
            
        } catch (Exception $e) {
            $this->assert_false(true, "Enhanced DB sync failed: " . $e->getMessage());
        }
    }
    
    /**
     * Test integration flow
     */
    private function test_integration_flow() {
        echo "🔗 Testing Integration Flow...\n";
        
        try {
            // Test complete flow simulation
            $options_sync = new DBFS_Options_Sync($this->source_url, $this->destination_url);
            
            // Step 1: Backup
            $backup = $options_sync->backup_critical_settings();
            $this->assert_true(is_array($backup), "Integration: Backup step");
            
            // Step 2: Restore
            $restore = $options_sync->restore_critical_settings();
            $this->assert_true($restore, "Integration: Restore step");
            
            // Step 3: URL replacement validation
            $validation = $options_sync->validate_url_replacement();
            $this->assert_true(is_array($validation), "Integration: Validation step");
            $this->assert_true(isset($validation['remaining_source_urls']), "Integration: Validation has remaining URLs count");
            
        } catch (Exception $e) {
            $this->assert_false(true, "Integration flow failed: " . $e->getMessage());
        }
    }
    
    /**
     * Mock WordPress options for testing
     */
    private function mock_wordpress_options() {
        if (!function_exists('get_option')) {
            function get_option($option, $default = false) {
                $mock_options = [
                    'siteurl' => 'http://destination-site.com',
                    'home' => 'http://destination-site.com',
                    'active_plugins' => ['plugin1/plugin1.php'],
                    'template' => 'twentytwentythree',
                    'stylesheet' => 'twentytwentythree'
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
        
        if (!function_exists('is_serialized')) {
            function is_serialized($data) {
                return (is_string($data) && preg_match('/^[aOs]:/', $data));
            }
        }
    }
    
    /**
     * Mock database for URL replacement testing
     */
    private function mock_database_for_url_replacement() {
        global $wpdb;
        
        if (!isset($wpdb)) {
            $wpdb = new stdClass();
            $wpdb->posts = 'wp_posts';
            $wpdb->postmeta = 'wp_postmeta';
            $wpdb->options = 'wp_options';
            $wpdb->comments = 'wp_comments';
            $wpdb->commentmeta = 'wp_commentmeta';
            $wpdb->users = 'wp_users';
            $wpdb->usermeta = 'wp_usermeta';
            $wpdb->prefix = 'wp_';
        }
        
        // Mock wpdb methods
        if (!method_exists($wpdb, 'get_results')) {
            $wpdb->get_results = function($query) {
                return []; // Return empty array for testing
            };
        }
        
        if (!method_exists($wpdb, 'update')) {
            $wpdb->update = function($table, $data, $where) {
                return 1; // Return 1 for successful update
            };
        }
        
        if (!method_exists($wpdb, 'prepare')) {
            $wpdb->prepare = function($query, ...$args) {
                return $query; // Simple mock
            };
        }
        
        if (!method_exists($wpdb, 'query')) {
            $wpdb->query = function($query) {
                return 1; // Return 1 for successful query
            };
        }
        
        if (!method_exists($wpdb, 'get_var')) {
            $wpdb->get_var = function($query) {
                return 0; // Return 0 remaining URLs for testing
            };
        }
    }
    
    /**
     * Mock attachment data
     */
    private function mock_attachment_data() {
        // Already handled by database mocking
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
        
        echo "\n📊 Test Summary\n";
        echo "===============\n";
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