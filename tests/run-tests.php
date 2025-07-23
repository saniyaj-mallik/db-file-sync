<?php
/**
 * Main Test Execution File
 * Run: php tests/run-tests.php
 */

// Set error reporting for testing
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Include the test runner
require_once __DIR__ . '/TestRunner.php';

// Start the test suite
echo "🚀 DB File Sync Plugin - Comprehensive Test Suite\n";
echo "================================================\n";
echo "Testing Date: " . date('Y-m-d H:i:s') . "\n";
echo "PHP Version: " . PHP_VERSION . "\n";
echo "Memory Limit: " . ini_get('memory_limit') . "\n\n";

try {
    // Run all tests
    $GLOBALS['test_runner']->runAllTests();
} catch (Exception $e) {
    echo "❌ Fatal error during test execution:\n";
    echo $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
} catch (Error $e) {
    echo "❌ Fatal error during test execution:\n";
    echo $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
    exit(1);
} 