<?php
class Theme_Update_Checker {
    private $theme_slug;
    private $remote_repo;
    private $current_version;
    private $api_url;
    private $download_url;

    public function __construct($slug, $repo, $current_version) {
        $this->theme_slug = $slug;
        $this->remote_repo = $repo;
        $this->current_version = $current_version;
        $this->api_url = "https://api.github.com/repos/{$this->remote_repo}/releases/latest";
        $this->download_url = '';

        add_filter('pre_set_site_transient_update_themes', array($this, 'check_for_update'));
        add_filter('themes_api', array($this, 'theme_info'), 10, 3);
    }

    /**
     * Check for theme update
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
            foreach ($release->assets as $asset) {
                if (strpos($asset->name, '.zip') !== false) {
                    $this->download_url = $asset->browser_download_url;
                    error_log('Theme Update Checker: Found ZIP asset ' . $this->download_url);
                    break;
                }
            }

            if ($this->download_url) {
                $transient->response[$this->theme_slug] = array(
                    'theme'       => $this->theme_slug,
                    'new_version' => $release_version,
                    'url'         => $release->html_url,
                    'package'     => $this->download_url, // Update package URL
                );
                error_log('Theme Update Checker: Update added to transient.');
            } else {
                error_log('Theme Update Checker: No ZIP asset found for release version ' . $release_version);
            }
        } else {
            error_log('Theme Update Checker: No update needed.');
        }

        return $transient;
    }

    /**
     * Provide theme information for the update
     */
    public function theme_info($false, $action, $args) {
        if ($action !== 'theme_information' || $args->slug !== $this->theme_slug) {
            return false;
        }

        // Ensure GITHUB_PAT is defined
        if (!defined('GITHUB_PAT') || empty(GITHUB_PAT)) {
            error_log('Theme Update Checker: GITHUB_PAT is not defined or empty.');
            return false;
        }

        $args_api = array(
            'headers' => array(
                'User-Agent'    => 'WordPress Theme Updater',
                'Authorization' => 'token ' . GITHUB_PAT,
                'Accept'        => 'application/vnd.github.v3+json',
            ),
            'timeout' => 10,
        );


        $response = wp_remote_get($this->api_url, $args_api);


        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) != 200) {
            error_log('Theme Update Checker: Failed to retrieve release data for theme info.');
            return false;
        }

        $release = json_decode(wp_remote_retrieve_body($response));

        if (empty($release)) {
            error_log('Theme Update Checker: Release data is empty.');
            return false;
        }

        // Find the ZIP asset
        $download_link = '';
        foreach ($release->assets as $asset) {
            if (strpos($asset->name, '.zip') !== false) {
                $download_link = $asset->browser_download_url;
                break;
            }
        }

        if (empty($download_link)) {
            error_log('Theme Update Checker: No ZIP asset found in release.');
            return false;
        }

        $release_version = ltrim($release->tag_name, 'v');

        $theme_info = array(
            'name'        => $release->name,
            'version'     => $release_version,
            'author'      => $release->author->login,
            'homepage'    => $release->html_url,
            'sections'    => array(
                'description' => $release->body,
            ),
            'download_link' => $download_link,
        );

        error_log('Theme Update Checker: Release version: ' . $release_version);
        error_log('Theme Update Checker: Download URL: ' . $download_link);

        return (object) $theme_info;
    }
}

/**
 * Custom Upgrader Class to Handle Authenticated Downloads
 */
class Custom_Theme_Upgrader extends Theme_Update_Checker {
    /**
     * Override the theme information to handle authenticated downloads.
     */
    public function theme_info($false, $action, $args) {
        $theme_info = parent::theme_info($false, $action, $args);

        if (!$theme_info) {
            return false;
        }

        // Use the PAT to download the ZIP file
        $download_response = wp_remote_get($theme_info->download_link, array(
            'headers' => array(
                'User-Agent'    => 'WordPress Theme Updater',
                'Authorization' => 'token ' . GITHUB_PAT,
                'Accept'        => 'application/vnd.github.v3+json',
            ),
            'timeout' => 60,
            'sslverify' => true,
        ));

        if (is_wp_error($download_response) || wp_remote_retrieve_response_code($download_response) != 200) {
            error_log('Custom Theme Updater: Failed to download the theme ZIP.');
            return false;
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
            error_log('Custom Theme Updater: Failed to save the theme ZIP locally.');
            return false;
        }

        // Set the download_link to the local file
        $theme_info->download_link = trailingslashit($upload_dir['url']) . "{$this->theme_slug}-update.zip";

        // Initiate the theme upgrade
        $this->upgrade_theme($theme_info);

        return $theme_info;
    }

    /**
     * Upgrade the theme using the downloaded ZIP file.
     *
     * @param object $theme_info The theme information object.
     */
    private function upgrade_theme($theme_info) {
        require_once ABSPATH . 'wp-admin/includes/class-wp-upgrader.php';
        require_once ABSPATH . 'wp-admin/includes/theme.php';

        // Initialize the theme upgrader
        $upgrader = new Theme_Upgrader(new WP_Ajax_Upgrader_Skin());

        // Perform the upgrade
        $result = $upgrader->upgrade($this->theme_slug);

        if (is_wp_error($result) || !$result) {
            error_log('Custom Theme Updater: Theme upgrade failed.');
        } else {
            error_log('Custom Theme Updater: Theme upgraded successfully.');
        }

        // Clean up the temporary ZIP file
        $upload_dir = wp_upload_dir();
        $temp_file = trailingslashit($upload_dir['basedir']) . "{$this->theme_slug}-update.zip";
        if ($wp_filesystem->exists($temp_file)) {
            $wp_filesystem->delete($temp_file, true); // Force delete
            error_log('Custom Theme Updater: Temporary ZIP file deleted.');
        }
    }
}

// Initialize the Custom Theme Updater
$theme_slug = 'celestialinterface'; // Replace with your theme slug
$remote_repo = 'FarloGroup/celestialinterface'; // Replace with your GitHub repo in 'owner/repo' format
$current_version = wp_get_theme($theme_slug)->get('Version');
new Custom_Theme_Upgrader($theme_slug, $remote_repo, $current_version);
?>
