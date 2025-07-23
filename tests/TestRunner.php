<?php
/**
 * Simple Test Runner for DB File Sync Plugin
 * Runs all test files and reports results
 */

// Define WordPress REST classes globally if they don't exist
if (!class_exists('WP_REST_Request')) {
    class WP_REST_Request {
        private $params = [];
        public function get_param($key) {
            return $this->params[$key] ?? null;
        }
        public function set_param($key, $value) {
            $this->params[$key] = $value;
        }
    }
}

if (!class_exists('WP_REST_Response')) {
    class WP_REST_Response {
        public $data;
        public $status;
        public function __construct($data = null, $status = 200) {
            $this->data = $data;
            $this->status = $status;
        }
    }
}

class DBFSTestRunner {
    
    private $tests_passed = 0;
    private $tests_failed = 0;
    private $test_results = [];
    
    public function __construct() {
        // Mock WordPress functions for testing
        $this->setup_wordpress_mocks();
        
        // Define plugin constants if not defined
        if (!defined('DBSYNC_SECRET')) {
            define('DBSYNC_SECRET', 'test-secret-token');
        }
        if (!defined('DBFS_PLUGIN_DIR')) {
            define('DBFS_PLUGIN_DIR', dirname(__DIR__));
        }
        if (!defined('ABSPATH')) {
            define('ABSPATH', '/tmp/wp-test/');
        }
        if (!defined('WP_CONTENT_DIR')) {
            define('WP_CONTENT_DIR', ABSPATH . 'wp-content');
        }
        if (!defined('DIRECTORY_SEPARATOR')) {
            define('DIRECTORY_SEPARATOR', '/');
        }
    }
    
    /**
     * Mock WordPress functions for testing
     */
    private function setup_wordpress_mocks() {
        if (!function_exists('wp_remote_get')) {
            function wp_remote_get($url, $args = []) {
                // Mock successful response
                return [
                    'response' => ['code' => 200],
                    'body' => json_encode(['test' => 'data'])
                ];
            }
        }
        
        if (!function_exists('wp_remote_retrieve_response_code')) {
            function wp_remote_retrieve_response_code($response) {
                return $response['response']['code'] ?? 200;
            }
        }
        
        if (!function_exists('wp_remote_retrieve_body')) {
            function wp_remote_retrieve_body($response) {
                return $response['body'] ?? '';
            }
        }
        
        if (!function_exists('is_wp_error')) {
            function is_wp_error($thing) {
                return false; // Mock no errors
            }
        }
        
        if (!function_exists('apply_filters')) {
            function apply_filters($hook, $value, ...$args) {
                return $value;
            }
        }
        
        if (!function_exists('wp_upload_dir')) {
            function wp_upload_dir() {
                return [
                    'basedir' => '/tmp/wp-test/wp-content/uploads',
                    'baseurl' => 'http://test.local/wp-content/uploads'
                ];
            }
        }
        
        if (!function_exists('wp_mkdir_p')) {
            function wp_mkdir_p($target) {
                return mkdir($target, 0755, true);
            }
        }
        
        if (!function_exists('get_transient')) {
            function get_transient($transient) {
                return false; // Mock no stored transients
            }
        }
        
        if (!function_exists('set_transient')) {
            function set_transient($transient, $value, $expiration) {
                return true;
            }
        }
        
        if (!function_exists('get_option')) {
            function get_option($option, $default = false) {
                // Return mock logs for the sync logs option
                if ($option === 'dbfs_sync_logs') {
                    return [
                        [
                            'timestamp' => '2025-07-22 12:00:00',
                            'type' => 'test',
                            'message' => 'Test sync operation',
                            'data' => ['test_data' => 'value']
                        ]
                    ];
                }
                return $default;
            }
        }
        
        if (!function_exists('update_option')) {
            function update_option($option, $value) {
                return true;
            }
        }
        
        if (!function_exists('current_time')) {
            function current_time($type) {
                return date('Y-m-d H:i:s');
            }
        }
        
        if (!function_exists('add_action')) {
            function add_action($hook, $function, $priority = 10, $accepted_args = 1) {
                return true;
            }
        }
        
        if (!function_exists('register_rest_route')) {
            function register_rest_route($namespace, $route, $args) {
                return true;
            }
        }
        
        if (!function_exists('sanitize_text_field')) {
            function sanitize_text_field($str) {
                return htmlspecialchars(trim($str), ENT_QUOTES, 'UTF-8');
            }
        }
        
        if (!function_exists('sanitize_url')) {
            function sanitize_url($url) {
                return filter_var($url, FILTER_SANITIZE_URL);
            }
        }
        
        if (!function_exists('current_user_can')) {
            function current_user_can($capability) {
                return true; // Mock admin user
            }
        }
        
        if (!function_exists('wp_schedule_single_event')) {
            function wp_schedule_single_event($timestamp, $hook, $args = []) {
                return true;
            }
        }
        
        if (!function_exists('esc_html')) {
            function esc_html($text) {
                return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            }
        }
        
        if (!function_exists('esc_attr')) {
            function esc_attr($text) {
                return htmlspecialchars($text, ENT_QUOTES, 'UTF-8');
            }
        }
        
        if (!function_exists('wp_create_nonce')) {
            function wp_create_nonce($action) {
                return 'mock-nonce-' . md5($action);
            }
        }
        
        if (!function_exists('__return_true')) {
            function __return_true() {
                return true;
            }
        }
        
        // WordPress REST classes will be defined globally if needed
    }
    
