<?php
// includes/install.php

function hgm_create_leads_table() {
    global $wpdb;

    $table_name = $wpdb->prefix . 'hgm_leads';
    $charset_collate = $wpdb->get_charset_collate();

    $sql = "CREATE TABLE $table_name (
        id mediumint(9) NOT NULL AUTO_INCREMENT,
        created_at datetime DEFAULT CURRENT_TIMESTAMP NOT NULL,
        first_name varchar(100) NOT NULL,
        email varchar(255) NOT NULL,
        phone varchar(50) NOT NULL,
        zip_code varchar(20) NOT NULL,
        PRIMARY KEY  (id)
    ) $charset_collate;";

    require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
    dbDelta($sql);
}