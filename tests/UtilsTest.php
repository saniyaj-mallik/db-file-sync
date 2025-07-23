<?php
/**
 * Utils Class Tests
 */

echo "🔧 Testing DBFS_Utils Class\n";

// Test file size formatting
test_assertEqual('1.50 KB', DBFS_Utils::format_file_size(1536), 'File size formatting for KB');
test_assertEqual('2.50 MB', DBFS_Utils::format_file_size(2621440), 'File size formatting for MB');
test_assertEqual('1.50 GB', DBFS_Utils::format_file_size(1610612736), 'File size formatting for GB');
test_assertEqual('512 bytes', DBFS_Utils::format_file_size(512), 'File size formatting for bytes');

// Test file hash calculation
$test_file = '/tmp/wp-test/wp-content/uploads/test-file.txt';
$hash = DBFS_Utils::get_file_hash($test_file);
test_assertNotEmpty($hash, 'File hash should be generated');
test_assertEqual(32, strlen($hash), 'Hash should be 32 characters (MD5)');

// Test non-existent file hash
$no_hash = DBFS_Utils::get_file_hash('/nonexistent/file.txt');
test_assertEqual(false, $no_hash, 'Non-existent file should return false');

// Test directory creation
$test_dir = '/tmp/wp-test/new-directory';
$created = DBFS_Utils::ensure_directory_exists($test_dir);
test_assert($created, 'Directory creation should succeed');
test_assert(is_dir($test_dir), 'Directory should exist after creation');

// Test sync directories configuration
$sync_dirs = DBFS_Utils::get_sync_directories();
test_assertNotEmpty($sync_dirs, 'Sync directories should be configured');
test_assert(isset($sync_dirs['uploads']), 'Uploads directory should be configured');
test_assert(isset($sync_dirs['uploads']['path']), 'Uploads path should be set');
test_assert(isset($sync_dirs['uploads']['name']), 'Uploads name should be set');

// Test directory scanning
$upload_dir = wp_upload_dir();
$files = DBFS_Utils::scan_directory($upload_dir['basedir'], $upload_dir['basedir']);
test_assertNotEmpty($files, 'Directory scan should find files');

// Verify scanned file structure
if (!empty($files)) {
    $first_file = $files[0];
    test_assert(isset($first_file['path']), 'Scanned file should have path');
    test_assert(isset($first_file['relative_path']), 'Scanned file should have relative path');
    test_assert(isset($first_file['size']), 'Scanned file should have size');
    test_assert(isset($first_file['hash']), 'Scanned file should have hash');
    test_assert(isset($first_file['modified']), 'Scanned file should have modified time');
}

// Test file comparison
$source_files = [
    ['relative_path' => 'test1.txt', 'hash' => 'abc123', 'size' => 100],
    ['relative_path' => 'test2.txt', 'hash' => 'def456', 'size' => 200],
    ['relative_path' => 'test3.txt', 'hash' => 'ghi789', 'size' => 300]
];

$local_files = [
    ['relative_path' => 'test1.txt', 'hash' => 'abc123', 'size' => 100], // Same
    ['relative_path' => 'test2.txt', 'hash' => 'different', 'size' => 200], // Modified
    ['relative_path' => 'old-file.txt', 'hash' => 'old123', 'size' => 50] // To delete
];

$comparison = DBFS_Utils::compare_file_lists($source_files, $local_files);
test_assertEqual(2, count($comparison['download']), 'Should find 2 files to download');
test_assertEqual(1, count($comparison['delete']), 'Should find 1 file to delete');
test_assertEqual(1, $comparison['unchanged'], 'Should find 1 unchanged file');

// Test file count functionality
$file_count = DBFS_Utils::get_directory_file_count($upload_dir['basedir'], 100);
test_assertNotEmpty($file_count, 'File count should return a value');

// Test sync operation logging
DBFS_Utils::log_sync_operation('test', 'Test sync operation', ['test_data' => 'value']);
$logs = DBFS_Utils::get_sync_logs(10);
test_assertNotEmpty($logs, 'Sync logs should be retrievable');

if (!empty($logs)) {
    $last_log = $logs[0];
    test_assertEqual('test', $last_log['type'], 'Log type should be recorded');
    test_assertEqual('Test sync operation', $last_log['message'], 'Log message should be recorded');
    test_assert(isset($last_log['timestamp']), 'Log should have timestamp');
    test_assert(isset($last_log['data']), 'Log should have data');
}

echo "✅ Utils tests completed\n"; 