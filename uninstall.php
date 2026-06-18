<?php
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}
wp_clear_scheduled_hook( 'fsb_cron_backup' );