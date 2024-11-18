<?php
/*
Plugin Name: Advanced Directory Uploader
Description: Enables administrators to upload a ZIP file containing directories and files, with collision detection and overwrite options.
Version: 1.0
Author: Morgän Attias
License: GPL-3.0
*/

// Exit if accessed directly
if (!defined('ABSPATH')) {
    exit;
}

class AdvancedDirectoryUploader {

    private $max_file_size;
    private $target_directory;
    private $overwrite_by_default;

    public function __construct() {
        // Set default options or get them from the database
        $this->max_file_size = get_option('adu_max_file_size', 50) * 1024 * 1024; // Default is 50MB
        $this->target_directory = get_option('adu_target_directory', 'custom-directory');
        $this->overwrite_by_default = get_option('adu_overwrite_by_default', false);

        // Hook into WordPress
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_post_adu_upload_zip', array($this, 'handle_zip_upload'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
    }

    public function add_admin_menu() {
        // Add submenu under Media
        add_submenu_page(
            'upload.php',
            'Advanced Directory Uploader',
            'Advanced Directory Uploader',
            'manage_options',
            'advanced-directory-uploader',
            array($this, 'render_admin_page')
        );

        // Add settings page under Settings
        add_options_page(
            'Advanced Directory Uploader Settings',
            'ADU Settings',
            'manage_options',
            'adu-settings',
            array($this, 'render_settings_page')
        );
    }

    public function enqueue_scripts($hook) {
        // Only enqueue scripts on our plugin's admin page
        if ($hook !== 'media_page_advanced-directory-uploader') {
            return;
        }
        wp_enqueue_script('adu-script', plugin_dir_url(__FILE__) . 'js/adu-script.js', array('jquery'), '1.0', true);
    }

    public function render_admin_page() {
        // Check if user has the required capability
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized user');
        }

        // Include error/success messages
        if (isset($_GET['adu_error'])) {
            $this->display_error_message($_GET['adu_error']);
        } elseif (isset($_GET['adu_success'])) {
            $this->display_success_message($_GET['adu_success']);
        }

