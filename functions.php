<?php
function your_theme_enqueue_scripts() {
    wp_enqueue_style('your-theme-style', get_stylesheet_uri());
    // Enqueue other styles and scripts here
}
add_action('wp_enqueue_scripts', 'your_theme_enqueue_scripts');

// Add theme support for various features
function your_theme_setup() {
    add_theme_support('title-tag');
    add_theme_support('custom-logo');
    add_theme_support('post-thumbnails');
    add_theme_support('customize-selective-refresh-widgets');
    // Add more supports as needed
}
add_action('after_setup_theme', 'your_theme_setup');
?>
