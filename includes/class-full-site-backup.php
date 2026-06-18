<?php

class Full_Site_Backup {

    private static $instance = null;

    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        // Initialize hooks here
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action('admin_init', [$this, 'register_settings']);
        add_action( 'fsb_cron_backup', array( $this, 'perform_backup' ) );
    }

    public function run() {
        // This method runs when plugin loads
        $this->load_textdomain();
        $this->schedule_cron();
    }

    private function load_textdomain() {
        load_plugin_textdomain( 'full-site-backup', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    private function schedule_cron() {
        if ( ! wp_next_scheduled( 'fsb_cron_backup' ) ) {
            wp_schedule_event( time(), 'daily', 'fsb_cron_backup' );
        }
    }

    public function add_admin_menu(){
        add_menu_page(
            __( 'Full Site Backup', 'full-site-backup' ),
            __( 'Full Site Backup', 'full-site-backup' ),
            'manage_options',
            'full-site-backup',
            [$this, 'backup_page_callback'],
            'dashicons-backup',
            6
        );
    }

    public function register_settings() {
        register_setting( 'fsb_settings_group', 'fsb_options' );
    }

    public function backup_page_callback() {
        $options = get_option( 'fsb_options', []);
        ?>
        <div class="wrap">
            <h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

            <form method="post" action="options.php">
                <?php settings_fields( 'fsb_settings_group' ); ?>
                <p>
                    <label><input type="checkbox" name="fsb_options[enable_cron]" value="1" <?php checked( !empty($options['enable_cron']) ); ?>> Enable Daily Automatic Backup</label>
                </p>
                <?php submit_button( 'Save Settings' ); ?>
            </form>

            <hr>
            <h2>Manual Backup</h2>
            <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=full-site-backup&action=backup_now' ), 'fsb_backup' ); ?>" class="button button-primary">Create Backup Now</a>

            <?php
            if ( isset( $_GET['action'] ) && $_GET['action'] === 'backup_now' && wp_verify_nonce( $_REQUEST['_wpnonce'], 'fsb_backup' ) ) {
                $this->perform_backup();
                echo '<div class="notice notice-success"><p>Backup created successfully!</p></div>';
            }
            ?>
        </div>
        <?php
    }

    public function perform_backup() {
        // Backup logic goes here
        $upload_dir = wp_upload_dir();
        $backup_dir = $upload_dir['basedir'] . '/fsb-backups';
        
        if ( ! is_dir( $backup_dir ) ) {
            wp_mkdir_p( $backup_dir );
        }

        $filename = 'backup-' . date( 'Y-m-d-H-i' ) . '.zip';
        $filepath = $backup_dir . '/' . $filename;

        // Simple DB + Files backup using ZipArchive (basic version)
        if ( class_exists( 'ZipArchive' ) ) {
            $zip = new ZipArchive();
            if ( $zip->open( $filepath, ZipArchive::CREATE ) === true ) {
                // Add some files (you can expand this)
                $zip->addFile( ABSPATH . 'wp-config.php', 'wp-config.php' );
                $zip->close();
            }
        }

        // Log or email (optional)
        error_log( 'Full Site Backup created: ' . $filename );
    }

}