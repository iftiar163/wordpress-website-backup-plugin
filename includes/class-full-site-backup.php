<?php
/**
 * Main Class for Full Site Backup
 */

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
        $this->backup_dir = FSB_PLUGIN_DIR . 'backups/';
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'fsb_cron_backup', array( $this, 'perform_backup' ) );
    }

    public function run() {
        $this->create_backup_directory();
    }

    private function create_backup_directory() {
        if ( ! is_dir( $this->backup_dir ) ) {
            wp_mkdir_p( $this->backup_dir );
        }
        if ( is_dir( $this->backup_dir ) && ! is_writable( $this->backup_dir ) ) {
            @chmod( $this->backup_dir, 0755 );
        }

        // Stop search engines / crawlers from indexing the backups folder,
        // and stop direct web access to its contents as a basic safety net.
        $htaccess = $this->backup_dir . '.htaccess';
        if ( ! file_exists( $htaccess ) ) {
            @file_put_contents( $htaccess, "Deny from all\n" );
        }
        $index = $this->backup_dir . 'index.php';
        if ( ! file_exists( $index ) ) {
            @file_put_contents( $index, "<?php\n// Silence is golden.\n" );
        }
    }

    public function add_admin_menu() {
        add_menu_page(
            __( 'Full Site Backup', 'full-site-backup' ),
            __( 'Site Backup', 'full-site-backup' ),
            'manage_options',
            'full-site-backup',
            array( $this, 'backup_page_callback' ),
            'dashicons-backup',
            93
        );
    }

    public function backup_page_callback() {
        ?>
        <div class="wrap">
            <h1>Full Site Backup</h1>

            <h2>Create New Backup</h2>
            <a href="<?php echo wp_nonce_url( admin_url( 'admin.php?page=full-site-backup&action=backup_now' ), 'fsb_backup' ); ?>" 
               class="button button-primary button-large">
                Create Backup Now
            </a>

            <?php
            if ( isset( $_GET['action'] ) && $_GET['action'] === 'backup_now' && wp_verify_nonce( $_REQUEST['_wpnonce'], 'fsb_backup' ) ) {
                $this->perform_backup( true );
            }

            if ( isset( $_GET['action'] ) && $_GET['action'] === 'download' && ! empty( $_GET['file'] ) ) {
                $this->handle_download( sanitize_text_field( $_GET['file'] ) );
            }
            ?>

            <hr>
            <h2>Available Backups</h2>
            <?php $this->list_backups(); ?>

            <hr>
            <h2>Restore / Import</h2>
            <form method="post" enctype="multipart/form-data">
                <input type="file" name="backup_file" accept=".zip">
                <?php wp_nonce_field( 'fsb_restore', 'fsb_restore_nonce' ); ?>
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
            if ( $manual ) {
                echo '<div class="notice notice-error"><p>❌ ZipArchive extension is not enabled on your server.</p></div>';
            }
            return false;
        }

        $this->create_backup_directory();
        if ( ! is_dir( $this->backup_dir ) ) {
            if ( $manual ) {
                echo '<div class="notice notice-error"><p>❌ Backup directory could not be created: <strong>' . esc_html( $this->backup_dir ) . '</strong></p></div>';
            }
            error_log( 'FSB: Backup directory does not exist: ' . $this->backup_dir );
            return false;
        }

        if ( ! is_writable( $this->backup_dir ) ) {
            if ( $manual ) {
                echo '<div class="notice notice-error"><p>❌ Backup directory is not writable: <strong>' . esc_html( $this->backup_dir ) . '</strong></p></div>';
            }
            error_log( 'FSB: Backup directory is not writable: ' . $this->backup_dir );
            return false;
        }

        $filename = 'fsb-backup-' . date( 'Y-m-d-H-i-s' ) . '.zip';
        $filepath = $this->backup_dir . $filename;

        $zip = new ZipArchive();
        $open_result = $zip->open( $filepath, ZipArchive::CREATE | ZipArchive::OVERWRITE );

        if ( $open_result !== true ) {
            if ( $manual ) {
                echo '<div class="notice notice-error"><p>❌ Could not create backup file (Error: ' . intval( $open_result ) . ').<br>Check permissions on folder: <strong>' . esc_html( $this->backup_dir ) . '</strong></p></div>';
            }
            error_log( 'FSB: Could not open ZIP file: Error ' . intval( $open_result ) . ' - ' . $filepath );
            return false;
        }

        // Add wp-config.php
        if ( file_exists( ABSPATH . 'wp-config.php' ) ) {
            if ( ! $zip->addFile( ABSPATH . 'wp-config.php', 'wp-config.php' ) ) {
                error_log( 'FSB: Failed to add wp-config.php to backup' );
            }
        }

        // Add Database — keep track of the temp SQL file so we can delete
        // it AFTER $zip->close(), not before. (This was the bug.)
        $temp_sql_file = $this->add_database_to_zip( $zip );

        // Exclude our own backups/ folder when zipping wp-content/plugins,
        // otherwise every backup zip starts including all previous backup
        // zips (and itself), growing forever.
        $exclude_paths = array( realpath( $this->backup_dir ) );

        if ( is_dir( WP_CONTENT_DIR . '/uploads' ) ) {
            $this->add_directory_to_zip( $zip, WP_CONTENT_DIR . '/uploads', 'wp-content/uploads' );
        }

        if ( is_dir( WP_CONTENT_DIR . '/themes' ) ) {
            $this->add_directory_to_zip( $zip, WP_CONTENT_DIR . '/themes', 'wp-content/themes' );
        }

        if ( is_dir( WP_CONTENT_DIR . '/plugins' ) ) {
            $this->add_directory_to_zip( $zip, WP_CONTENT_DIR . '/plugins', 'wp-content/plugins', $exclude_paths );
        }

        // Close the zip. This is when ZipArchive actually reads
        // wp-config.php, database.sql, and every queued file from disk.
        $close_result = $zip->close();

        // Only now is it safe to remove the temp SQL dump — ZipArchive has
        // already finished reading from it during close().
        if ( $temp_sql_file && file_exists( $temp_sql_file ) ) {
            unlink( $temp_sql_file );
        }

        if ( ! $close_result ) {
            if ( $manual ) {
                echo '<div class="notice notice-error"><p>❌ Failed to finalize backup file.</p></div>';
            }
            error_log( 'FSB: Failed to close ZIP file at: ' . $filepath );
            if ( file_exists( $filepath ) ) {
                unlink( $filepath );
            }
            return false;
        }

        if ( file_exists( $filepath ) && filesize( $filepath ) > 0 ) {
            if ( $manual ) {
                $size = round( filesize( $filepath ) / (1024 * 1024), 2 );
                echo '<div class="notice notice-success"><p>✅ Backup created successfully!<br><strong>' . esc_html( $filename ) . '</strong> (' . esc_html( $size ) . ' MB)</p></div>';
            }
            error_log( 'FSB: Backup created successfully: ' . $filepath );
            return true;
        } else {
            if ( $manual ) {
                echo '<div class="notice notice-error"><p>❌ Failed to create backup file or file is empty.</p></div>';
            }
            error_log( 'FSB: Backup file missing or empty: ' . $filepath );
            if ( file_exists( $filepath ) ) {
                unlink( $filepath );
            }
            return false;
        }
    }

    /**
     * Writes a full DB dump to a temp .sql file and queues it into the zip.
     *
     * IMPORTANT: This does NOT delete the temp file. ZipArchive::addFile()
     * only stores a reference to the path — the file is actually read when
     * $zip->close() runs. The caller (perform_backup) is responsible for
     * deleting the returned path AFTER close() succeeds.
     *
     * @return string|false Path to the temp SQL file to clean up later, or false.
     */
    private function add_database_to_zip( $zip ) {
        global $wpdb;
        $sql_file = $this->backup_dir . 'temp_database.sql';

        $handle = @fopen( $sql_file, 'w' );
        if ( ! $handle ) {
            error_log( 'FSB: Could not open SQL file for writing: ' . $sql_file );
            return false;
        }

        $tables = $wpdb->get_results( "SHOW TABLES", ARRAY_A );

        if ( ! empty( $tables ) ) {
            foreach ( $tables as $table ) {
                $table_name = array_values( $table )[0];
                $create = $wpdb->get_row( "SHOW CREATE TABLE `$table_name`", ARRAY_A );
                if ( $create ) {
                    fwrite( $handle, $create['Create Table'] . ";\n\n" );

                    $rows = $wpdb->get_results( "SELECT * FROM `$table_name`", ARRAY_A );
                    if ( ! empty( $rows ) ) {
                        foreach ( $rows as $row ) {
                            // Preserve real NULLs instead of dumping them as ''.
                            $values = array_map( function( $v ) {
                                return is_null( $v ) ? 'NULL' : "'" . esc_sql( $v ) . "'";
                            }, array_values( $row ) );
                            $columns = implode( '`, `', array_keys( $row ) );
                            fwrite( $handle, "INSERT INTO `$table_name` (`$columns`) VALUES (" . implode( ', ', $values ) . ");\n" );
                        }
                    }
                }
            }
        }

        fclose( $handle );

        if ( file_exists( $sql_file ) && filesize( $sql_file ) > 0 ) {
            if ( ! $zip->addFile( $sql_file, 'database.sql' ) ) {
                error_log( 'FSB: Failed to add database.sql to backup' );
            }
            return $sql_file; // caller deletes this after $zip->close()
        }

        // Nothing was written — safe to remove immediately.
        unlink( $sql_file );
        return false;
    }

    /**
     * @param array $exclude_paths Absolute realpath()'d directories to skip.
     */
    private function add_directory_to_zip( $zip, $dir, $base, array $exclude_paths = array() ) {
        if ( ! is_dir( $dir ) ) {
            error_log( 'FSB: Directory does not exist: ' . $dir );
            return false;
        }

        if ( ! is_readable( $dir ) ) {
            error_log( 'FSB: Directory is not readable: ' . $dir );
            return false;
        }

        $file_count  = 0;
        $error_count = 0;

        try {
            $iterator = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator( $dir, RecursiveDirectoryIterator::SKIP_DOTS ),
                RecursiveIteratorIterator::SELF_FIRST
            );

            foreach ( $iterator as $file ) {
                if ( $file->isDir() ) {
                    continue;
                }

                try {
                    $filepath = $file->getPathname();

                    // Skip anything inside an excluded directory
                    // (e.g. our own backups/ folder).
                    $skip = false;
                    foreach ( $exclude_paths as $exclude ) {
                        if ( $exclude && strpos( $filepath, $exclude ) === 0 ) {
                            $skip = true;
                            break;
                        }
                    }
                    if ( $skip ) {
                        continue;
                    }

                    if ( ! file_exists( $filepath ) ) {
                        error_log( 'FSB: File disappeared: ' . $filepath );
                        $error_count++;
                        continue;
                    }

                    if ( ! is_readable( $filepath ) ) {
                        error_log( 'FSB: File not readable: ' . $filepath );
                        $error_count++;
                        continue;
                    }

                    $localpath = $base . '/' . substr( $filepath, strlen( $dir ) + 1 );
                    $localpath = str_replace( '\\', '/', $localpath );

                    if ( $zip->addFile( $filepath, $localpath ) ) {
                        $file_count++;
                    } else {
                        error_log( 'FSB: Failed to add file to zip: ' . $filepath );
                        $error_count++;
                    }
                } catch ( Exception $e ) {
                    error_log( 'FSB: Exception processing file: ' . $e->getMessage() );
                    $error_count++;
                }
            }

            error_log( 'FSB: Added ' . $file_count . ' files from ' . $base . ' (' . $error_count . ' errors)' );
            return true;
        } catch ( Exception $e ) {
            error_log( 'FSB: Error adding directory to zip: ' . $e->getMessage() );
            return false;
        }
    }

    private function list_backups() {
        if ( ! is_dir( $this->backup_dir ) ) {
            echo '<p>No backups found yet.</p>';
            return;
        }

        $files = array_diff( scandir( $this->backup_dir ), array( '.', '..' ) );
        $backups = array_filter( $files, function( $file ) {
            return strpos( $file, '.zip' ) !== false;
        });

        if ( empty( $backups ) ) {
            echo '<p>No backup files found yet. Create one above.</p>';
            return;
        }

        echo '<table class="wp-list-table widefat fixed striped">';
        echo '<thead><tr><th>Backup File</th><th>Size</th><th>Date</th><th>Action</th></tr></thead><tbody>';

        foreach ( $backups as $file ) {
            $filepath = $this->backup_dir . $file;
            $size = round( filesize( $filepath ) / (1024 * 1024), 2 ) . ' MB';
            $date = date( 'Y-m-d H:i', filemtime( $filepath ) );
            $download_url = wp_nonce_url( admin_url( 'admin.php?page=full-site-backup&action=download&file=' . urlencode( $file ) ), 'fsb_download' );

            echo "<tr>
                <td><strong>" . esc_html( $file ) . "</strong></td>
                <td>{$size}</td>
                <td>{$date}</td>
                <td><a href='" . esc_url( $download_url ) . "' class='button button-primary'>Download</a></td>
            </tr>";
        }
        echo '</tbody></table>';
    }

    private function handle_download( $file ) {
        if ( ! wp_verify_nonce( $_REQUEST['_wpnonce'], 'fsb_download' ) ) {
            wp_die( 'Security check failed.' );
        }

        // Prevent path traversal — only allow the bare filename, no slashes.
        $file = basename( $file );
        $filepath = $this->backup_dir . $file;

        if ( file_exists( $filepath ) ) {
            header( 'Content-Description: File Transfer' );
            header( 'Content-Type: application/zip' );
            header( 'Content-Disposition: attachment; filename="' . basename( $filepath ) . '"' );
            header( 'Expires: 0' );
            header( 'Cache-Control: must-revalidate' );
            header( 'Pragma: public' );
            header( 'Content-Length: ' . filesize( $filepath ) );
            flush();
            readfile( $filepath );
            exit;
        } else {
            wp_die( 'File not found.' );
        }
    }

    private function handle_restore() {
        echo '<div class="notice notice-info"><p>Restore functionality is under development. For large sites, we recommend using Duplicator or All-in-One WP Migration plugins.</p></div>';
    }
}