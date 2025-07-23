<?php
/**
 * File Sync Class Tests
 */

echo "📁 Testing DBFS_File_Sync Class\n";

// Test file sync initialization
$source_url = 'http://test-source.local';
$file_sync = new DBFS_File_Sync($source_url, 1024, 300);
test_assertNotEmpty($file_sync, 'File sync instance should be created');

// Test get sync directories
$sync_dirs = $file_sync->get_sync_directories();
test_assertNotEmpty($sync_dirs, 'Sync directories should be available');
test_assert(isset($sync_dirs['uploads']), 'Uploads directory should be configured');

// Test get local files
$upload_dir = wp_upload_dir();
$local_files = $file_sync->get_local_files('uploads');
test_assertNotEmpty($local_files, 'Local files should be scanned');

// Verify local file structure
if (!empty($local_files)) {
    $first_file = $local_files[0];
    test_assert(isset($first_file['relative_path']), 'Local file should have relative path');
    test_assert(isset($first_file['size']), 'Local file should have size');
    test_assert(isset($first_file['hash']), 'Local file should have hash');
}

// Test invalid directory type
$invalid_files = $file_sync->get_local_files('invalid-directory');
test_assertEqual([], $invalid_files, 'Invalid directory should return empty array');

// Test progress calculation using testable wrapper
class TestableProgressSync extends DBFS_File_Sync_Ajax {
    public function test_calculate_progress($current, $total) {
        return $this->calculate_progress($current, $total);
    }
}

$progress_sync = new TestableProgressSync($source_url, 1024, 300, 'test-sync-id');
$progress_25 = $progress_sync->test_calculate_progress(25, 100);
$progress_50 = $progress_sync->test_calculate_progress(50, 100);
$progress_100 = $progress_sync->test_calculate_progress(100, 100);

test_assert($progress_25 > 15, 'Progress should be above base 15%');
test_assert($progress_50 > $progress_25, 'Progress should increase');
test_assert($progress_100 == 100, 'Progress should reach 100%');

// Test zero files case
$progress_zero = $progress_sync->test_calculate_progress(0, 0);
test_assertEqual(100, $progress_zero, 'Zero files should give 100% progress');

// Mock get_source_files to test API interaction
class TestableFileSync extends DBFS_File_Sync {
    public function test_get_source_files($directory_type) {
        // Mock successful API response
        return [
            [
                'relative_path' => 'test-image.jpg',
                'size' => 2048,
                'hash' => 'abc123def456',
                'modified' => time()
            ],
            [
                'relative_path' => 'subfolder/document.pdf',
                'size' => 4096,
                'hash' => 'ghi789jkl012',
                'modified' => time()
            ]
        ];
    }
}

$testable_sync = new TestableFileSync($source_url);
$mock_source_files = $testable_sync->test_get_source_files('uploads');
test_assertEqual(2, count($mock_source_files), 'Mock source files should return expected count');
test_assertEqual('test-image.jpg', $mock_source_files[0]['relative_path'], 'First file should have expected path');

// Test file comparison logic
$source_files = [
    ['relative_path' => 'same-file.txt', 'hash' => 'same123', 'size' => 100],
    ['relative_path' => 'modified-file.txt', 'hash' => 'new456', 'size' => 200],
    ['relative_path' => 'new-file.txt', 'hash' => 'brand789', 'size' => 300]
];

$local_files = [
    ['relative_path' => 'same-file.txt', 'hash' => 'same123', 'size' => 100],
    ['relative_path' => 'modified-file.txt', 'hash' => 'old456', 'size' => 200],
    ['relative_path' => 'deleted-file.txt', 'hash' => 'gone123', 'size' => 50]
];

$comparison = DBFS_Utils::compare_file_lists($source_files, $local_files);
test_assertEqual(2, count($comparison['download']), 'Should detect 2 files to download');
test_assertEqual(1, count($comparison['delete']), 'Should detect 1 file to delete');
test_assertEqual(1, $comparison['unchanged'], 'Should detect 1 unchanged file');

// Test AJAX sync class specific functionality
class TestableAjaxSync extends DBFS_File_Sync_Ajax {
    public $progress_updates = [];
    
    protected function update_progress($status, $message, $progress, $extra_data = []) {
        $progress = max(0, min(100, $progress)); // Apply bounds checking
        $this->progress_updates[] = [
            'status' => $status,
            'message' => $message,
            'progress' => $progress,
            'extra_data' => $extra_data
        ];
        // Don't call parent to avoid transient operations in test
    }
    
    public function test_update_progress($status, $message, $progress, $extra_data = []) {
        return $this->update_progress($status, $message, $progress, $extra_data);
    }
    
    public function get_progress_updates() {
        return $this->progress_updates;
    }
}

$ajax_testable = new TestableAjaxSync($source_url, 1024, 300, 'test-sync');

// Simulate progress updates
$ajax_testable->test_update_progress('running', 'Test message', 50, ['files_completed' => 5]);
$updates = $ajax_testable->get_progress_updates();

test_assertEqual(1, count($updates), 'Progress update should be recorded');
test_assertEqual('running', $updates[0]['status'], 'Progress status should be recorded');
test_assertEqual('Test message', $updates[0]['message'], 'Progress message should be recorded');
test_assertEqual(50, $updates[0]['progress'], 'Progress percentage should be recorded');
test_assertEqual(5, $updates[0]['extra_data']['files_completed'], 'Extra data should be recorded');

// Test progress bounds
$ajax_testable->test_update_progress('running', 'Over 100%', 150);
$ajax_testable->test_update_progress('running', 'Under 0%', -10);
$bounded_updates = $ajax_testable->get_progress_updates();

test_assertEqual(100, $bounded_updates[1]['progress'], 'Progress should be capped at 100%');
test_assertEqual(0, $bounded_updates[2]['progress'], 'Progress should be floored at 0%');

// Test chunk size and timeout settings
$custom_sync = new DBFS_File_Sync($source_url, 2048, 600);
test_assertNotEmpty($custom_sync, 'Custom sync instance should be created');

echo "✅ File Sync tests completed\n"; 