    /**
     * Run a test and record results
     */
    public function assert($condition, $message, $test_name = '') {
        if ($condition) {
            $this->tests_passed++;
            $this->test_results[] = "✅ PASS: $message" . ($test_name ? " [$test_name]" : "");
            return true;
        } else {
            $this->tests_failed++;
            $this->test_results[] = "❌ FAIL: $message" . ($test_name ? " [$test_name]" : "");
            return false;
        }
    }
    
    /**
     * Assert two values are equal
     */
    public function assertEqual($expected, $actual, $message, $test_name = '') {
        $condition = $expected === $actual;
        if (!$condition) {
            $message .= " (Expected: " . var_export($expected, true) . ", Got: " . var_export($actual, true) . ")";
        }
        return $this->assert($condition, $message, $test_name);
    }
    
    /**
     * Assert value is not null/false
     */
    public function assertNotEmpty($value, $message, $test_name = '') {
        return $this->assert(!empty($value), $message, $test_name);
    }
    
    /**
     * Assert exception is thrown
     */
    public function assertException($callable, $message, $test_name = '') {
        try {
            $callable();
            return $this->assert(false, $message . " (No exception thrown)", $test_name);
        } catch (Exception $e) {
            return $this->assert(true, $message, $test_name);
        }
    }
    
    /**
     * Load and run all test files
     */
    public function runAllTests() {
        echo "🧪 Starting DB File Sync Plugin Tests\n";
        echo "=====================================\n\n";
        
        // Setup test environment
        $this->setupTestEnvironment();
        
        // Load plugin files
        $this->loadPluginFiles();
        
        // Run individual test files
        $test_files = [
            'UtilsTest.php',
            'AuthTest.php',
            'FileSyncTest.php',
            'AjaxEndpointsTest.php',
            'IntegrationTest.php'
        ];
        
        foreach ($test_files as $test_file) {
            $test_path = __DIR__ . '/' . $test_file;
            if (file_exists($test_path)) {
                echo "Running $test_file...\n";
                include $test_path;
                echo "\n";
            }
        }
        
        $this->printResults();
    }
    
    /**
     * Setup test environment
     */
    private function setupTestEnvironment() {
        // Create test directories
        $test_dirs = [
            '/tmp/wp-test',
            '/tmp/wp-test/wp-content',
            '/tmp/wp-test/wp-content/uploads',
            '/tmp/wp-test/wp-content/uploads/test'
        ];
        
        foreach ($test_dirs as $dir) {
            if (!is_dir($dir)) {
                mkdir($dir, 0755, true);
            }
        }
        
        // Create test files
        file_put_contents('/tmp/wp-test/wp-content/uploads/test-file.txt', 'Test content');
        file_put_contents('/tmp/wp-test/wp-content/uploads/test/nested-file.jpg', 'Fake image content');
    }
    
    /**
     * Load plugin files for testing
     */
    private function loadPluginFiles() {
        $plugin_files = [
            '/includes/class-utils.php',
            '/includes/class-auth.php',
            '/file-sync/class-file-sync.php',
            '/file-sync/file-endpoints.php'
        ];
        
        foreach ($plugin_files as $file) {
            $file_path = DBFS_PLUGIN_DIR . $file;
            if (file_exists($file_path)) {
                require_once $file_path;
            }
        }
    }
    
    /**
     * Print final test results
     */
    public function printResults() {
        echo "\n🏁 Test Results Summary\n";
        echo "======================\n";
        
        foreach ($this->test_results as $result) {
            echo $result . "\n";
        }
        
        echo "\n📊 Final Score:\n";
        echo "✅ Passed: {$this->tests_passed}\n";
        echo "❌ Failed: {$this->tests_failed}\n";
        echo "📈 Total: " . ($this->tests_passed + $this->tests_failed) . "\n";
        
        $success_rate = $this->tests_passed + $this->tests_failed > 0 
            ? round(($this->tests_passed / ($this->tests_passed + $this->tests_failed)) * 100, 1)
            : 0;
            
        echo "🎯 Success Rate: {$success_rate}%\n";
        
        if ($this->tests_failed > 0) {
            echo "\n⚠️  Some tests failed. Check the output above for details.\n";
            exit(1);
        } else {
            echo "\n🎉 All tests passed!\n";
            exit(0);
        }
    }
}

// Global test runner instance
$GLOBALS['test_runner'] = new DBFSTestRunner();

// Helper functions for tests
function test_assert($condition, $message, $test_name = '') {
    return $GLOBALS['test_runner']->assert($condition, $message, $test_name);
}

function test_assertEqual($expected, $actual, $message, $test_name = '') {
    return $GLOBALS['test_runner']->assertEqual($expected, $actual, $message, $test_name);
}

function test_assertNotEmpty($value, $message, $test_name = '') {
    return $GLOBALS['test_runner']->assertNotEmpty($value, $message, $test_name);
}

function test_assertException($callable, $message, $test_name = '') {
    return $GLOBALS['test_runner']->assertException($callable, $message, $test_name);
} 