        // Get overwrite default setting
        $overwrite_checked = $this->overwrite_by_default ? 'checked' : '';
        ?>
        <div class="wrap">
            <h1>Advanced Directory Uploader</h1>
            <form id="adu-upload-form" method="post" enctype="multipart/form-data" action="<?php echo esc_url(admin_url('admin-post.php')); ?>">
                <?php wp_nonce_field('adu_upload_action', 'adu_upload_nonce'); ?>
                <input type="hidden" name="action" value="adu_upload_zip">
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="zip_file">Select ZIP File</label></th>
                        <td><input type="file" name="zip_file" id="zip_file" accept=".zip" required></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="allow_overwrite">Allow Overwrite</label></th>
                        <td>
                            <input type="checkbox" name="allow_overwrite" id="allow_overwrite" value="1" <?php echo $overwrite_checked; ?>>
                            <span class="description">Overwrite existing files if they already exist.</span>
                        </td>
                    </tr>
                </table>
                <?php submit_button('Upload and Extract'); ?>
            </form>
            <div id="adu-progress" style="display:none;">
                <h2>Processing...</h2>
                <div id="adu-progress-bar" style="width: 100%; background-color: #ccc;">
                    <div id="adu-progress-bar-fill" style="width: 0%; height: 30px; background-color: #4caf50;"></div>
                </div>
            </div>
        </div>
        <?php
    }

    public function handle_zip_upload() {
        // Check user capabilities and nonce
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized user');
        }
        check_admin_referer('adu_upload_action', 'adu_upload_nonce');

        // Check if a file was uploaded
        if (!isset($_FILES['zip_file']) || empty($_FILES['zip_file']['name'])) {
            wp_redirect(add_query_arg('adu_error', 'no_file', wp_get_referer()));
            exit;
        }

        $zip_file = $_FILES['zip_file'];

        // Validate file size
        if ($zip_file['size'] > $this->max_file_size) {
            wp_redirect(add_query_arg('adu_error', 'file_too_large', wp_get_referer()));
            exit;
        }

        // Validate file type
        $file_info = wp_check_filetype($zip_file['name']);
        if ($file_info['ext'] !== 'zip') {
            wp_redirect(add_query_arg('adu_error', 'invalid_file_type', wp_get_referer()));
            exit;
        }

        // Prepare upload
        $upload_dir = wp_upload_dir();
        $target_dir = trailingslashit($upload_dir['basedir']) . $this->target_directory . '/';
        if (!file_exists($target_dir)) {
            wp_mkdir_p($target_dir);
        }

        $tmp_name = $zip_file['tmp_name'];
        $zip_name = sanitize_file_name($zip_file['name']);
        $uploaded_zip = $target_dir . $zip_name;

        // Move uploaded file
        if (!move_uploaded_file($tmp_name, $uploaded_zip)) {
            wp_redirect(add_query_arg('adu_error', 'upload_error', wp_get_referer()));
            exit;
        }

        // Extract ZIP file
        $zip = new ZipArchive;
        if ($zip->open($uploaded_zip) === TRUE) {
            $overwrite = isset($_POST['allow_overwrite']) ? true : false;
            $collision_files = array();
            $new_files = array();

            // Check for existing files
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $filename = $zip->getNameIndex($i);
                $filepath = $target_dir . $filename;

                if (file_exists($filepath)) {
                    $collision_files[] = $filename;
                } else {
                    $new_files[] = $filename;
                }
            }

            // Handle collisions
            if (count($collision_files) > 0 && !$overwrite) {
                // Close zip and delete uploaded file
                $zip->close();
                unlink($uploaded_zip);

                wp_redirect(add_query_arg('adu_error', 'collision_detected', wp_get_referer()));
                exit;
            } else {
                // Extract files
                $zip->extractTo($target_dir);
                $zip->close();

                // Delete the uploaded ZIP file
                unlink($uploaded_zip);

                // Prepare summary data
                $summary = array(
                    'overwritten_files' => $collision_files,
                    'new_files' => $new_files,
                );

                // Redirect with success message and summary
                wp_redirect(add_query_arg(array(
                    'adu_success' => 'upload_complete',
                    'adu_summary' => urlencode(json_encode($summary)),
                ), wp_get_referer()));
                exit;
            }
        } else {
            // Extraction failed
            unlink($uploaded_zip);
            wp_redirect(add_query_arg('adu_error', 'extraction_error', wp_get_referer()));
            exit;
        }
    }

    public function render_settings_page() {
        // Check if user has the required capability
        if (!current_user_can('manage_options')) {
            wp_die('Unauthorized user');
        }

        // Save settings if form is submitted
        if (isset($_POST['adu_settings_nonce']) && wp_verify_nonce($_POST['adu_settings_nonce'], 'adu_save_settings')) {
            $max_file_size = intval($_POST['adu_max_file_size']);
            $target_directory = sanitize_text_field($_POST['adu_target_directory']);
            $overwrite_by_default = isset($_POST['adu_overwrite_by_default']) ? true : false;

            update_option('adu_max_file_size', $max_file_size);
            update_option('adu_target_directory', $target_directory);
            update_option('adu_overwrite_by_default', $overwrite_by_default);

            echo '<div class="updated"><p>Settings saved successfully.</p></div>';
        }

        // Get current settings
        $max_file_size = get_option('adu_max_file_size', 50);
        $target_directory = get_option('adu_target_directory', 'custom-directory');
        $overwrite_by_default = get_option('adu_overwrite_by_default', false);
        ?>
        <div class="wrap">
            <h1>Advanced Directory Uploader Settings</h1>
            <form method="post" action="">
                <?php wp_nonce_field('adu_save_settings', 'adu_settings_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="adu_max_file_size">Maximum Upload Size (MB)</label></th>
                        <td><input type="number" name="adu_max_file_size" id="adu_max_file_size" value="<?php echo esc_attr($max_file_size); ?>" min="1"></td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="adu_target_directory">Target Directory</label></th>
                        <td>
                            <input type="text" name="adu_target_directory" id="adu_target_directory" value="<?php echo esc_attr($target_directory); ?>">
                            <p class="description">Directory inside wp-content/uploads/ where files will be extracted.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="adu_overwrite_by_default">Overwrite by Default</label></th>
                        <td><input type="checkbox" name="adu_overwrite_by_default" id="adu_overwrite_by_default" value="1" <?php checked($overwrite_by_default); ?>></td>
                    </tr>
                </table>
                <?php submit_button('Save Settings'); ?>
            </form>
        </div>
        <?php
    }

    private function display_error_message($error_code) {
        $messages = array(
            'no_file' => 'Please select a ZIP file to upload.',
            'file_too_large' => 'The uploaded file exceeds the maximum allowed size.',
            'invalid_file_type' => 'Invalid file type. Only ZIP files are permitted.',
            'upload_error' => 'An error occurred during file upload.',
            'extraction_error' => 'Failed to extract the ZIP file.',
            'collision_detected' => 'Existing files conflict with the uploaded files. Enable overwrite to proceed.',
        );

        if (isset($messages[$error_code])) {
            echo '<div class="notice notice-error"><p>' . esc_html($messages[$error_code]) . '</p></div>';
        }
    }

    private function display_success_message($success_code) {
        if ($success_code === 'upload_complete') {
            echo '<div class="notice notice-success"><p>Upload and extraction completed successfully.</p></div>';

            if (isset($_GET['adu_summary'])) {
                $summary = json_decode(urldecode($_GET['adu_summary']), true);
                if ($summary) {
                    echo '<h2>Upload Summary</h2>';
                    echo '<ul>';
                    if (!empty($summary['overwritten_files'])) {
                        echo '<li><strong>Overwritten Files:</strong><ul>';
                        foreach ($summary['overwritten_files'] as $file) {
                            echo '<li>' . esc_html($file) . '</li>';
                        }
                        echo '</ul></li>';
                    }
                    if (!empty($summary['new_files'])) {
                        echo '<li><strong>New Files:</strong><ul>';
                        foreach ($summary['new_files'] as $file) {
                            echo '<li>' . esc_html($file) . '</li>';
                        }
                        echo '</ul></li>';
                    }
                    echo '</ul>';
                }
            }
        }
    }
}

// Initialize the plugin
new AdvancedDirectoryUploader();
