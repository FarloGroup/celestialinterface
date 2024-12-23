<?php
class Theme_Update_Checker {
    private $theme_slug;
    private $remote_repo;
    private $current_version;
    private $api_url;

    public function __construct($slug, $repo, $current_version) {
        $this->theme_slug = $slug;
        $this->remote_repo = $repo;
        $this->current_version = $current_version;
        $this->api_url = "https://api.github.com/repos/{$this->remote_repo}/releases/latest";

        // Hook into the theme update process
        add_filter('pre_set_site_transient_update_themes', array($this, 'check_for_update'));
    }

    /**
     * Check for theme update and perform the update
     */
    public function check_for_update($transient) {
        if (empty($transient->checked)) {
            return $transient;
        }

        // Ensure GITHUB_PAT is defined
        if (!defined('GITHUB_PAT') || empty(GITHUB_PAT)) {
            error_log('Theme Update Checker: GITHUB_PAT is not defined or empty.');
            return $transient;
        }

        // Prepare API request to GitHub
        $args = array(
            'headers' => array(
                'User-Agent'    => 'WordPress Theme Updater',
                'Authorization' => 'token ' . GITHUB_PAT,
                'Accept'        => 'application/vnd.github.v3+json',
            ),
            'timeout' => 10,
        );

        $response = wp_remote_get($this->api_url, $args);

        if (is_wp_error($response)) {
            error_log('Theme Update Checker: API request failed. ' . $response->get_error_message());
            return $transient;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code != 200) {
            error_log('Theme Update Checker: API response code ' . $response_code);
            return $transient;
        }

        $release = json_decode(wp_remote_retrieve_body($response));

        if (empty($release) || !isset($release->tag_name)) {
            error_log('Theme Update Checker: Invalid release data.');
            return $transient;
        }

        error_log('Theme Update Checker: Retrieved release version ' . $release->tag_name);

        $release_version = ltrim($release->tag_name, 'v');

        error_log('Theme Update Checker: Release version after trim ' . $release_version);

        if (version_compare($this->current_version, $release_version, '<')) {
            // Find the ZIP asset
            $download_url = '';
            foreach ($release->assets as $asset) {
                if (strpos($asset->name, '.zip') !== false) {
                    $download_url = $asset->browser_download_url;
                    error_log('Theme Update Checker: Found ZIP asset ' . $download_url);
                    break;
                }
            }

            if ($download_url) {
                // Use the PAT to download the ZIP file
                $download_response = wp_remote_get($download_url, array(
                    'headers' => array(
                        'User-Agent'    => 'WordPress Theme Updater',
                        'Authorization' => 'token ' . GITHUB_PAT,
                        'Accept'        => 'application/vnd.github.v3+json',
                    ),
                    'timeout' => 60,
                    'sslverify' => true,
                ));

                if (is_wp_error($download_response) || wp_remote_retrieve_response_code($download_response) != 200) {
                    error_log('Theme Update Checker: Failed to download the theme ZIP.');
                    return $transient;
                }

                $zip_body = wp_remote_retrieve_body($download_response);
                $upload_dir = wp_upload_dir();
                $temp_file = trailingslashit($upload_dir['basedir']) . "{$this->theme_slug}-update.zip";

                // Save the ZIP file locally using WP_Filesystem
                if (!function_exists('WP_Filesystem')) {
                    require_once ABSPATH . 'wp-admin/includes/file.php';
                }

                WP_Filesystem();
                global $wp_filesystem;

                if (!$wp_filesystem->put_contents($temp_file, $zip_body, FS_CHMOD_FILE)) {
                    error_log('Theme Update Checker: Failed to save the theme ZIP locally.');
                    return $transient;
                }

                // Define a silent upgrader skin
                class WP_Silent_Upgrader_Skin extends Skin {
                    public function header() {}
                    public function footer() {}
                    public function error($error) {}
                    public function feedback($string, ...$args) {}
                }

                // Initialize the upgrader with the silent skin
                $upgrader = new Theme_Upgrader(new WP_Silent_Upgrader_Skin());

                // Perform the upgrade
                $result = $upgrader->upgrade($this->theme_slug);

                if (is_wp_error($result) || !$result) {
                    error_log('Theme Update Checker: Theme upgrade failed.');
                } else {
                    error_log('Theme Update Checker: Theme upgraded successfully.');
                }

                // Clean up the temporary ZIP file
                if ($wp_filesystem->exists($temp_file)) {
                    $wp_filesystem->delete($temp_file, true); // Force delete
                    error_log('Theme Update Checker: Temporary ZIP file deleted.');
                }

                // Prevent WordPress from attempting to download the ZIP again
                unset($transient->response[$this->theme_slug]);
            } else {
                error_log('Theme Update Checker: No ZIP asset found for release version ' . $release_version);
            }
        } else {
            error_log('Theme Update Checker: No update needed.');
        }

        return $transient;
    }
}

// Initialize the Theme Update Checker
$theme_slug = 'celestialinterface'; // Replace with your theme slug
$remote_repo = 'FarloGroup/celestialinterface'; // Replace with your GitHub repo in 'owner/repo' format
$current_version = wp_get_theme($theme_slug)->get('Version');
new Theme_Update_Checker($theme_slug, $remote_repo, $current_version);
?>
