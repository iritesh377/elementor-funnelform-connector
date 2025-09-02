<?php
if ( ! defined( 'ABSPATH' ) ) exit;

class EFC_Activator {
    public static function activate() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'efc_leads';
        $charset_collate = $wpdb->get_charset_collate();

        $sql = "CREATE TABLE $table_name (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            submission_id VARCHAR(100) NOT NULL UNIQUE,
            elementor_data LONGTEXT NULL,
            funnelform_data LONGTEXT NULL,
            created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id)
        ) $charset_collate;";

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        dbDelta( $sql );
    }
}
