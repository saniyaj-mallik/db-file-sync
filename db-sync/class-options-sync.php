<?php
/**
 * Options Sync with URL Replacement
 * Handles full wp_options sync with automatic URL replacement and settings protection
 */

if (!defined('ABSPATH')) {
    exit;
}

class DBFS_Options_Sync {
    
    private $source_url;
    private $destination_url;
    private $protected_settings = [];
    
    public function __construct($source_url, $destination_url) {
        $this->source_url = rtrim($source_url, '/');
        $this->destination_url = rtrim($destination_url, '/');
    }
    
    /**
     * Backup critical settings before options sync
     */
    public function backup_critical_settings() {
        $this->protected_settings = [
            // URL settings
            'siteurl' => get_option('siteurl'),
            'home' => get_option('home'),
            
            // Database settings (from wp-config, but stored in options sometimes)
            'db_version' => get_option('db_version'),
            
            // Upload settings (we want to preserve local paths)
            'upload_path' => get_option('upload_path'),
            'upload_url_path' => get_option('upload_url_path'),
            
            // Security settings
            'secret_key' => get_option('secret_key'),
            'auth_key' => get_option('auth_key'),
            'secure_auth_key' => get_option('secure_auth_key'),
            'logged_in_key' => get_option('logged_in_key'),
            'nonce_key' => get_option('nonce_key'),
            'auth_salt' => get_option('auth_salt'),
            'secure_auth_salt' => get_option('secure_auth_salt'),
            'logged_in_salt' => get_option('logged_in_salt'),
            'nonce_salt' => get_option('nonce_salt'),
            
            // Plugin-specific settings that shouldn't be synced
            'active_plugins' => get_option('active_plugins'),
            'template' => get_option('template'), // Active theme
            'stylesheet' => get_option('stylesheet'), // Active theme stylesheet
            
            // Cron and transient data
            'cron' => get_option('cron'),
        ];
        
        return $this->protected_settings;
    }
    
    /**
     * Restore critical settings after options sync
     */
    public function restore_critical_settings() {
        foreach ($this->protected_settings as $option_name => $option_value) {
            if ($option_value !== false || $option_name === 'upload_path') {
                update_option($option_name, $option_value, false);
            }
        }
        
        // Force correct site URLs
        update_option('siteurl', $this->destination_url, false);
        update_option('home', $this->destination_url, false);
        
        return true;
    }
    
    /**
     * Perform URL replacement in all relevant database tables
     */
    public function replace_urls_in_database() {
        global $wpdb;
        
        $tables_to_update = [
            $wpdb->posts => ['post_content', 'post_excerpt', 'guid'],
            $wpdb->postmeta => ['meta_value'],
            $wpdb->options => ['option_value'],
            $wpdb->comments => ['comment_content'],
            $wpdb->commentmeta => ['meta_value'],
        ];
        
        $updated_rows = 0;
        
        foreach ($tables_to_update as $table => $columns) {
            foreach ($columns as $column) {
                // Handle serialized data properly
                $rows = $wpdb->get_results("SELECT * FROM `$table` WHERE `$column` LIKE '%{$this->source_url}%'");
                
                foreach ($rows as $row) {
                    $old_value = $row->$column;
                    $new_value = $this->replace_serialized_urls($old_value);
                    
                    if ($old_value !== $new_value) {
                        $primary_key = $this->get_primary_key($table);
                        $wpdb->update(
                            $table,
                            [$column => $new_value],
                            [$primary_key => $row->$primary_key]
                        );
                        $updated_rows++;
                    }
                }
            }
        }
        
        return $updated_rows;
    }
    
    /**
     * Replace URLs in serialized data
     */
    private function replace_serialized_urls($data) {
        // First try simple string replacement
        $updated = str_replace($this->source_url, $this->destination_url, $data);
        
        // If data looks serialized, handle it properly
        if (is_serialized($data)) {
            $unserialized = @unserialize($data);
            if ($unserialized !== false) {
                $unserialized = $this->recursive_url_replace($unserialized);
                $updated = serialize($unserialized);
            }
        }
        
        return $updated;
    }
    
    /**
     * Recursively replace URLs in arrays and objects
     */
    private function recursive_url_replace($data) {
        if (is_string($data)) {
            return str_replace($this->source_url, $this->destination_url, $data);
        } elseif (is_array($data)) {
            foreach ($data as $key => $value) {
                $data[$key] = $this->recursive_url_replace($value);
            }
        } elseif (is_object($data)) {
            foreach ($data as $key => $value) {
                $data->$key = $this->recursive_url_replace($value);
            }
        }
        
        return $data;
    }
    
    /**
     * Get primary key column name for a table
     */
    private function get_primary_key($table) {
        global $wpdb;
        
        $primary_keys = [
            $wpdb->posts => 'ID',
            $wpdb->postmeta => 'meta_id',
            $wpdb->options => 'option_id',
            $wpdb->comments => 'comment_ID',
            $wpdb->commentmeta => 'meta_id',
            $wpdb->users => 'ID',
            $wpdb->usermeta => 'umeta_id',
        ];
        
        return isset($primary_keys[$table]) ? $primary_keys[$table] : 'id';
    }
    
