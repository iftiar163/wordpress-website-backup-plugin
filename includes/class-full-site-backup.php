<?php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

class Full_Site_Backup {

    private static $instance = null;
    private $backup_dir;

    public static function get_instance() {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
        $upload_dir = wp_upload_dir();
        $this->backup_dir = $upload_dir['basedir'] . '/fsb-backups';
    }

    private function init_hooks() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_init', array( $this, 'register_settings' ) );
        add_action( 'fsb_cron_backup', array( $this, 'perform_backup' ) );
    }

    public function run() {
        $this->load_textdomain();
        $this->create_backup_directory();
        $this->schedule_cron();
    }

    private function load_textdomain() {
        load_plugin_textdomain( 'full-site-backup', false, dirname( plugin_basename( FSB_PLUGIN_DIR ) ) . '/languages' );
    }

    private function create_backup_directory() {
        if ( ! is_dir( $this->backup_dir ) ) {
            wp_mkdir_p( $this->backup_dir );
        }
    }

    private function schedule_cron() {
        if ( ! wp_next_scheduled( 'fsb_cron_backup' ) ) {
            wp_schedule_event( time(), 'daily', 'fsb_cron_backup' );
        }
    }

    public function add_admin_menu() {
        add_menu_page(
            __( 'Full Site Backup & Migration', 'full-site-backup' ),
            __( 'Site Backup', 'full-site-backup' ),
            'manage_options',
            'full-site-backup',
            array( $this, 'backup_page_callback' ),
            'dashicons-backup',
            93
        );
    }

    public function register_settings() {
        register_setting( 'fsb_settings_group', 'fsb_options' );
    }

    public function backup_page_callback() {
        ?>
        <div class="wrap">
            <h1>Full Site Backup & Migration</h1>
            
            <div class="notice notice-info">
                <p><strong>Warning:</strong> For very large sites (50GB+), use a VPS or dedicated server with high limits.</p>
            </div>

            <h2>Manual Backup</h2>
            <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=full-site-backup&action=backup_now' ), 'fsb_backup' ); ?>" class="button button-primary button-large">Create Full Backup Now</a>

            <?php
            if ( isset( $_GET['action'] ) && $_GET['action'] === 'backup_now' && wp_verify_nonce( $_REQUEST['_wpnonce'], 'fsb_backup' ) ) {
                $this->perform_backup( true );
            }
            ?>

            <hr>
            <h2>Restore / Import</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="file" name="backup_file" accept=".zip">
                <input type="submit" name="restore_backup" class="button button-primary" value="Upload & Restore">
            </form>
            <?php
            if ( isset( $_POST['restore_backup'] ) ) {
                $this->handle_restore();
            }
            ?>
        </div>
        <?php
    }

    public function perform_backup( $manual = false ) {
        if ( ! class_exists( 'ZipArchive' ) ) {
            wp_die( 'ZipArchive is required but not available on your server.' );
        }

        $filename = 'fsb-backup-' . date( 'Y-m-d-H-i' ) . '.zip';
        $filepath = $this->backup_dir . '/' . $filename;

        $zip = new ZipArchive();
        if ( $zip->open( $filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE ) !== true ) {
            error_log( 'Failed to create zip file' );
            return false;
        }

        // Add wp-config.php
        $zip->addFile( ABSPATH . 'wp-config.php', 'wp-config.php' );

        // Add database (basic export)
        $this->add_database_to_zip( $zip );

        // Add important folders (you can expand this)
        $this->add_directory_to_zip( $zip, WP_CONTENT_DIR, 'wp-content' );

        $zip->close();

        if ( $manual ) {
            echo '<div class="notice notice-success"><p>Backup created successfully: <strong>' . esc_html( $filename ) . '</strong></p></div>';
        }

        return $filename;
    }

    private function add_database_to_zip( $zip ) {
        global $wpdb;
        $sql_file = $this->backup_dir . '/database.sql';

        $tables = $wpdb->get_results( "SHOW TABLES", ARRAY_A );
        $handle = fopen( $sql_file, 'w' );

        foreach ( $tables as $table ) {
            $table_name = $table['Tables_in_' . DB_NAME];
            $create = $wpdb->get_row( "SHOW CREATE TABLE `$table_name`", ARRAY_A );
            fwrite( $handle, $create['Create Table'] . ";\n\n" );
        }

        fclose( $handle );
        $zip->addFile( $sql_file, 'database.sql' );
        unlink( $sql_file ); // Clean up
    }

    private function add_directory_to_zip( $zip, $dir, $base ) {
        $iterator = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $dir ) );
        foreach ( $iterator as $file ) {
            if ( $file->isDir() ) continue;
            $filepath = $file->getPathname();
            $localpath = $base . '/' . substr( $filepath, strlen( $dir ) + 1 );
            $zip->addFile( $filepath, $localpath );
        }
    }

    private function handle_restore() {
        // This is complex and dangerous - basic placeholder
        echo '<div class="notice notice-warning"><p>Full restore functionality is complex and requires careful implementation. For production use, consider using Duplicator or All-in-One WP Migration for large sites.</p></div>';
    }
}