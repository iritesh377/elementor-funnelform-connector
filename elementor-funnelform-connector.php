<?php
/**
 * Plugin Name: Elementor → FunnelForm Connector
 * Description: Connect Elementor form submissions with FunnelForm surveys using a unique token.
 * Version: 1.1.0
 * Author: Ritesh Sapkota
 * Author URI: https://saliksapkota.com.np
 * License: GPLv2 or later
 * Text Domain: efc
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'EFC_VERSION', '1.1.0' );
define( 'EFC_PATH', plugin_dir_path( __FILE__ ) );
define( 'EFC_URL', plugin_dir_url( __FILE__ ) );

// Autoload classes
require_once EFC_PATH . 'includes/class-efc-activator.php';
require_once EFC_PATH . 'includes/class-efc-handler.php';

// On activation: create DB table
register_activation_hook( __FILE__, ['EFC_Activator', 'activate'] );

// Init plugin
add_action('plugins_loaded', function() {
    new EFC_Handler();
});

// Handle deletion of submissions
add_action('admin_post_efc_delete_submission', function() {
    if (!current_user_can('manage_options')) {
        wp_die('Unauthorized user');
    }

    $submission_id = sanitize_text_field($_GET['submission_id'] ?? '');
    if (!$submission_id || !wp_verify_nonce($_GET['_wpnonce'] ?? '', 'efc_delete_submission')) {
        wp_die('Invalid request');
    }

    global $wpdb;
    $table = $wpdb->prefix . 'efc_leads';
    $wpdb->delete($table, ['submission_id' => $submission_id], ['%s']);

    wp_redirect(admin_url('admin.php?page=efc-leads&deleted=1'));
    exit;
});

// Admin menu
add_action('admin_menu', function() {
    add_menu_page(
        'EFC Leads',
        'EFC Leads',
        'manage_options',
        'efc-leads',
        'efc_leads_index',
        'dashicons-feedback',
        6
    );

    add_submenu_page(
        null,
        'EFC Lead Detail',
        'EFC Lead Detail',
        'manage_options',
        'efc-leads-view',
        'efc_lead_view'
    );
});

// Leads index
function efc_leads_index() {
    if (isset($_GET['deleted'])) {
        echo '<div class="notice notice-success is-dismissible"><p>Submission deleted successfully.</p></div>';
    }

    global $wpdb;
    $table = $wpdb->prefix . 'efc_leads';
    $leads = $wpdb->get_results("SELECT * FROM $table ORDER BY created_at DESC");

    echo '<div class="wrap"><h1>EFC Leads</h1>';
    echo '<table class="widefat fixed striped"><thead><tr>
        <th>Submission ID</th>
        <th>First Name</th>
        <th>Email</th>
        <th>Submitted At</th>
        <th>Actions</th>
        </tr></thead><tbody>';

    foreach ($leads as $lead) {
        $elementor_data = json_decode($lead->elementor_data, true);
        $first_name = $elementor_data['first_name'] ?? '';
        $email      = $elementor_data['email'] ?? '';

        $view_url   = admin_url('admin.php?page=efc-leads-view&submission_id=' . $lead->submission_id);
        $delete_url = wp_nonce_url(admin_url('admin-post.php?action=efc_delete_submission&submission_id=' . $lead->submission_id), 'efc_delete_submission');

        echo "<tr>
            <td>{$lead->submission_id}</td>
            <td>{$first_name}</td>
            <td>{$email}</td>
            <td>{$lead->created_at}</td>
            <td>
                <a href='{$view_url}'>View</a> | 
                <a href='{$delete_url}' onclick=\"return confirm('Are you sure you want to delete this submission?');\">Delete</a>
            </td>
        </tr>";
    }

    echo '</tbody></table></div>';
}

// Lead detail page
function efc_lead_view() {
    if (!isset($_GET['submission_id'])) {
        echo '<div class="wrap"><p>No submission selected.</p></div>';
        return;
    }

    $submission_id = sanitize_text_field($_GET['submission_id']);
    global $wpdb;
    $table = $wpdb->prefix . 'efc_leads';
    $lead = $wpdb->get_row($wpdb->prepare("SELECT * FROM $table WHERE submission_id = %s", $submission_id));

    if (!$lead) {
        echo '<div class="wrap"><p>Submission not found.</p></div>';
        return;
    }

    $elementor_data  = json_decode($lead->elementor_data, true);
    $funnelform_data = json_decode($lead->funnelform_data, true);

    // Elementor readable labels
    $elementor_labels = [
        'first_name' => 'First Name',
        'last_name'  => 'Last Name',
        'email'      => 'Email',
        'phone'      => 'Phone',
        'zipcode'    => 'Zip Code',
        'acceptance' => 'Terms Accepted'
    ];

    echo '<div class="wrap">';
    echo '<h1>Submission Detail</h1>';

    // Elementor data table
    echo '<h2>Elementor Data</h2><table class="widefat striped">';
    foreach ($elementor_data as $key => $value) {
        $label = $elementor_labels[$key] ?? $key;
        $display = is_array($value) ? implode(', ', $value) : $value;
        echo "<tr><th>{$label}</th><td>{$display}</td></tr>";
    }
    echo '</table>';

    // FunnelForm data table
    echo '<h2>FunnelForm Data</h2>';
    if (!empty($funnelform_data)) {
        echo '<table class="widefat striped"><thead><tr><th>Field / Question</th><th>Answer</th></tr></thead><tbody>';
        foreach ($funnelform_data as $key => $value) {
            $display = is_array($value) ? implode(', ', $value) : $value;
            echo "<tr><td>{$key}</td><td>{$display}</td></tr>";
        }
        echo '</tbody></table>';
    } else {
        echo '<p>No FunnelForm data submitted yet.</p>';
    }

    echo '<p><a href="' . admin_url('admin.php?page=efc-leads') . '" class="button button-secondary">&laquo; Back to Leads List</a></p>';
    echo '</div>';
}
