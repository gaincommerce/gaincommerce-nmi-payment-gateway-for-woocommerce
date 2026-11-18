<?php
if ( ! defined( 'ABSPATH' ) ) exit;

function apnmi_locate_template($file_path)
{
    $template = apnmi_get_plugin_dir() . 'template/' . $file_path;

    if (file_exists($template)) {
        return $template;
    }

    return false;
}

function apnmi_inc_template($template_file, $args = [], $load_once = true)
{
    $get_template = apnmi_locate_template($template_file);

    if ($get_template) {
        if (!empty($args) && is_array($args)) {
            extract($args, EXTR_SKIP);
        }
        
        if ($load_once) {
            include_once $get_template;
        } else {
            include $get_template;
        }
    }
}

function apnmi_render_admin_template($template_file, $args = [], $load_once = true)
{
    $get_template = apnmi_locate_template('admin/' . $template_file);

    if ($get_template) {
        apnmi_inc_template($get_template, $args, $load_once);
    }
}

function apnmi_render_public_template($template_file, $args = [], $load_once = true)
{
    $get_template = apnmi_locate_template('public/' . $template_file);
    
    if ($get_template) {
        apnmi_inc_template($get_template, $args, $load_once);
    }
}

// Opt-in developer debug helper (like Laravel's dd()) — never called anywhere in
// the plugin itself; it only runs if a developer adds a call to it manually while
// debugging locally.
// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r
function apnmi_dd($data =[], $label ='', $exit = false)
{
    echo '<pre>';
    if($label) {
        echo '<strong>' . esc_html($label) . ':</strong> ';
    }
    print_r($data); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_print_r -- intentional debug helper, see note above.
    echo '</pre>';
    if($exit){
        exit;
    }
}
