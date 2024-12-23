<?php


// Add theme support for various features
function your_theme_setup() {
    add_theme_support('title-tag');
    add_theme_support('custom-logo');
    add_theme_support('post-thumbnails');
    add_theme_support('customize-selective-refresh-widgets');
    // Add more supports as needed
}
add_action('after_setup_theme', 'your_theme_setup');


// Include the update checker class
require_once get_template_directory() . '/inc/theme-update.php';

// Get the current theme version from style.css
$current_theme_version = wp_get_theme()->get('Version');

// Initialize the update checker
new Theme_Update_Checker(
    'celestialinterface', // Replace with your theme's slug
    'kierankarn/celestialinterface', // GitHub username and repository name
    $current_theme_version
);


?>