    /**
     * Update attachment URLs in posts content
     */
    public function update_attachment_urls() {
        global $wpdb;
        
        // Update attachment GUIDs
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->posts} 
             SET guid = REPLACE(guid, %s, %s) 
             WHERE post_type = 'attachment'",
            $this->source_url,
            $this->destination_url
        ));
        
        // Update attachment URLs in post content
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->posts} 
             SET post_content = REPLACE(post_content, %s, %s) 
             WHERE post_content LIKE %s",
            $this->source_url,
            $this->destination_url,
            '%' . $this->source_url . '%'
        ));
        
        // Update attachment metadata
        $wpdb->query($wpdb->prepare(
            "UPDATE {$wpdb->postmeta} 
             SET meta_value = REPLACE(meta_value, %s, %s) 
             WHERE meta_key LIKE '%_url%' OR meta_key LIKE '%_file%'",
            $this->source_url,
            $this->destination_url
        ));
        
        return true;
    }
    
    /**
     * Clean up problematic options after sync
     */
    public function cleanup_problematic_options() {
        // Remove cron jobs from source site
        delete_option('cron');
        wp_clear_scheduled_hook('wp_version_check');
        wp_clear_scheduled_hook('wp_update_plugins');
        wp_clear_scheduled_hook('wp_update_themes');
        
        // Clear transients that might have source URLs
        $wpdb = $GLOBALS['wpdb'];
        $wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_%' OR option_name LIKE '_site_transient_%'");
        
        return true;
    }
    
    /**
     * Validate that URLs were replaced correctly
     */
    public function validate_url_replacement() {
        global $wpdb;
        
        $remaining_source_urls = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) FROM {$wpdb->options} WHERE option_value LIKE %s",
            '%' . $this->source_url . '%'
        ));
        
        return [
            'remaining_source_urls' => $remaining_source_urls,
            'siteurl_correct' => get_option('siteurl') === $this->destination_url,
            'home_correct' => get_option('home') === $this->destination_url,
        ];
    }
}

/**
 * Enhanced Database Sync with Options Support
 */
class DBFS_DB_Sync_With_Options extends DBFS_DB_Sync {
    
    private $sync_options;
    private $source_url;
    private $destination_url;
    
    public function __construct($source_url = '', $chunk_size = 50, $max_execution_time = 300, $sync_options = false) {
        parent::__construct($source_url, $chunk_size, $max_execution_time);
        $this->sync_options = $sync_options;
        $this->source_url = $source_url;
        $this->destination_url = home_url();
    }
    
    /**
     * Override get_tables_to_sync to conditionally include wp_options
     */
    public function get_tables_to_sync() {
        $tables = parent::get_tables_to_sync();
        
        if ($this->sync_options) {
            global $wpdb;
            // Add wp_options table if not already included
            if (!in_array($wpdb->prefix . 'options', $tables)) {
                $tables[] = $wpdb->prefix . 'options';
            }
        }
        
        return $tables;
    }
    
    /**
     * Enhanced sync with options handling
     */
    public function run_sync() {
        if (!$this->sync_options) {
            // Run normal sync without options
            return parent::run_sync();
        }
        
        echo '<div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; margin: 10px 0;">';
        echo '<h3>🔧 Full Site Sync with Options</h3>';
        echo '<p>This will sync all settings including wp_options. URLs will be automatically updated.</p>';
        echo '</div>';
        
        // Step 1: Backup critical settings
        echo '<p>🔒 Backing up critical settings...</p>';
        flush();
        
        $options_sync = new DBFS_Options_Sync($this->source_url, $this->destination_url);
        $options_sync->backup_critical_settings();
        
        // Step 2: Run normal database sync (now includes wp_options)
        echo '<p>📊 Running database sync with options...</p>';
        flush();
        
        parent::run_sync();
        
        // Step 3: Restore protected settings
        echo '<p>🔧 Restoring protected settings...</p>';
        flush();
        
        $options_sync->restore_critical_settings();
        
        // Step 4: Replace URLs throughout database
        echo '<p>🔄 Replacing URLs in database...</p>';
        flush();
        
        $updated_rows = $options_sync->replace_urls_in_database();
        echo "<p>✅ Updated $updated_rows database records with new URLs</p>";
        
        // Step 5: Update attachment URLs specifically
        echo '<p>🖼️ Updating media attachment URLs...</p>';
        flush();
        
        $options_sync->update_attachment_urls();
        
        // Step 6: Clean up problematic options
        echo '<p>🧹 Cleaning up problematic options...</p>';
        flush();
        
        $options_sync->cleanup_problematic_options();
        
        // Step 7: Validate results
        echo '<p>✅ Validating URL replacement...</p>';
        flush();
        
        $validation = $options_sync->validate_url_replacement();
        
        echo '<div style="background: #d4edda; border: 1px solid #c3e6cb; padding: 15px; margin: 10px 0;">';
        echo '<h3>🎉 Full Site Sync Complete!</h3>';
        echo "<p><strong>Site URL:</strong> {$this->destination_url}</p>";
        echo "<p><strong>Remaining source URLs:</strong> {$validation['remaining_source_urls']}</p>";
        echo "<p><strong>Site URL correct:</strong> " . ($validation['siteurl_correct'] ? '✅' : '❌') . "</p>";
        echo "<p><strong>Home URL correct:</strong> " . ($validation['home_correct'] ? '✅' : '❌') . "</p>";
        echo '<p><strong>Media Library:</strong> Files should now appear correctly!</p>';
        echo '</div>';
        
        return true;
    }
}

?> 