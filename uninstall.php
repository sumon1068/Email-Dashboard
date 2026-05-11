<?php
/**
 * Uninstall Mailora Email Composer
 *
 * Runs automatically when the plugin is deleted from the WordPress admin.
 * Drops all custom tables and removes any plugin options from wp_options.
 */
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

global $wpdb;

// Drop custom tables — direct schema changes are required in uninstall context; no WP API exists for this.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpped_email_log" );
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}wpped_settings" );

// Remove infrastructure option stored in wp_options
delete_option( 'wpped_schema_version' );

// Remove legacy option in case user is upgrading from an older version
delete_option( 'wpped_from_address' );

// Remove activation redirect transient
delete_option( 'wpped_activation_redirect' );
