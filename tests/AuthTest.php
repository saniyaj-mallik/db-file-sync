<?php
/**
 * Auth Class Tests
 */

echo "🔐 Testing DBFS_Auth Class\n";

// Test token verification with correct token
$valid_token = DBSYNC_SECRET;
$is_valid = DBFS_Auth::verify_token($valid_token);
test_assert($is_valid, 'Valid token should be accepted');

// Test token verification with incorrect token
$invalid_token = 'wrong-token';
$is_invalid = DBFS_Auth::verify_token($invalid_token);
test_assert(!$is_invalid, 'Invalid token should be rejected');

// Test token verification with empty token
$empty_token = '';
$is_empty = DBFS_Auth::verify_token($empty_token);
test_assert(!$is_empty, 'Empty token should be rejected');

// Test auth headers generation
$headers = DBFS_Auth::get_auth_headers();
test_assertNotEmpty($headers, 'Auth headers should be generated');
test_assert(isset($headers['X-DBFS-Token']), 'Token header should be set');
test_assert(isset($headers['Content-Type']), 'Content-Type header should be set');
test_assertEqual(DBSYNC_SECRET, $headers['X-DBFS-Token'], 'Token header should contain secret');
test_assertEqual('application/json', $headers['Content-Type'], 'Content-Type should be JSON');

// Test file path validation - safe paths
$safe_paths = [
    WP_CONTENT_DIR . '/uploads/image.jpg',
    ABSPATH . 'wp-content/themes/theme/style.css'
];

foreach ($safe_paths as $path) {
    // Create the path for testing
    $dir = dirname($path);
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }
    touch($path);
    
    $is_safe = DBFS_Auth::validate_file_path($path);
    test_assert($is_safe, 'Safe file path should be validated: ' . $path);
}

// Test file path validation - unsafe paths (directory traversal)
$unsafe_paths = [
    '../../../etc/passwd',
    WP_CONTENT_DIR . '/../../../etc/passwd',
    '/etc/passwd'
];

foreach ($unsafe_paths as $path) {
    $is_unsafe = DBFS_Auth::validate_file_path($path);
    test_assert(!$is_unsafe, 'Unsafe file path should be rejected: ' . $path);
}

// Test file type validation - allowed types
$allowed_files = [
    'image.jpg',
    'photo.png',
    'document.pdf',
    'style.css',
    'archive.zip',
    'video.mp4',
    'audio.mp3',
    'data.json',
    'markup.html'
];

foreach ($allowed_files as $filename) {
    $is_allowed = DBFS_Auth::is_file_type_allowed($filename);
    test_assert($is_allowed, 'Allowed file type should pass: ' . $filename);
}

// Test file type validation - blocked types
$blocked_files = [
    'malware.exe',
    'script.bat',
    'command.cmd',
    'binary.com',
    'virus.scr',
    'trojan.pif',
    'malicious.vbs',
    'script.js' // JavaScript is blocked for security
];

foreach ($blocked_files as $filename) {
    $is_blocked = DBFS_Auth::is_file_type_allowed($filename);
    test_assert(!$is_blocked, 'Blocked file type should be rejected: ' . $filename);
}

// Test file path sanitization
$dirty_paths = [
    'file name with spaces.txt',
    'file-with-special-chars@#$.jpg',
    'normal_file.png'
];

foreach ($dirty_paths as $dirty_path) {
    $clean_path = DBFS_Auth::sanitize_file_path($dirty_path);
    test_assertNotEmpty($clean_path, 'Sanitized path should not be empty');
    test_assert(!preg_match('/[^a-zA-Z0-9\-_\.\/\\\\]/', $clean_path), 'Sanitized path should only contain safe characters: ' . $clean_path);
}

// Test edge cases (convert null to string to avoid hash_equals error)
test_assert(!DBFS_Auth::verify_token(''), 'Empty string token should be rejected');
test_assert(!DBFS_Auth::is_file_type_allowed(''), 'Empty filename should be rejected');
test_assert(!DBFS_Auth::is_file_type_allowed('no-extension'), 'File without extension should be rejected');

// Test case insensitive file extensions
test_assert(DBFS_Auth::is_file_type_allowed('IMAGE.JPG'), 'Uppercase extension should be allowed');
test_assert(DBFS_Auth::is_file_type_allowed('Image.Png'), 'Mixed case extension should be allowed');

echo "✅ Auth tests completed\n"; 