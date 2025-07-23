<?php
/**
 * Admin Page for DB & File Sync - Clean UI
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Main admin page function
 */
function dbfs_admin_page() {
    if (!current_user_can('manage_options')) return;
    
    // Handle settings save
    $settings_saved = false;
    if (isset($_POST['action']) && $_POST['action'] === 'save_settings' && wp_verify_nonce($_POST['dbfs_nonce'], 'dbfs_save_settings')) {
        $source_url = isset($_POST['source_url']) ? esc_url_raw($_POST['source_url']) : '';
        $include_options = isset($_POST['include_options_sync']) ? 1 : 0;
        
        if ($source_url && filter_var($source_url, FILTER_VALIDATE_URL)) {
            update_option('dbfs_source_url', $source_url);
            update_option('dbfs_include_options', $include_options);
            $settings_saved = true;
        } else if (empty($source_url)) {
            // Allow saving empty URL to clear settings
            update_option('dbfs_source_url', '');
            update_option('dbfs_include_options', $include_options);
            $settings_saved = true;
        }
    }
    
    // Get the source URL from saved settings or use empty default
    $source_url = get_option('dbfs_source_url', '');
    $include_options_saved = get_option('dbfs_include_options', 0);
    
    // Override with form submission if present
    if (isset($_POST['source_url']) && $_POST['action'] !== 'save_settings') {
        $source_url = esc_url_raw($_POST['source_url']);
    }
    
    // Validate URL format
    $url_error = '';
    if (isset($_POST['source_url']) && $source_url && !filter_var($source_url, FILTER_VALIDATE_URL)) {
        $url_error = 'Invalid URL format';
        $source_url = '';
    }

    ?>
    <div class="wrap">
        <h1><span class="dashicons dashicons-update"></span> DB & Media Sync</h1>
        
        <style>
        .dbfs-container { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-top: 20px; }
        .dbfs-card { background: #fff; border: 1px solid #ccd0d4; box-shadow: 0 1px 1px rgba(0,0,0,.04); padding: 20px; }
        .dbfs-card h3 { margin-top: 0; color: #23282d; border-bottom: 1px solid #eee; padding-bottom: 10px; }
        .dbfs-form-group { margin-bottom: 20px; }
        .dbfs-form-group label { display: block; font-weight: 600; margin-bottom: 5px; }
        .dbfs-form-group input[type="url"] { width: 100%; padding: 8px 12px; border: 1px solid #ddd; border-radius: 4px; }
        .dbfs-form-group .description { font-size: 13px; color: #666; margin-top: 5px; }

        .dbfs-actions { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; margin-top: 20px; }
        .dbfs-actions .button-primary { grid-column: 1 / -1; }
        .dbfs-status-card { background: #f8f9fa; border-left: 4px solid #0073aa; }
        .dbfs-error { background: #ffeaea; border-left: 4px solid #dc3232; color: #dc3232; }
        .dbfs-success { background: #eafaf1; border-left: 4px solid #46b450; color: #46b450; }
        .dbfs-warning { background: #fff8e1; border-left: 4px solid #ffb900; color: #bf8500; }
        .dbfs-stats { display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin: 15px 0; }
        .dbfs-stat { text-align: center; padding: 10px; background: #f9f9f9; border-radius: 4px; }
        .dbfs-stat-number { font-size: 24px; font-weight: bold; color: #0073aa; }
        .dbfs-stat-label { font-size: 12px; color: #666; text-transform: uppercase; }
        .dbfs-progress-container { background: #f1f1f1; border-radius: 10px; margin: 15px 0; overflow: hidden; }
        .dbfs-progress-bar { height: 30px; background: linear-gradient(45deg, #0073aa, #005a87); color: white; text-align: center; line-height: 30px; font-weight: bold; transition: width 0.3s ease; width: 0%; }
        .dbfs-logs { max-height: 300px; overflow-y: auto; background: #f9f9f9; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 12px; }
        .dbfs-log-entry { padding: 5px 0; border-bottom: 1px solid #eee; }
        .dbfs-log-time { color: #666; }
        .dbfs-log-type { font-weight: bold; }
        .dbfs-hidden { display: none; }
        .dbfs-save-section { border-top: 1px solid #eee; padding-top: 20px; margin-top: 20px; }
        .dbfs-save-section .button { margin-right: 10px; }
        </style>

        <?php if ($url_error): ?>
        <div class="notice notice-error">
            <p><strong>Error:</strong> <?php echo esc_html($url_error); ?></p>
        </div>
        <?php endif; ?>

        <?php if ($settings_saved): ?>
        <div class="notice notice-success is-dismissible">
            <p><strong>Settings saved successfully!</strong></p>
        </div>
        <?php endif; ?>

        <div class="dbfs-container">
            <!-- Main Configuration Panel -->
            <div class="dbfs-card">
                <h3>🚀 Sync Configuration</h3>
                
                <form method="post" action="">
                    <?php wp_nonce_field('dbfs_save_settings', 'dbfs_nonce'); ?>
                    <input type="hidden" name="action" value="save_settings">
                    
                    <div class="dbfs-form-group">
                        <label for="source_url">Source Site URL *</label>
                        <input type="url" id="source_url" name="source_url" value="<?php echo esc_attr($source_url); ?>" placeholder="https://example.com" required>
                        <div class="description">URL of the WordPress site to sync from (required to start sync)</div>
                    </div>

                    <div class="dbfs-form-group">
                        <label style="display: flex; align-items: center; gap: 8px; font-weight: normal;">
                            <input type="checkbox" id="include_options_sync" name="include_options_sync" <?php checked($include_options_saved, 1); ?>> 
                            <span>Include wp_options sync</span>
                        </label>
                        <div class="description" style="color: #856404; background: #fff3cd; padding: 8px 12px; border-radius: 4px; border-left: 3px solid #ffc107;">💡 <strong>Advanced option:</strong> Includes all WordPress settings, theme options, and plugin configurations. Recommended for full site migrations.</div>
                    </div>

                    <div class="dbfs-form-group dbfs-save-section">
                        <button type="submit" class="button button-primary" style="background: #0073aa; color: white; border-color: #0073aa;">Save Settings</button>
                        <span class="description">Save your configuration for future use</span>
                    </div>
                </form>



                <div class="dbfs-actions">
                    <button id="dbfs-btn-full-sync" class="button button-primary button-hero dbfs-sync-btn" data-sync-type="full" <?php echo empty($source_url) ? 'disabled' : ''; ?>>
                        🎯 Complete Sync
                    </button>
                    <button id="dbfs-btn-db-sync" class="button button-secondary dbfs-sync-btn" data-sync-type="db" <?php echo empty($source_url) ? 'disabled' : ''; ?>>
                        📊 Database Only
                    </button>
                    <button id="dbfs-btn-file-sync" class="button button-secondary dbfs-sync-btn" data-sync-type="file" <?php echo empty($source_url) ? 'disabled' : ''; ?>>
                        📁 Files Only
                    </button>
                </div>

                <!-- Progress Section -->
                <div id="dbfs-progress-section" class="dbfs-hidden" style="margin-top: 30px; padding-top: 20px; border-top: 1px solid #eee;">
                    <h4>Sync Progress</h4>
                    <div id="dbfs-status-message" class="dbfs-status-card" style="padding: 10px; margin: 10px 0; border-radius: 4px;">
                        Initializing...
                    </div>
                    <div class="dbfs-progress-container">
                        <div id="dbfs-progress-bar" class="dbfs-progress-bar">0%</div>
                    </div>
                    <div style="font-size: 12px; color: #666; margin-top: 10px;">
                        <span id="dbfs-files-completed">0</span> of <span id="dbfs-files-total">0</span> files • 
                        <span id="dbfs-files-errors">0</span> errors • 
                        Current: <span id="dbfs-current-file">-</span>
                    </div>
                    <button id="dbfs-cancel-sync" type="button" class="button dbfs-hidden" style="margin-top: 10px;">Cancel</button>
                </div>
            </div>

            <!-- Status & Info Panel -->
            <div>
                <!-- Connection Status -->
                <div class="dbfs-card">
                    <h3>📊 Status</h3>
                    <?php
                    if (!empty($source_url)) {
                        $db_sync = new DBFS_DB_Sync($source_url, DBSYNC_CHUNK_SIZE, DBSYNC_MAX_EXECUTION_TIME);
                        $tables = $db_sync->get_tables_to_sync();
                        $directories = DBFS_Utils::get_sync_directories();
                        ?>
                        
                        <div class="dbfs-stats">
                            <div class="dbfs-stat">
                                <div class="dbfs-stat-number"><?php echo count($tables); ?></div>
                                <div class="dbfs-stat-label">DB Tables</div>
                            </div>
                            <div class="dbfs-stat">
                                <div class="dbfs-stat-number"><?php echo count($directories); ?></div>
                                <div class="dbfs-stat-label">Directories</div>
                            </div>
                        </div>

                        <div style="margin-top: 15px;">
                            <p><strong>Source:</strong> <?php echo esc_html($source_url); ?></p>
                            <p><strong>Status:</strong> 
                                <?php if (!empty($tables)): ?>
                                    <span style="color: #46b450;">✅ Connected</span>
                                <?php else: ?>
                                    <span style="color: #dc3232;">❌ Cannot connect</span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <?php
                    } else {
                        ?>
                        <div style="text-align: center; padding: 20px; color: #666;">
                            <p><span style="font-size: 24px;">⏳</span></p>
                            <p><strong>No source URL configured</strong></p>
                            <p>Enter a source site URL above to get started</p>
                        </div>
                        <?php
                    }
                    ?>
                </div>

                <!-- Configuration Info -->
                <div class="dbfs-card">
                    <h3>⚙️ Settings</h3>
                    <table style="width: 100%; font-size: 13px;">
                        <tr><td><strong>Chunk Size:</strong></td><td><?php echo DBSYNC_CHUNK_SIZE; ?> rows</td></tr>
                        <tr><td><strong>Max Time:</strong></td><td><?php echo DBSYNC_MAX_EXECUTION_TIME; ?>s</td></tr>
                        <tr><td><strong>Options Sync:</strong></td><td><span id="options-sync-status">User Choice</span></td></tr>
                    </table>
                </div>


            </div>
        </div>

        <!-- Recent Activity -->
        <div class="dbfs-card" style="margin-top: 20px;">
            <h3>📋 Recent Activity</h3>
            <div class="dbfs-logs">
                <?php
                $logs = DBFS_Utils::get_sync_logs(10);
                if (!empty($logs)) {
                    foreach ($logs as $log) {
                        $type_icon = $log['type'] === 'db' ? '📊' : ($log['type'] === 'file' ? '📁' : '🔧');
                        echo '<div class="dbfs-log-entry">';
                        echo '<span class="dbfs-log-time">' . esc_html($log['timestamp']) . '</span> ';
                        echo '<span class="dbfs-log-type">' . $type_icon . ' ' . esc_html(ucfirst($log['type'])) . '</span>: ';
                        echo esc_html($log['message']);
                        echo '</div>';
                    }
                } else {
                    echo '<div class="dbfs-log-entry">No recent activity</div>';
                }
                ?>
            </div>
        </div>
    </div>

    <script>
    let dbfsSyncPolling = null;
    let dbfsSyncActive = false;
    
    function validateUrl(url) {
        if (!url || !/^https?:\/\/.+/i.test(url)) {
            return false;
        }
        return true;
    }
    
    function updateSyncButtons() {
        const sourceUrl = document.getElementById("source_url").value.trim();
        const isValid = validateUrl(sourceUrl);
        const buttons = document.querySelectorAll(".dbfs-sync-btn");
        
        buttons.forEach(btn => {
            btn.disabled = !isValid || dbfsSyncActive;
        });
        
        // Update options sync status display
        updateOptionsStatus();
    }
    
    function updateOptionsStatus() {
        const optionsCheckbox = document.getElementById("include_options_sync");
        const statusElement = document.getElementById("options-sync-status");
        
        if (optionsCheckbox && statusElement) {
            if (optionsCheckbox.checked) {
                statusElement.innerHTML = '<span style="color: #d63384;">⚠️ Enabled</span>';
            } else {
                statusElement.innerHTML = '<span style="color: #46b450;">✅ Disabled</span>';
            }
        }
    }
    
    function startSync(syncType) {
        if (dbfsSyncActive) {
            alert("A sync is already in progress.");
            return;
        }
        
        let sourceUrl = document.getElementById("source_url").value.trim().replace(/\/$/, '');
        
        if (!validateUrl(sourceUrl)) {
            alert("Please enter a valid source site URL starting with http:// or https://");
            return;
        }
        
        if ((syncType === "full" || syncType === "db") && !confirm("⚠️ This will overwrite your database/files. Continue?")) {
            return;
        }
        
        // Show progress and disable buttons
        document.getElementById("dbfs-progress-section").style.display = "block";
        document.querySelectorAll(".dbfs-sync-btn").forEach(btn => btn.disabled = true);
        document.getElementById("dbfs-cancel-sync").style.display = "inline-block";
        dbfsSyncActive = true;
        
        // Get options sync setting
        const includeOptions = document.getElementById("include_options_sync").checked;
        
        // Start sync
        fetch("<?php echo rest_url('filesync/v1/start-sync'); ?>", {
            method: "POST",
            headers: {
                "Content-Type": "application/json",
                "X-WP-Nonce": "<?php echo wp_create_nonce('wp_rest'); ?>"
            },
            body: JSON.stringify({
                source_url: sourceUrl,
                sync_type: syncType,
                include_options: includeOptions
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                updateProgress("🚀 Sync started successfully!", 0);
                startPolling();
            } else {
                updateProgress("❌ Failed to start: " + (data.error || "Unknown error"), 0);
                endSync();
            }
        })
        .catch(error => {
            updateProgress("❌ Network error: " + error.message, 0);
            endSync();
        });
    }
    
    function startPolling() {
        dbfsSyncPolling = setInterval(() => {
            fetch("<?php echo rest_url('filesync/v1/sync-progress'); ?>", {
                headers: { "X-WP-Nonce": "<?php echo wp_create_nonce('wp_rest'); ?>" }
            })
            .then(response => response.json())
            .then(data => {
                if (data.status === "no_sync" || data.status === "not_found") {
                    endSync();
                    return;
                }
                
                updateProgress(data.message || "Syncing...", data.progress || 0);
                updateDetails(data);
                
                if (data.status === "completed" || data.status === "error" || data.status === "timeout") {
                    setTimeout(endSync, 2000);
                }
            })
            .catch(error => console.error("Polling error:", error));
        }, 1000);
    }
    
    function updateProgress(message, progress) {
        document.getElementById("dbfs-status-message").innerHTML = message;
        document.getElementById("dbfs-progress-bar").style.width = progress + "%";
        document.getElementById("dbfs-progress-bar").innerHTML = Math.round(progress) + "%";
    }
    
    function updateDetails(data) {
        if (data.files_total) document.getElementById("dbfs-files-total").textContent = data.files_total;
        if (data.files_completed !== undefined) document.getElementById("dbfs-files-completed").textContent = data.files_completed;
        if (data.files_errors !== undefined) document.getElementById("dbfs-files-errors").textContent = data.files_errors;
        if (data.current_file) document.getElementById("dbfs-current-file").textContent = data.current_file;
        else if (data.current_file === "") document.getElementById("dbfs-current-file").textContent = "-";
    }
    
    function endSync() {
        if (dbfsSyncPolling) {
            clearInterval(dbfsSyncPolling);
            dbfsSyncPolling = null;
        }
        dbfsSyncActive = false;
        updateSyncButtons(); // Re-enable buttons based on URL validity
        document.getElementById("dbfs-cancel-sync").style.display = "none";
        
        setTimeout(() => {
            if (!dbfsSyncActive) {
                document.getElementById("dbfs-progress-section").style.display = "none";
            }
        }, 5000);
    }
    
    // Event listeners
    document.addEventListener("DOMContentLoaded", function() {
        // Initial button state check and options status
        updateSyncButtons();
        updateOptionsStatus();
        
        // URL input validation on change
        document.getElementById("source_url").addEventListener("input", function() {
            updateSyncButtons();
        });
        
        // Options sync checkbox change
        document.getElementById("include_options_sync").addEventListener("change", function() {
            updateOptionsStatus();
        });
        
        document.querySelectorAll(".dbfs-sync-btn").forEach(btn => {
            btn.addEventListener("click", function(e) {
                e.preventDefault();
                startSync(this.getAttribute("data-sync-type"));
            });
        });
        
        document.getElementById("dbfs-cancel-sync").addEventListener("click", function() {
            if (confirm("Cancel the sync?")) {
                endSync();
                updateProgress("❌ Sync cancelled", 0);
            }
        });
        

        
        // Check for active sync on load
        fetch("<?php echo rest_url('filesync/v1/sync-status'); ?>", {
            headers: { "X-WP-Nonce": "<?php echo wp_create_nonce('wp_rest'); ?>" }
        })
        .then(response => response.json())
        .then(data => {
            if (data.has_active_sync && data.status !== "completed") {
                document.getElementById("dbfs-progress-section").style.display = "block";
                dbfsSyncActive = true;
                startPolling();
            }
        })
        .catch(error => console.error("Status check error:", error));
    });
    </script>
    <?php
} 