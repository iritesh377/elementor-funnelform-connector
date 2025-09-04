<?php
/**
 * Plugin Name: Elementor → FunnelForm Connector
 * Description: Connect Elementor form submissions with FunnelForm surveys using a unique token.
 * Version: 1.2.0
 * Author: Ritesh Sapkota
 * Author URI: https://saliksapkota.com.np
 * License: GPLv2 or later
 * Text Domain: efc
 */

if ( ! defined( 'ABSPATH' ) ) exit;

define( 'EFC_VERSION', '1.2.0' );
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
    echo '<p><strong>Submission ID:</strong> ' . esc_html($submission_id) . '</p>';
    echo '<p><strong>Submitted At:</strong> ' . esc_html($lead->created_at) . '</p>';

    // Elementor data table
    echo '<h2>Elementor Data (Landing Page Form)</h2>';
    if (!empty($elementor_data)) {
        echo '<table class="widefat striped">';
        foreach ($elementor_data as $key => $value) {
            $label = $elementor_labels[$key] ?? ucfirst(str_replace('_', ' ', $key));
            $display = is_array($value) ? implode(', ', $value) : esc_html($value);
            echo "<tr><th style='width: 200px;'>{$label}</th><td>{$display}</td></tr>";
        }
        echo '</table>';
    } else {
        echo '<p>No Elementor data available.</p>';
    }

    // FunnelForm data table
    echo '<h2>FunnelForm Data (Survey Responses)</h2>';
    if (!empty($funnelform_data)) {
        
        // Check if we have the questions data in the expected format
        if (isset($funnelform_data['questions']) && is_array($funnelform_data['questions'])) {
            echo '<table class="widefat striped"><thead><tr><th style="width: 60%;">Question</th><th style="width: 40%;">Answer</th></tr></thead><tbody>';
            
            foreach ($funnelform_data['questions'] as $question => $answer) {
                // Clean up the question text
                $clean_question = trim($question);
                $clean_question = str_replace(["\n", "\t"], ' ', $clean_question);
                $clean_question = preg_replace('/\s+/', ' ', $clean_question);
                
                // Format the answer
                $formatted_answer = is_array($answer) ? implode(', ', $answer) : $answer;
                $formatted_answer = esc_html($formatted_answer);
                
                // Skip empty questions or vendor pitch questions (those that are just promotional text)
                if (empty(trim($clean_question)) || empty(trim($formatted_answer))) {
                    continue;
                }
                
                echo "<tr>";
                echo "<td style='vertical-align: top; padding: 12px;'><strong>" . esc_html($clean_question) . "</strong></td>";
                echo "<td style='vertical-align: top; padding: 12px;'>" . $formatted_answer . "</td>";
                echo "</tr>";
            }
            
            echo '</tbody></table>';
        } 
        // Fallback: check if we have all_questions_by_id format
        elseif (isset($funnelform_data['all_questions_by_id']) && is_array($funnelform_data['all_questions_by_id'])) {
            echo '<table class="widefat striped"><thead><tr><th style="width: 60%;">Question</th><th style="width: 40%;">Answer</th></tr></thead><tbody>';
            
            foreach ($funnelform_data['all_questions_by_id'] as $id => $qa_data) {
                if (isset($qa_data['question']) && isset($qa_data['answer'])) {
                    $clean_question = trim($qa_data['question']);
                    $clean_question = str_replace(["\n", "\t"], ' ', $clean_question);
                    $clean_question = preg_replace('/\s+/', ' ', $clean_question);
                    
                    $formatted_answer = is_array($qa_data['answer']) ? implode(', ', $qa_data['answer']) : $qa_data['answer'];
                    $formatted_answer = esc_html($formatted_answer);
                    
                    // Skip empty questions or answers
                    if (empty(trim($clean_question)) || empty(trim($formatted_answer))) {
                        continue;
                    }
                    
                    echo "<tr>";
                    echo "<td style='vertical-align: top; padding: 12px;'><strong>" . esc_html($clean_question) . "</strong></td>";
                    echo "<td style='vertical-align: top; padding: 12px;'>" . $formatted_answer . "</td>";
                    echo "</tr>";
                }
            }
            
            echo '</tbody></table>';
        }
        // Last fallback: display raw data in a more organized way
        else {
            echo '<h3>Raw FunnelForm Data:</h3>';
            echo '<div style="background: #f9f9f9; padding: 15px; border: 1px solid #ddd; border-radius: 4px;">';
            
            // Display key sections if they exist
            $sections_to_show = [
                'form_id' => 'Form ID',
                'lead_id' => 'Lead ID', 
                'lead_timestamp' => 'Lead Timestamp'
            ];
            
            foreach ($sections_to_show as $key => $label) {
                if (isset($funnelform_data[$key])) {
                    echo "<p><strong>{$label}:</strong> " . esc_html($funnelform_data[$key]) . "</p>";
                }
            }
            
            // Show the full JSON in a collapsible section
            echo '<details style="margin-top: 15px;">';
            echo '<summary style="cursor: pointer; font-weight: bold;">View Full JSON Data</summary>';
            echo '<pre style="background: white; padding: 10px; margin-top: 10px; border: 1px solid #ccc; max-height: 400px; overflow-y: auto;">';
            echo esc_html(json_encode($funnelform_data, JSON_PRETTY_PRINT));
            echo '</pre>';
            echo '</details>';
            echo '</div>';
        }
    } else {
        echo '<p><em>No FunnelForm data submitted yet.</em></p>';
    }

    // Add some additional info if available
    if (!empty($funnelform_data)) {
        echo '<h3>Survey Summary</h3>';
        echo '<div style="background: #f0f0f1; padding: 15px; border-left: 4px solid #0073aa; margin: 20px 0;">';
        
        if (isset($funnelform_data['form_id'])) {
            echo '<p><strong>Form ID:</strong> ' . esc_html($funnelform_data['form_id']) . '</p>';
        }
        if (isset($funnelform_data['lead_id'])) {
            echo '<p><strong>Lead ID:</strong> ' . esc_html($funnelform_data['lead_id']) . '</p>';
        }
        if (isset($funnelform_data['lead_timestamp'])) {
            echo '<p><strong>Survey Completed:</strong> ' . esc_html($funnelform_data['lead_timestamp']) . '</p>';
        }
        
        // Count the number of questions answered
        $question_count = 0;
        if (isset($funnelform_data['questions'])) {
            $question_count = count(array_filter($funnelform_data['questions'], function($answer) {
                return !empty(trim($answer));
            }));
        }
        if ($question_count > 0) {
            echo '<p><strong>Questions Answered:</strong> ' . $question_count . '</p>';
        }
        
        echo '</div>';
    }

    echo '<p style="margin-top: 30px;"><a href="' . admin_url('admin.php?page=efc-leads') . '" class="button button-secondary">&laquo; Back to Leads List</a></p>';
    echo '</div>';
}
