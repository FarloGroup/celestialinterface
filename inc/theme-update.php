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

public function check_for_update($transient) {
    if (empty($transient->checked)) {
        return $transient;
    }

    $args = array(
        'headers' => array(
            'User-Agent' => 'WordPress',
        ),
        'timeout' => 10,
    );

    $response = wp_remote_get($this->api_url, $args);

    if (is_wp_error($response)) {
        error_log('Theme Update Checker: API request failed.');
        return $transient;
    }

    if (wp_remote_retrieve_response_code($response) != 200) {
        error_log('Theme Update Checker: API response code ' . wp_remote_retrieve_response_code($response));
        return $transient;
    }

    $release = json_decode(wp_remote_retrieve_body($response));
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
                'package'     => $this->download_url,
            );
            error_log('Theme Update Checker: Update added to transient.');
        } else {
            error_log('Theme Update Checker: No ZIP asset found.'. var_dump($release));
        }
    } else {
        error_log('Theme Update Checker: No update needed.');
    }

    return $transient;
}



    public function theme_info($false, $action, $args) {
        if ($action !== 'theme_information' || $args->slug !== $this->theme_slug) {
            return false;
        }

        $args_api = array(
            'headers' => array(
                'User-Agent'    => 'WordPress',
                'Authorization' => defined('GITHUB_TOKEN') ? 'token ' . GITHUB_TOKEN : '',
            ),
            'timeout' => 10,
        );

        $response = wp_remote_get($this->api_url, $args_api);

        if (is_wp_error($response) || wp_remote_retrieve_response_code($response) != 200) {
            return false;
        }

        $release = json_decode(wp_remote_retrieve_body($response));

        $theme_info = array(
            'name'        => $release->name,
            'version'     => ltrim($release->tag_name, 'v'),
            'author'      => $release->author->login,
            'homepage'    => $release->html_url,
            'sections'    => array(
                'description' => $release->body,
            ),
        );
error_log('Release version: ' . $release_version);
error_log('Download URL: ' . $this->download_url);

        return (object) $theme_info;
    }
}
?>
