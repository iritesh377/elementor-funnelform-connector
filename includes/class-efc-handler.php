<?php
if (!defined('ABSPATH')) exit;

class EFC_Handler {
    public function __construct() {
        // Capture Elementor form
        add_action('elementor_pro/forms/new_record', [$this, 'capture_elementor_form'], 10, 2);

        // Webhook endpoint for FunnelForm
        add_action('rest_api_init', function () {
            register_rest_route('efc/v1', '/funnelform-webhook', [
                'methods' => 'POST',
                'callback' => [$this, 'handle_funnelform_webhook'],
                'permission_callback' => '__return_true',
            ]);
        });
    }

    /**
     * Capture Elementor form and store submission
     */
    public function capture_elementor_form($record, $handler) {
        $form_name = $record->get_form_settings('form_name');

        if ('LandingPageLead' !== $form_name) return;

        $fields = [];
        foreach ($record->get('fields') as $id => $field) {
            $fields[$id] = $field['value'];
        }

        // Generate unique submission_id
        $submission_id = wp_generate_uuid4();

        // Save in DB
        global $wpdb;
        $wpdb->insert(
            $wpdb->prefix . 'efc_leads',
            [
                'submission_id'  => $submission_id,
                'elementor_data' => wp_json_encode($fields),
            ],
            ['%s', '%s']
        );

        // Redirect to FunnelForm page with submission_id
        $survey_url = site_url('/rg-sales-software-version/?submission_id=' . $submission_id);
        $handler->add_response_data('redirect_url', $survey_url);
    }

    /**
     * Handle FunnelForm webhook
     */
    public function handle_funnelform_webhook(WP_REST_Request $request) {
        $log_file = WP_CONTENT_DIR . '/efc-webhook-test.log';
        file_put_contents($log_file, "=== WEBHOOK CALLED AT " . date('Y-m-d H:i:s') . " ===\n", FILE_APPEND);

        global $wpdb;
        $table = $wpdb->prefix . 'efc_leads';

        try {
            // Parse JSON body reliably
            $json_data = $request->get_json_params();
            if (empty($json_data)) {
                $body = file_get_contents('php://input');
                $json_data = json_decode($body, true);
            }

            $query_params = $request->get_query_params() ?: [];

            // Log incoming request
            file_put_contents($log_file, "Raw body: " . file_get_contents('php://input') . "\n", FILE_APPEND);
            file_put_contents($log_file, "Parsed JSON: " . json_encode($json_data, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);
            file_put_contents($log_file, "Query Params: " . json_encode($query_params, JSON_PRETTY_PRINT) . "\n", FILE_APPEND);

            // Extract submission_id from query, query_string, or url
            $submission_id = null;

            if (!empty($query_params['submission_id'])) {
                $submission_id = sanitize_text_field($query_params['submission_id']);
                file_put_contents($log_file, "submission_id from query: {$submission_id}\n", FILE_APPEND);

            } elseif (!empty($json_data['query_string'])) {
                parse_str($json_data['query_string'], $qs_params);
                if (!empty($qs_params['submission_id'])) {
                    $submission_id = sanitize_text_field($qs_params['submission_id']);
                    file_put_contents($log_file, "submission_id from query_string: {$submission_id}\n", FILE_APPEND);
                }

            } elseif (!empty($json_data['url'])) {
                $parsed_url = parse_url($json_data['url']);
                if (!empty($parsed_url['query'])) {
                    parse_str($parsed_url['query'], $url_params);
                    if (!empty($url_params['submission_id'])) {
                        $submission_id = sanitize_text_field($url_params['submission_id']);
                        file_put_contents($log_file, "submission_id from url: {$submission_id}\n", FILE_APPEND);
                    }
                }

            } elseif (!empty($json_data['analytics_data']['query_string'])) {
                parse_str($json_data['analytics_data']['query_string'], $qs_params);
                if (!empty($qs_params['submission_id'])) {
                    $submission_id = sanitize_text_field($qs_params['submission_id']);
                    file_put_contents($log_file, "submission_id from analytics_data.query_string: {$submission_id}\n", FILE_APPEND);
                }

            } elseif (!empty($json_data['analytics_data']['url'])) {
                $parsed_url = parse_url($json_data['analytics_data']['url']);
                if (!empty($parsed_url['query'])) {
                    parse_str($parsed_url['query'], $url_params);
                    if (!empty($url_params['submission_id'])) {
                        $submission_id = sanitize_text_field($url_params['submission_id']);
                        file_put_contents($log_file, "submission_id from analytics_data.url: {$submission_id}\n", FILE_APPEND);
                    }
                }
            }

            if (!$submission_id) {
                file_put_contents($log_file, "ERROR: No submission_id found!\n", FILE_APPEND);
                return new WP_REST_Response([
                    'error' => 'No submission_id found',
                    'received_data' => [
                        'json'  => $json_data,
                        'query' => $query_params
                    ]
                ], 400);
            }

            // Check if submission exists
            $exists = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM $table WHERE submission_id = %s",
                $submission_id
            ));

            if ($exists == 0) {
                file_put_contents($log_file, "ERROR: submission_id not found in DB!\n", FILE_APPEND);
                return new WP_REST_Response([
                    'error' => 'submission_id not found',
                    'submission_id' => $submission_id
                ], 404);
            }

            // Update FunnelForm data
            $updated = $wpdb->update(
                $table,
                ['funnelform_data' => wp_json_encode($json_data)],
                ['submission_id' => $submission_id],
                ['%s'],
                ['%s']
            );

            if ($updated === false) {
                file_put_contents($log_file, "ERROR: DB update failed: " . $wpdb->last_error . "\n", FILE_APPEND);
                return new WP_REST_Response([
                    'error' => 'Database update failed',
                    'sql_error' => $wpdb->last_error
                ], 500);
            }

            file_put_contents($log_file, "SUCCESS: Updated {$updated} rows for submission_id: {$submission_id}\n", FILE_APPEND);
            file_put_contents($log_file, "=== END WEBHOOK ===\n\n", FILE_APPEND);

            return new WP_REST_Response([
                'success' => true,
                'submission_id' => $submission_id,
                'updated_rows' => $updated
            ], 200);

        } catch (Exception $e) {
            file_put_contents($log_file, "EXCEPTION: " . $e->getMessage() . "\n", FILE_APPEND);
            return new WP_REST_Response([
                'error' => 'Exception occurred',
                'message' => $e->getMessage()
            ], 500);
        }
    }
}
