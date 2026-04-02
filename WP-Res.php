<?php
/**
 * Plugin Name: WP一键备份还原（WP One-Click Backup & Restore）
 * Description: 批量处理、兼容序列化的安全域名替换、Session保持、严格目录排除。提供现代UI、备份文件管理、分片上传（动态分片、断点续传、指数退避重试），包含磁盘空间预检查与ZIP64风险提示。
 * Version: 1.0.3
 * Author: Stone
 * Author URI: https://blog.cacca.cc
 * Text Domain: WP-Res
 * Domain Path: /languages
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_Backup_Restore_Active {

    const BACKUP_DIR = 'wpbkres';
    const BACKUP_EXT = '.bgbk';
    const BATCH_SIZE = 500;
    const COMMIT_INTERVAL = 20;
    const MAX_LOG_SIZE = 512 * 1024;

    private $stop_check_callback = null;
    private $log_level = 'INFO';

    const LOG_OFF     = 0;
    const LOG_ERROR   = 1;
    const LOG_WARNING = 2;
    const LOG_INFO    = 3;
    const LOG_DEBUG   = 4;

    public function __construct() {
        add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
        add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

        // 加载文本域
        add_action( 'plugins_loaded', array( $this, 'load_textdomain' ) );

        if ( ! wp_next_scheduled( 'wpbk_cleanup_temp' ) ) {
            wp_schedule_event( time(), 'daily', 'wpbk_cleanup_temp' );
        }
        add_action( 'wpbk_cleanup_temp', array( $this, 'cleanup_orphaned_temp' ) );

        $saved_level = get_option( 'wp_backup_log_level', 'INFO' );
        $this->log_level = $saved_level;
        add_action( 'wp_ajax_save_log_level', array( $this, 'ajax_save_log_level' ) );

        add_action( 'wp_ajax_wp_backup_download', array( $this, 'ajax_download_backup' ) );
        add_action( 'wp_ajax_wp_backup_delete',   array( $this, 'ajax_delete_backup' ) );
        add_action( 'wp_ajax_wp_backup_upload_chunk', array( $this, 'ajax_upload_chunk' ) );
        add_action( 'wp_ajax_wp_backup_upload_status', array( $this, 'ajax_upload_status' ) );
        add_action( 'wp_ajax_wp_backup_upload_cancel', array( $this, 'ajax_upload_cancel' ) );

        add_action( 'wp_ajax_wp_backup_check_space', array( $this, 'ajax_check_space' ) );
    }

    public function load_textdomain() {
        load_plugin_textdomain( 'WP-Res', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );
    }

    private function get_backup_dir() {
        return rtrim(ABSPATH, '/\\') . '/wp-content/uploads/' . self::BACKUP_DIR;
    }

    private function log_message($message, $level = 'INFO') {
        $level_values = [
            'ERROR'   => self::LOG_ERROR,
            'WARNING' => self::LOG_WARNING,
            'INFO'    => self::LOG_INFO,
            'DEBUG'   => self::LOG_DEBUG,
        ];
        $msg_level = isset( $level_values[ $level ] ) ? $level_values[ $level ] : self::LOG_INFO;
        if ( $msg_level > $this->get_log_level_value() ) return;

        $log_file = $this->get_backup_dir() . '/restore.log';
        $this->maybe_truncate_log( $log_file );
        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents( $log_file, "[$timestamp] [$level] $message" . PHP_EOL, FILE_APPEND | LOCK_EX );
    }

    private function maybe_truncate_log( $file ) {
        if ( file_exists( $file ) && filesize( $file ) > self::MAX_LOG_SIZE ) {
            @file_put_contents( $file, '' );
            $this->log_message( __( 'Log file exceeded 512KB, cleared', 'WP-Res' ), 'INFO' );
        }
    }

    private function maybe_truncate_worker_log() {
        $worker_log = $this->get_backup_dir() . '/worker_debug.log';
        if ( file_exists( $worker_log ) && filesize( $worker_log ) > self::MAX_LOG_SIZE ) {
            @file_put_contents( $worker_log, '' );
        }
    }

    private function get_log_level_value() {
        $levels = [
            'OFF'     => self::LOG_OFF,
            'ERROR'   => self::LOG_ERROR,
            'WARNING' => self::LOG_WARNING,
            'INFO'    => self::LOG_INFO,
            'DEBUG'   => self::LOG_DEBUG,
        ];
        return isset( $levels[ $this->log_level ] ) ? $levels[ $this->log_level ] : self::LOG_INFO;
    }

    public function ajax_save_log_level() {
        if ( ! current_user_can( 'manage_options' ) ) wp_die( __( 'Permission denied', 'WP-Res' ) );
        $level = isset( $_POST['level'] ) ? sanitize_text_field( $_POST['level'] ) : 'INFO';
        $allowed = array( 'OFF', 'ERROR', 'WARNING', 'INFO', 'DEBUG' );
        if ( in_array( $level, $allowed ) ) {
            update_option( 'wp_backup_log_level', $level );
            $this->log_level = $level;
            $this->log_message( sprintf( __( 'Log level changed to: %s', 'WP-Res' ), $level ), 'INFO' );
            wp_send_json_success( array( 'level' => $level ) );
        } else {
            wp_send_json_error( __( 'Invalid level', 'WP-Res' ) );
        }
    }

    public function set_stop_check_callback($callback) {
        $this->stop_check_callback = $callback;
    }

    private function should_stop($task_id) {
        if ($this->stop_check_callback) {
            return call_user_func($this->stop_check_callback, $task_id);
        }
        $file = $this->get_backup_dir() . '/state_' . $task_id . '.json';
        if (!file_exists($file)) return false;
        $data = json_decode(file_get_contents($file), true);
        return isset($data['stop']) && $data['stop'] === true;
    }

    public function update_worker_state( $task_id, $data ) {
        $dir = $this->get_backup_dir();
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $file = $dir . '/state_' . $task_id . '.json';
        $state = file_exists( $file ) ? json_decode( file_get_contents( $file ), true ) : [];
        $new_state = array_merge( (array)$state, (array)$data );
        file_put_contents( $file, json_encode( $new_state ), LOCK_EX );
    }

    public function cleanup_old_residuals( $current_task_id = null ) {
        $dir = $this->get_backup_dir();
        if ( ! is_dir( $dir ) ) return;
        foreach ( glob( $dir . '/temp_*' ) as $temp_dir ) {
            if ( is_dir( $temp_dir ) ) $this->remove_directory( $temp_dir );
        }
        foreach ( glob( $dir . '/state_*.json' ) as $state_file ) {
            if ( $current_task_id && strpos( $state_file, "state_{$current_task_id}.json" ) !== false ) continue;
            @unlink( $state_file );
        }
        foreach ( glob( ABSPATH . '.wp_restore_*' ) as $f ) @unlink( $f );
        @unlink( ABSPATH . 'database.sql' );
        @unlink( ABSPATH . 'siteinfo.json' );
        $this->log_message( __( 'Cleaned up residual files from previous tasks', 'WP-Res' ), 'INFO' );
    }

    // ==================== 空间与ZIP64检查 ====================

    private function estimate_site_size() {
        $total = 0;
        $root = ABSPATH;
        $exclude = $this->get_backup_dir();
        $iter = new RecursiveIteratorIterator( new RecursiveDirectoryIterator( $root, RecursiveDirectoryIterator::SKIP_DOTS ) );
        foreach ( $iter as $f ) {
            $p = str_replace( '\\', '/', $f->getRealPath() );
            if ( strpos( $p, $exclude ) === 0 ) continue;
            if ( $f->isFile() ) {
                $total += $f->getSize();
            }
        }
        global $wpdb;
        $db_size = $wpdb->get_var( "SELECT SUM(data_length + index_length) FROM information_schema.tables WHERE table_schema = DATABASE()" );
        if ( $db_size ) {
            $total += $db_size;
        }
        return $total;
    }

    private function estimate_restore_space( $backup_file ) {
        $zip = new ZipArchive();
        if ( $zip->open( $backup_file ) !== true ) {
            return 0;
        }
        $uncompressed = 0;
        for ( $i = 0; $i < $zip->numFiles; $i++ ) {
            $stat = $zip->statIndex( $i );
            $uncompressed += $stat['size'];
        }
        $zip->close();
        return $uncompressed * 1.2;
    }

    private function check_zip64_compatibility( $size_in_bytes ) {
        if ( $size_in_bytes <= 4 * 1024 * 1024 * 1024 ) {
            return '';
        }
        $php_version = PHP_VERSION;
        $libzip_version = phpversion('zip');
        if ( version_compare( PHP_VERSION, '7.0.0', '<' ) ) {
            return sprintf( __( 'Your PHP version is %s, which has incomplete support for ZIP64 (files >4GB). Backup/restore may fail. Upgrade to PHP 7.0+ recommended.', 'WP-Res' ), $php_version );
        } elseif ( $libzip_version && version_compare( $libzip_version, '1.6.0', '<' ) ) {
            return sprintf( __( 'Your libzip extension version is %s, which does not fully support ZIP64. Handling large files may fail. Upgrade libzip to 1.6.0 or later.', 'WP-Res' ), $libzip_version );
        }
        return __( 'Backup file exceeds 4GB. Your environment supports ZIP64, so it can be handled normally.', 'WP-Res' );
    }

    public function ajax_check_space() {
        if ( ! current_user_can( 'manage_options' ) ) {
            wp_send_json_error( __( 'Permission denied', 'WP-Res' ) );
        }
        if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'wp_backup_action' ) ) {
            wp_send_json_error( __( 'Invalid request', 'WP-Res' ) );
        }

        $type = isset( $_POST['check_type'] ) ? sanitize_text_field( $_POST['check_type'] ) : '';
        $backup_file = isset( $_POST['backup_file'] ) ? sanitize_text_field( $_POST['backup_file'] ) : '';

        $free = disk_free_space( ABSPATH );
        if ( $free === false ) {
            wp_send_json_error( __( 'Unable to get disk space information', 'WP-Res' ) );
        }

        $required = 0;
        $zip64_warning = '';
        $message = '';

        if ( $type === 'backup' ) {
            $total_size = $this->estimate_site_size();
            $required = $total_size * 1.2;
            $message = sprintf( __( 'Current site total size approximately %s. Backup process requires at least %s of free space.', 'WP-Res' ), size_format( $total_size ), size_format( $required ) );
            $zip64_warning = $this->check_zip64_compatibility( $total_size );
        } elseif ( $type === 'restore' ) {
            if ( empty( $backup_file ) || ! file_exists( $backup_file ) ) {
                wp_send_json_error( __( 'Backup file does not exist', 'WP-Res' ) );
            }
            $required = $this->estimate_restore_space( $backup_file );
            $message = sprintf( __( 'Extracted content estimated to occupy %s. Restore process requires at least %s of free space.', 'WP-Res' ), size_format( $required ), size_format( $required ) );
            $zip64_warning = $this->check_zip64_compatibility( filesize( $backup_file ) );
        } else {
            wp_send_json_error( __( 'Invalid check type', 'WP-Res' ) );
        }

        $enough = ( $free >= $required );
        wp_send_json_success( array(
            'enough'        => $enough,
            'free'          => size_format( $free ),
            'required'      => size_format( $required ),
            'message'       => $message,
            'zip64_warning' => $zip64_warning,
        ) );
    }

    // ==================== 备份引擎 ====================

    public function do_full_backup( $task_id, $params ) {
        $this->cleanup_old_residuals( $task_id );
        $this->maybe_truncate_worker_log();
        set_time_limit( 0 );
        @ini_set( 'memory_limit', '512M' );
        $backup_dir = $this->get_backup_dir();
        $site_host = parse_url( home_url(), PHP_URL_HOST );
        $site_host = preg_replace( '/[^a-z0-9\-\.]/i', '', $site_host );
        if ( empty( $site_host ) ) $site_host = 'site';
        $backup_name = 'backup_' . $site_host . '_' . date( 'Ymd_His' ) . self::BACKUP_EXT;
        $temp_dir = $backup_dir . '/temp_' . $task_id;
        @wp_mkdir_p( $temp_dir );
        try {
            $this->update_worker_state( $task_id, ['status' => 'processing', 'message' => __( 'Scanning site files...', 'WP-Res' ), 'percent' => 5] );
            $files = $this->get_file_list( ABSPATH, $backup_dir );
            $total_files = count($files);
            $temp_zip = $temp_dir . '/' . $backup_name . '.part';
            $zip = new ZipArchive();
            if ($zip->open( $temp_zip, ZipArchive::CREATE ) !== true) throw new Exception( __( 'Unable to create archive', 'WP-Res' ) );
            foreach ( $files as $i => $f ) {
                if ( $this->should_stop($task_id) ) throw new Exception( __( 'Task stopped by user', 'WP-Res' ) );
                if ( is_file( $f ) ) $zip->addFile( $f, str_replace( rtrim(ABSPATH, '/\\') . '/', '', $f ) );
                if ( $i % 1000 == 0 ) {
                    $p = 5 + round(($i / $total_files) * 50);
                    $this->update_worker_state( $task_id, ['message' => sprintf( __( 'Packing files: %d/%d', 'WP-Res' ), $i, $total_files ), 'percent' => $p] );
                }
            }
            $zip->close();
            $this->update_worker_state( $task_id, ['message' => __( 'Exporting database...', 'WP-Res' ), 'percent' => 60] );
            $sql_file = $temp_dir . '/database.sql';
            $this->export_db_streaming( $sql_file, $task_id );
            $zip->open( $temp_zip );
            $zip->addFile( $sql_file, 'database.sql' );
            $zip->addFromString( 'siteinfo.json', json_encode( ['siteurl' => home_url()] ) );
            $zip->close();
            rename( $temp_zip, $backup_dir . '/' . $backup_name );
            $this->remove_directory( $temp_dir );
            $this->update_worker_state( $task_id, ['status' => 'done', 'percent' => 100, 'message' => __( 'Backup completed successfully!', 'WP-Res' )] );
        } catch ( Exception $e ) {
            $this->update_worker_state( $task_id, ['status' => 'error', 'message' => $e->getMessage()] );
        }
    }

    // ==================== 还原引擎 ====================

    public function do_full_restore( $task_id, $params ) {
        set_time_limit( 0 );
        @ini_set( 'memory_limit', '512M' );
        $backup_file = isset($params['backup_file']) ? $params['backup_file'] : '';
        $current_site_url = isset($params['current_url']) ? untrailingslashit($params['current_url']) : untrailingslashit(home_url());
        $root_path = rtrim(ABSPATH, '/\\') . '/';
        $this->log_message( __( '=== Restore started ===', 'WP-Res' ), 'INFO' );
        $this->log_message( sprintf( __( 'Task ID: %s', 'WP-Res' ), $task_id ), 'INFO' );
        $this->log_message( sprintf( __( 'Backup file: %s', 'WP-Res' ), $backup_file ), 'INFO' );
        $this->log_message( sprintf( __( 'Current target domain: %s', 'WP-Res' ), $current_site_url ), 'INFO' );
        try {
            if (empty($backup_file) || !file_exists($backup_file)) throw new Exception( __( 'Backup file does not exist', 'WP-Res' ) );
            $this->update_worker_state( $task_id, ['status' => 'processing', 'message' => __( 'Extracting site files...', 'WP-Res' ), 'percent' => 10] );
            $zip = new ZipArchive();
            if ($zip->open($backup_file) === true) {
                $this->log_message( sprintf( __( 'Start extracting, total %d files', 'WP-Res' ), $zip->numFiles ), 'INFO' );
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    if ($this->should_stop($task_id)) throw new Exception( __( 'Task stopped', 'WP-Res' ) );
                    $filename = $zip->getNameIndex($i);
                    $zip->extractTo($root_path, $filename);
                    if ($i % 500 == 0) {
                        $p = 10 + round(($i / $zip->numFiles) * 30);
                        $this->update_worker_state( $task_id, ['message' => sprintf( __( 'Extracting files: %d/%d', 'WP-Res' ), $i, $zip->numFiles ), 'percent' => $p] );
                    }
                }
                $zip->close();
                $this->log_message( __( 'Extraction completed', 'WP-Res' ), 'INFO' );
            } else {
                throw new Exception( __( 'Cannot open backup file', 'WP-Res' ) );
            }
            $sql_file = $root_path . 'database.sql';
            if (file_exists($sql_file)) {
                $prefix = 'tmp_' . uniqid() . '_';
                $this->update_worker_state( $task_id, ['message' => __( 'Preparing bulk database import...', 'WP-Res' ), 'percent' => 45] );
                $this->import_sql_streaming_v2( $sql_file, $prefix, $task_id );
                $this->update_worker_state( $task_id, ['message' => __( 'Atomically replacing table structure...', 'WP-Res' ), 'percent' => 85] );
                $this->swap_temp_tables( $prefix );
                @unlink($sql_file);
                $this->log_message( __( 'Database import and swap completed', 'WP-Res' ), 'INFO' );
            }
            $siteinfo = $root_path . 'siteinfo.json';
            if (file_exists($siteinfo)) {
                $info = json_decode(file_get_contents($siteinfo), true);
                if (isset($info['siteurl'])) {
                    $old_url = untrailingslashit($info['siteurl']);
                    if ($old_url !== $current_site_url) {
                        $this->update_worker_state( $task_id, ['message' => sprintf( __( 'Performing safe domain replacement: %s -> %s', 'WP-Res' ), $old_url, $current_site_url )] );
                        $this->replace_domain_safe($old_url, $current_site_url);
                    }
                }
                @unlink($siteinfo);
            }
            if (isset($params['browser_cookie'])) {
                $this->update_worker_state( $task_id, ['message' => __( 'Restoring login session...', 'WP-Res' )] );
                $this->preserve_session_enhanced($params['browser_cookie']);
            }
            $this->cleanup_temp_files($task_id);
            $this->update_worker_state( $task_id, ['status' => 'done', 'percent' => 100, 'message' => __( 'Full site restore successful!', 'WP-Res' )] );
            $this->log_message( __( 'Restore process completed', 'WP-Res' ), 'INFO' );
        } catch ( Exception $e ) {
            $this->log_message( __( 'Exception: ', 'WP-Res' ) . $e->getMessage(), 'ERROR' );
            $this->update_worker_state( $task_id, ['status' => 'error', 'message' => $e->getMessage()] );
        }
    }

    // ==================== 辅助函数 ====================

    private function export_db_streaming( $file, $task_id ) {
        global $wpdb;
        $tables = $wpdb->get_results( "SHOW TABLES", ARRAY_N );
        $total = count($tables);
        file_put_contents( $file, "SET FOREIGN_KEY_CHECKS=0;\nSET AUTOCOMMIT=0;\n" );
        foreach ( $tables as $i => $t ) {
            $table = $t[0];
            $this->update_worker_state( $task_id, ['message' => sprintf( __( 'Exporting table: %s', 'WP-Res' ), $table ), 'percent' => 60 + round(($i/$total)*30)] );
            $create = $wpdb->get_row( "SHOW CREATE TABLE `$table`", ARRAY_N );
            file_put_contents( $file, "\nSTART TRANSACTION;\nDROP TABLE IF EXISTS `$table`;\n" . $create[1] . ";\n", FILE_APPEND );
            $offset = 0;
            while ( true ) {
                if ($this->should_stop($task_id)) throw new Exception( __( 'Stopped', 'WP-Res' ) );
                $rows = $wpdb->get_results( "SELECT * FROM `$table` LIMIT $offset, 5000", ARRAY_A );
                if ( empty( $rows ) ) break;
                $values = [];
                foreach ( $rows as $row ) {
                    $v = array_map( function($v) use ($wpdb) { return $v === null ? 'NULL' : "'" . $wpdb->_real_escape($v) . "'"; }, array_values($row) );
                    $values[] = "(" . implode(',', $v) . ")";
                    if ( count($values) >= self::BATCH_SIZE ) {
                        file_put_contents( $file, "INSERT INTO `$table` VALUES " . implode(',', $values) . ";\n", FILE_APPEND );
                        $values = [];
                    }
                }
                if ( !empty($values) ) file_put_contents( $file, "INSERT INTO `$table` VALUES " . implode(',', $values) . ";\n", FILE_APPEND );
                $offset += 5000;
                file_put_contents( $file, "COMMIT;\nSTART TRANSACTION;\n", FILE_APPEND );
            }
            file_put_contents( $file, "COMMIT;\n", FILE_APPEND );
        }
    }

    private function import_sql_streaming_v2( $file, $prefix, $task_id ) {
        global $wpdb;
        $total_statements = 0;
        $handle_stat = fopen($file, 'r');
        if ($handle_stat) {
            while (($line = fgets($handle_stat)) !== false) {
                $line_clean = trim($line);
                if ($line_clean != '' && substr($line_clean, 0, 2) != '--') {
                    if (preg_match('/;[\s\r\n]*$/', $line_clean)) $total_statements++;
                }
            }
            fclose($handle_stat);
        }
        $this->log_message( sprintf( __( 'Total SQL statements: %d', 'WP-Res' ), $total_statements ), 'DEBUG' );
        $handle = fopen( $file, 'r' );
        if (!$handle) return;
        $wpdb->query("SET FOREIGN_KEY_CHECKS=0");
        $wpdb->query("SET AUTOCOMMIT=0");
        $wpdb->query("START TRANSACTION");
        $query = ''; $q_count = 0;
        while (($line = fgets( $handle )) !== false) {
            $line_clean = trim($line);
            if ( $line_clean == '' || strpos($line_clean, '--') === 0 ) continue;
            $query .= $line;
            if ( preg_match('/;[\s\r\n]*$/', $line_clean) ) {
                $q = preg_replace('/(CREATE TABLE|INSERT INTO|DROP TABLE IF EXISTS) `(.*?)`/i', "$1 `{$prefix}$2`", $query);
                $wpdb->query($q);
                $query = ''; $q_count++;
                if ($q_count % self::COMMIT_INTERVAL == 0) {
                    $wpdb->query("COMMIT");
                    $wpdb->query("START TRANSACTION");
                    $percent = 40 + min(40, floor(($q_count / max(1, $total_statements)) * 40));
                    $this->update_worker_state($task_id, [
                        'message' => sprintf( __( 'Database restore progress: %d / %d SQL statements', 'WP-Res' ), $q_count, $total_statements ),
                        'percent' => $percent
                    ]);
                }
            }
        }
        $wpdb->query("COMMIT");
        fclose($handle);
    }

    private function replace_domain_safe($old, $new) {
        global $wpdb;
        $old_esc = esc_sql($old);
        $new_esc = esc_sql($new);
        $this->log_message( sprintf( __( 'Starting replace_domain_safe: %s -> %s', 'WP-Res' ), $old, $new ), 'INFO' );
        $sql1 = "UPDATE {$wpdb->options} SET option_value = '$new_esc' WHERE option_name IN ('siteurl','home')";
        $this->log_message( $sql1, 'DEBUG' );
        $result1 = $wpdb->query($sql1);
        $this->log_message( sprintf( __( 'Updated siteurl/home, rows affected: %s', 'WP-Res' ), ($result1 !== false ? $result1 : 'failed') ), 'INFO' );
        $sql2 = "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, '$old_esc', '$new_esc')";
        $this->log_message( $sql2, 'DEBUG' );
        $result2 = $wpdb->query($sql2);
        $this->log_message( sprintf( __( 'Updated post_content, rows affected: %s', 'WP-Res' ), ($result2 !== false ? $result2 : 'failed') ), 'INFO' );
        $sql3 = "UPDATE {$wpdb->posts} SET guid = REPLACE(guid, '$old_esc', '$new_esc')";
        $this->log_message( $sql3, 'DEBUG' );
        $result3 = $wpdb->query($sql3);
        $this->log_message( sprintf( __( 'Updated guid, rows affected: %s', 'WP-Res' ), ($result3 !== false ? $result3 : 'failed') ), 'INFO' );
        $this->deep_replace($wpdb->options, 'option_id', 'option_value', $old, $new);
        $sql4 = "UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, '$old_esc', '$new_esc')";
        $this->log_message( $sql4, 'DEBUG' );
        $result4 = $wpdb->query($sql4);
        $this->log_message( sprintf( __( 'Updated postmeta, rows affected: %s', 'WP-Res' ), ($result4 !== false ? $result4 : 'failed') ), 'INFO' );
        $wpdb->query('COMMIT');
        $this->log_message( __( 'COMMIT executed', 'WP-Res' ), 'INFO' );
        wp_cache_flush();
        $this->log_message( __( 'replace_domain_safe completed', 'WP-Res' ), 'INFO' );
    }

    private function deep_replace($table, $id_col, $val_col, $old, $new) {
        global $wpdb;
        $like = '%' . esc_sql($old) . '%';
        $rows = $wpdb->get_results($wpdb->prepare("SELECT $id_col, $val_col FROM $table WHERE $val_col LIKE %s", $like));
        if($rows) {
            foreach ($rows as $row) {
                $val = $row->$val_col;
                $fixed = $this->recursive_replace($val, $old, $new);
                if ($fixed !== $val) {
                    $wpdb->update($table, [$val_col => $fixed], [$id_col => $row->$id_col]);
                }
            }
        }
    }

    private function recursive_replace($data, $old, $new) {
        if (is_string($data)) {
            if (is_serialized($data)) {
                $un = @unserialize($data);
                if ($un !== false) return serialize($this->recursive_replace($un, $old, $new));
            }
            return str_replace($old, $new, $data);
        }
        if (is_array($data)) {
            foreach ($data as $k => $v) $data[$k] = $this->recursive_replace($v, $old, $new);
        }
        return $data;
    }

    private function preserve_session_enhanced($cookie_string) {
        global $wpdb;
        if (preg_match('/(wordpress_logged_in_[a-f0-9]{32})=([^;]+)/', $cookie_string, $matches)) {
            $cookie_value = urldecode($matches[2]);
            $parts = explode('|', $cookie_value);
            if (count($parts) >= 4) {
                $username = $parts[0];
                $token_hash = hash('sha256', $parts[2]);
                $user = $wpdb->get_row($wpdb->prepare("SELECT ID FROM $wpdb->users WHERE user_login = %s", $username));
                if ($user) {
                    $session = [
                        'expiration' => (int)$parts[1],
                        'ip'         => $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1',
                        'login'      => time(),
                        'ua'         => $_SERVER['HTTP_USER_AGENT'] ?? ''
                    ];
                    $existing_tokens = $wpdb->get_var($wpdb->prepare(
                        "SELECT meta_value FROM $wpdb->usermeta WHERE user_id = %d AND meta_key = 'session_tokens'",
                        $user->ID
                    ));
                    $tokens = maybe_unserialize($existing_tokens);
                    if (!is_array($tokens)) $tokens = [];
                    $tokens[$token_hash] = $session;
                    $wpdb->replace($wpdb->usermeta, [
                        'user_id'    => $user->ID,
                        'meta_key'   => 'session_tokens',
                        'meta_value' => serialize($tokens)
                    ]);
                    wp_cache_delete($user->ID, 'users');
                    wp_cache_delete($user->ID, 'user_meta');
                    $this->log_message( sprintf( __( 'Session restored successfully, user: %s', 'WP-Res' ), $username ), 'DEBUG' );
                }
            }
        }
    }

    private function get_file_list($root, $exclude) {
        $files = []; $ex_real = str_replace('\\','/',realpath($exclude));
        $iter = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS));
        foreach($iter as $f) {
            $p = str_replace('\\','/',$f->getRealPath());
            if ($ex_real && strpos($p, $ex_real)===0) continue;
            if ($f->isFile()) $files[] = $p;
        }
        return $files;
    }

    private function swap_temp_tables($prefix) {
        global $wpdb;
        $tables = $wpdb->get_results("SHOW TABLES LIKE '{$prefix}%'", ARRAY_N);
        $this->log_message( sprintf( __( 'Swapping temporary tables, total %d tables', 'WP-Res' ), count($tables) ), 'DEBUG' );
        foreach($tables as $t) {
            $temp = $t[0]; $real = substr($temp, strlen($prefix));
            $wpdb->query("DROP TABLE IF EXISTS `$real` ");
            $wpdb->query("RENAME TABLE `$temp` TO `$real` ");
        }
        $this->log_message( __( 'Table swap completed', 'WP-Res' ), 'DEBUG' );
    }

    private function remove_directory($dir) {
        if(!is_dir($dir)) return;
        $files = array_diff(scandir($dir), ['.','..']);
        foreach($files as $f) (is_dir("$dir/$f")) ? $this->remove_directory("$dir/$f") : unlink("$dir/$f");
        @rmdir($dir);
    }

    public function cleanup_temp_files($task_id) {
        $dir = $this->get_backup_dir();
        $this->remove_directory($dir . '/temp_' . $task_id);
        foreach (glob(ABSPATH . '.wp_restore_*') as $f) @unlink($f);
        @unlink(rtrim(ABSPATH, '/\\') . '/database.sql');
        @unlink(rtrim(ABSPATH, '/\\') . '/siteinfo.json');
    }

    public function cleanup_orphaned_temp() {
        $dir = $this->get_backup_dir();
        foreach (glob($dir . '/temp_*') as $t) {
            if (is_dir($t) && (time() - filemtime($t) > 3600)) $this->remove_directory($t);
        }
        foreach (glob($dir . '/state_*.json') as $f) {
            if (time() - filemtime($f) > 86400) @unlink($f);
        }
        $log = $dir . '/restore.log';
        if (file_exists($log) && filesize($log) > self::MAX_LOG_SIZE) @unlink($log);
        $worker_log = $dir . '/worker_debug.log';
        if (file_exists($worker_log) && filesize($worker_log) > self::MAX_LOG_SIZE) @unlink($worker_log);
    }

    public function get_backup_list() {
        $dir = $this->get_backup_dir();
        return glob($dir . '/*.bgbk') ?: [];
    }

    // ==================== AJAX 方法 ====================

    public function ajax_download_backup() {
        while (ob_get_level()) ob_end_clean();
        if ( ! current_user_can( 'manage_options' ) ) wp_die( __( 'Permission denied', 'WP-Res' ), '', array( 'response' => 403 ) );
        if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'wp_backup_action' ) ) wp_die( __( 'Invalid request', 'WP-Res' ), '', array( 'response' => 403 ) );
        $file = isset( $_GET['file'] ) ? sanitize_text_field( $_GET['file'] ) : '';
        if ( empty( $file ) ) wp_die( __( 'Missing file parameter', 'WP-Res' ), '', array( 'response' => 400 ) );
        $backup_dir = $this->get_backup_dir();
        $real_path = realpath( $backup_dir . '/' . basename( $file ) );
        if ( ! $real_path || strpos( $real_path, $backup_dir ) !== 0 || ! file_exists( $real_path ) ) {
            wp_die( __( 'Invalid file', 'WP-Res' ), '', array( 'response' => 404 ) );
        }
        header( 'Content-Description: File Transfer' );
        header( 'Content-Type: application/octet-stream' );
        header( 'Content-Disposition: attachment; filename="' . basename( $real_path ) . '"' );
        header( 'Content-Length: ' . filesize( $real_path ) );
        header( 'Cache-Control: no-cache, must-revalidate' );
        header( 'Pragma: no-cache' );
        header( 'Expires: 0' );
        readfile( $real_path );
        exit;
    }

    public function ajax_delete_backup() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( __( 'Permission denied', 'WP-Res' ) );
        if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'wp_backup_action' ) ) wp_send_json_error( __( 'Invalid request', 'WP-Res' ) );
        $file = isset( $_POST['file'] ) ? sanitize_text_field( $_POST['file'] ) : '';
        if ( empty( $file ) ) wp_send_json_error( __( 'Missing file parameter', 'WP-Res' ) );
        $backup_dir = $this->get_backup_dir();
        $real_path = realpath( $backup_dir . '/' . basename( $file ) );
        if ( ! $real_path || strpos( $real_path, $backup_dir ) !== 0 || ! file_exists( $real_path ) ) {
            wp_send_json_error( __( 'Invalid file', 'WP-Res' ) );
        }
        if ( unlink( $real_path ) ) {
            wp_send_json_success( [ 'message' => __( 'Deleted', 'WP-Res' ) ] );
        } else {
            wp_send_json_error( __( 'Deletion failed', 'WP-Res' ) );
        }
    }

    public function ajax_upload_chunk() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( __( 'Permission denied', 'WP-Res' ) );
        if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'wp_backup_action' ) ) wp_send_json_error( __( 'Invalid request', 'WP-Res' ) );

        $filename = isset( $_POST['filename'] ) ? sanitize_file_name( $_POST['filename'] ) : '';
        $start = isset( $_POST['start'] ) ? intval( $_POST['start'] ) : -1;
        $end = isset( $_POST['end'] ) ? intval( $_POST['end'] ) : -1;
        // 修复：使用字符串保留完整文件大小，避免 intval 溢出导致 TaskID 不一致
        $file_size = isset( $_POST['file_size'] ) ? (string) $_POST['file_size'] : '0';

        if ( empty( $filename ) || $start < 0 || $end <= $start || ! isset( $_FILES['file_chunk'] ) ) {
            wp_send_json_error( __( 'Invalid parameters', 'WP-Res' ) );
        }

        $chunk = $_FILES['file_chunk'];
        if ( $chunk['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( __( 'Chunk upload error', 'WP-Res' ) );
        }

        $backup_dir = $this->get_backup_dir();
        $temp_dir = $backup_dir . '/upload_temp';
        if ( ! is_dir( $temp_dir ) ) wp_mkdir_p( $temp_dir );

        $task_id = md5( $filename . $file_size . get_current_user_id() );
        $part_file = $temp_dir . '/' . $task_id . '_' . $start . '_' . $end . '.part';

        if ( ! move_uploaded_file( $chunk['tmp_name'], $part_file ) ) {
            wp_send_json_error( __( 'Failed to save chunk', 'WP-Res' ) );
        }

        $meta_file = $temp_dir . '/' . $task_id . '_meta.json';
        $meta = file_exists( $meta_file ) ? json_decode( file_get_contents( $meta_file ), true ) : [];
        $meta['filename'] = $filename;
        $meta['file_size'] = $file_size;
        if ( ! isset( $meta['parts'] ) ) $meta['parts'] = [];
        $meta['parts'][] = [ 'start' => $start, 'end' => $end ];
        $meta['parts'] = array_unique( $meta['parts'], SORT_REGULAR );
        file_put_contents( $meta_file, json_encode( $meta ) );

        $covered = $this->is_range_fully_covered( $meta['parts'], $file_size );
        if ( $covered ) {
            $final_file = $backup_dir . '/' . $filename;
            $handle = fopen( $final_file, 'wb' );
            if ( ! $handle ) {
                wp_send_json_error( __( 'Cannot create final file, please check directory permissions', 'WP-Res' ) );
            }
            usort( $meta['parts'], function($a, $b) { return $a['start'] - $b['start']; } );
            foreach ( $meta['parts'] as $part ) {
                $part_path = $temp_dir . '/' . $task_id . '_' . $part['start'] . '_' . $part['end'] . '.part';
                if ( ! file_exists( $part_path ) ) {
                    fclose( $handle );
                    @unlink( $final_file );
                    wp_send_json_error( __( 'Chunk file missing, merge failed', 'WP-Res' ) );
                }
                fseek( $handle, $part['start'] );
                $part_handle = fopen( $part_path, 'rb' );
                while ( ! feof( $part_handle ) ) {
                    fwrite( $handle, fread( $part_handle, 4096 ) );
                }
                fclose( $part_handle );
                unlink( $part_path );
            }
            fclose( $handle );
            unlink( $meta_file );
            @rmdir( $temp_dir );
            // 返回明确的状态，前端据此判断是否完成
            wp_send_json_success( [ 'status' => 'success', 'message' => __( 'Upload and merge completed', 'WP-Res' ) ] );
        } else {
            wp_send_json_success( [ 'status' => 'processing', 'message' => __( 'Chunk received', 'WP-Res' ) ] );
        }
    }

    private function is_range_fully_covered( $parts, $size ) {
        if ( empty( $parts ) ) return false;
        usort( $parts, function($a, $b) { return $a['start'] - $b['start']; } );
        $covered = 0;
        foreach ( $parts as $part ) {
            if ( $part['start'] > $covered ) return false;
            $covered = max( $covered, $part['end'] );
        }
        // $size 可能是字符串（大文件），转为整数比较前确保数值
        $size_int = (int) $size;
        return $covered >= $size_int;
    }

    public function ajax_upload_status() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( __( 'Permission denied', 'WP-Res' ) );
        if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'wp_backup_action' ) ) wp_send_json_error( __( 'Invalid request', 'WP-Res' ) );

        $filename = isset( $_POST['filename'] ) ? sanitize_file_name( $_POST['filename'] ) : '';
        $file_size = isset( $_POST['file_size'] ) ? (string) $_POST['file_size'] : '0';
        if ( empty( $filename ) ) wp_send_json_error( __( 'Invalid parameters', 'WP-Res' ) );

        $temp_dir = $this->get_backup_dir() . '/upload_temp';
        $task_id = md5( $filename . $file_size . get_current_user_id() );
        $meta_file = $temp_dir . '/' . $task_id . '_meta.json';

        if ( file_exists( $meta_file ) ) {
            $meta = json_decode( file_get_contents( $meta_file ), true );
            wp_send_json_success( [ 'parts' => $meta['parts'] ] );
        } else {
            wp_send_json_success( [ 'parts' => [] ] );
        }
    }

    public function ajax_upload_cancel() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( __( 'Permission denied', 'WP-Res' ) );
        if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'wp_backup_action' ) ) wp_send_json_error( __( 'Invalid request', 'WP-Res' ) );

        $filename = isset( $_POST['filename'] ) ? sanitize_file_name( $_POST['filename'] ) : '';
        $file_size = isset( $_POST['file_size'] ) ? (string) $_POST['file_size'] : '0';
        if ( empty( $filename ) ) wp_send_json_error( __( 'Invalid parameters', 'WP-Res' ) );

        $temp_dir = $this->get_backup_dir() . '/upload_temp';
        $task_id = md5( $filename . $file_size . get_current_user_id() );
        $pattern = $temp_dir . '/' . $task_id . '_*';
        foreach ( glob( $pattern ) as $file ) @unlink( $file );
        @unlink( $temp_dir . '/' . $task_id . '_meta.json' );

        wp_send_json_success( [ 'message' => __( 'Upload cancelled and temporary files cleaned', 'WP-Res' ) ] );
    }

    // ==================== UI ====================

    public function enqueue_scripts($hook) {
        if ($hook !== 'tools_page_WP-Res') return;
        wp_enqueue_script( 'jquery' );
        wp_enqueue_style( 'dashicons' );
        wp_add_inline_style( 'dashicons', '
            .wp-backup-card { background:#fff; padding:25px 30px; border-radius:12px; box-shadow:0 2px 8px rgba(0,0,0,0.05); margin-top:20px; }
            .log-area { background:#f6f7f7; padding:12px 16px; border-radius:8px; border-left:4px solid #2271b1; }
            .log-area code { background: #eaeef2; padding:2px 6px; border-radius:4px; }
            .copy-path-btn { cursor:pointer; margin-left:8px; color:#2271b1; text-decoration:none; }
            .restore-danger-btn {
                background: #d63638 !important;
                border-color: #d63638 !important;
                color: #fff !important;
            }
            .restore-danger-btn:hover {
                background: #b32d2e !important;
                border-color: #b32d2e !important;
                color: #fff !important;
                box-shadow: 0 1px 2px rgba(0,0,0,0.1);
            }
        ' );

        // 本地化脚本，传递翻译字符串
        $l10n = array(
            'api_url'          => plugins_url( 'restore-api.php', __FILE__ ),
            'ajax_url'         => admin_url( 'admin-ajax.php' ),
            'current_level'    => $this->log_level,
            'nonce'            => wp_create_nonce( 'wp_backup_action' ),
            // UI 文本
            'no_backup_selected' => __( 'Please select a backup file', 'WP-Res' ),
            'delete_confirm'     => __( 'Are you sure you want to delete this backup file? This cannot be undone!', 'WP-Res' ),
            'only_bgbk_allowed'  => __( 'Only .bgbk backup files are allowed.', 'WP-Res' ),
            'upload_cancel_confirm' => __( 'Are you sure you want to cancel the upload and clean up temporary files?', 'WP-Res' ),
            'restore_confirm'    => __( '⚠️ This operation will overwrite existing data and is irreversible! Continue?', 'WP-Res' ),
            'disk_space_check'   => __( 'Disk Space Check', 'WP-Res' ),
            'calculating_space'  => __( 'Calculating disk space, please wait...<br><span style="font-size:12px;">(May take a few seconds if the site has many files)</span>', 'WP-Res' ),
            'backup_title'       => __( 'Full Site Backup in Progress', 'WP-Res' ),
            'restore_title'      => __( 'Full Site Restore in Progress', 'WP-Res' ),
            'backup_complete'    => __( '✔ Backup completed successfully!', 'WP-Res' ),
            'restore_complete'   => __( '✔ Restore completed successfully!', 'WP-Res' ),
            'backup_complete_msg' => __( 'All site files and database have been backed up.', 'WP-Res' ),
            'restore_complete_msg' => __( 'All site data has been restored.<br><span style="color:#d63638;">Note: The database has been updated. Please refresh the page; you may need to log in again.</span>', 'WP-Res' ),
            'error_occurred'     => __( '❌ Error: ', 'WP-Res' ),
            'task_stopped'       => __( 'Task stopped manually, temporary files cleaned.', 'WP-Res' ),
            'stopping_task'      => __( '⏸️ Stopping task and cleaning temporary files...', 'WP-Res' ),
            'task_maybe_stopping' => __( 'Task may still be stopping, please refresh manually later.', 'WP-Res' ),
            'stop_failed'        => __( 'Stop request failed: ', 'WP-Res' ),
            'network_error'      => __( 'Network error, please check connection', 'WP-Res' ),
            'unknown_error'      => __( 'Unknown error', 'WP-Res' ),
            'check_failed'       => __( 'Check failed: ', 'WP-Res' ),
            'request_failed'     => __( 'Request failed, please check network connection', 'WP-Res' ),
            'delete_success'     => __( 'Deleted successfully', 'WP-Res' ),
            'delete_failed'      => __( 'Deletion failed: ', 'WP-Res' ),
            'upload_success'     => __( '✓ Upload successful, refreshing page...', 'WP-Res' ),
            'upload_failed'      => __( '✗ Upload failed: ', 'WP-Res' ),
            'upload_cancelled'   => __( 'Upload cancelled, temporary files cleaned', 'WP-Res' ),
            'upload_cancel_failed' => __( 'Cancel failed: ', 'WP-Res' ),
            'preparing_upload'   => __( 'Preparing upload, dynamic chunk size ', 'WP-Res' ),
            'remaining_intervals' => __( 'remaining intervals', 'WP-Res' ),
            'chunk_upload_success' => __( 'Chunk upload successful (', 'WP-Res' ),
            'chunk_upload_failed' => __( 'Chunk upload failed, retrying (', 'WP-Res' ),
            'retry_attempt'       => __( 'attempt', 'WP-Res' ),
            'network_good'       => __( 'Network good, chunk size increased to ', 'WP-Res' ),
            'chunk_too_large'    => __( 'Chunk too large, reduced to ', 'WP-Res' ),
            'copy_path_success'  => __( 'Path copied', 'WP-Res' ),
            'log_level_saved'    => __( 'Saved', 'WP-Res' ),
            'log_level_save_failed' => __( 'Save failed', 'WP-Res' ),
            'button_backup'      => __( 'Backup Now', 'WP-Res' ),
            'button_download'    => __( 'Download', 'WP-Res' ),
            'button_delete'      => __( 'Delete', 'WP-Res' ),
            'button_upload'      => __( 'Upload Backup', 'WP-Res' ),
            'button_cancel_upload' => __( 'Cancel Upload', 'WP-Res' ),
            'button_restore'     => __( 'Full Site Restore', 'WP-Res' ),
            'log_label'          => __( '📋 Debug Log Level:', 'WP-Res' ),
            'log_file_info'      => __( '📁 Log file: ', 'WP-Res' ),
            'copy_path'          => __( '📋 Copy path', 'WP-Res' ),
            'modal_title_processing' => __( 'Processing Task', 'WP-Res' ),
            'modal_button_stop'  => __( 'Stop Task', 'WP-Res' ),
            'modal_button_cancel' => __( 'Cancel', 'WP-Res' ),
            'modal_button_confirm' => __( 'Confirm', 'WP-Res' ),
            'modal_button_continue' => __( 'Continue', 'WP-Res' ),
            'modal_button_continue_risk' => __( 'Continue Anyway (Risk Assumed)', 'WP-Res' ),
            'space_check_loading' => __( 'Disk Space Check', 'WP-Res' ),
            'space_check_msg'    => __( 'Calculating disk space, please wait...<br><span style="font-size:12px;">(May take a few seconds if the site has many files)</span>', 'WP-Res' ),
        );
        wp_localize_script( 'jquery', 'wp_backup_l10n', $l10n );
    }

    public function add_admin_menu() {
        add_management_page( __( 'WP Backup Restore', 'WP-Res' ), __( 'WP Backup Restore', 'WP-Res' ), 'manage_options', 'WP-Res', array( $this, 'admin_page' ) );
    }

    public function admin_page() {
        $backups = $this->get_backup_list();
        ?>
        <div class="wrap">
            <h1 style="font-weight:600; margin-bottom:24px;"><?php _e( '🔄 WP Backup Restore', 'WP-Res' ); ?></h1>
            <div class="wp-backup-card">
                <div style="margin-bottom:20px;">
                    <button id="btn-bak" class="button button-primary button-large" style="display:inline-flex; align-items:center; gap:6px;">
                        <span class="dashicons dashicons-backup"></span> <?php _e( 'Backup Now', 'WP-Res' ); ?>
                    </button>
                </div>
                <div style="margin-bottom:12px; display:flex; flex-wrap:wrap; align-items:center; gap:12px;">
                    <select id="sel-bak" style="min-width:260px; max-width:100%;">
                        <?php if(empty($backups)): ?>
                            <option value=""><?php _e( 'No backup files', 'WP-Res' ); ?></option>
                        <?php else: ?>
                            <?php foreach($backups as $f): ?>
                                <option value="<?php echo esc_attr($f); ?>"><?php echo esc_html(basename($f)); ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <button id="btn-download" class="button button-secondary" style="display:inline-flex; align-items:center; gap:6px;">
                        <span class="dashicons dashicons-download"></span> <?php _e( 'Download', 'WP-Res' ); ?>
                    </button>
                    <button id="btn-delete" class="button button-secondary button-danger" style="display:inline-flex; align-items:center; gap:6px;">
                        <span class="dashicons dashicons-trash"></span> <?php _e( 'Delete', 'WP-Res' ); ?>
                    </button>
                </div>
                <div style="margin-bottom:20px;">
                    <button id="btn-upload" class="button button-secondary" style="display:inline-flex; align-items:center; gap:6px;">
                        <span class="dashicons dashicons-upload"></span> <?php _e( 'Upload Backup', 'WP-Res' ); ?>
                    </button>
                    <button id="btn-cancel-upload" class="button button-secondary button-danger" style="display:inline-flex; align-items:center; gap:6px; margin-left:10px; display:none;">
                        <span class="dashicons dashicons-no-alt"></span> <?php _e( 'Cancel Upload', 'WP-Res' ); ?>
                    </button>
                    <input type="file" id="upload-file-input" accept=".bgbk" style="display:none;">
                    <div id="upload-progress-container" style="display:none; margin-top:10px;">
                        <div style="background:#f0f0f0; height:20px; border-radius:10px; overflow:hidden; width:100%; max-width:400px;">
                            <div id="upload-progress-bar" style="background:#2271b1; width:0%; height:100%; transition:width 0.3s; text-align:center; color:#fff; line-height:20px; font-size:12px;">0%</div>
                        </div>
                        <div id="upload-status" style="margin-top:5px; font-size:12px; color:#555;"></div>
                    </div>
                </div>
                <div style="margin-bottom:20px;">
                    <button id="btn-res" class="button restore-danger-btn" style="display:inline-flex; align-items:center; gap:6px;">
                        <span style="font-size:1.2em;">↩️</span> <?php _e( 'Full Site Restore', 'WP-Res' ); ?>
                    </button>
                </div>
                <hr style="margin:20px 0;">
                <div class="log-area">
                    <label><strong><?php _e( '📋 Debug Log Level:', 'WP-Res' ); ?></strong></label>
                    <select id="log-level" style="margin-left:12px; border-radius:4px;">
                        <option value="OFF" <?php selected( $this->log_level, 'OFF' ); ?>><?php _e( 'OFF', 'WP-Res' ); ?></option>
                        <option value="ERROR" <?php selected( $this->log_level, 'ERROR' ); ?>><?php _e( 'ERROR', 'WP-Res' ); ?></option>
                        <option value="WARNING" <?php selected( $this->log_level, 'WARNING' ); ?>><?php _e( 'WARNING', 'WP-Res' ); ?></option>
                        <option value="INFO" <?php selected( $this->log_level, 'INFO' ); ?>><?php _e( 'INFO', 'WP-Res' ); ?></option>
                        <option value="DEBUG" <?php selected( $this->log_level, 'DEBUG' ); ?>><?php _e( 'DEBUG', 'WP-Res' ); ?></option>
                    </select>
                    <span id="log-save-status" style="margin-left:12px; color:#00a32a;"></span>
                    <p class="description" style="margin-top:10px;">
                        <?php _e( '📁 Log file:', 'WP-Res' ); ?> <code><?php echo esc_html( $this->get_backup_dir() . '/restore.log' ); ?></code>
                        <a href="javascript:void(0);" id="copy-log-path" class="copy-path-btn"><?php _e( '📋 Copy path', 'WP-Res' ); ?></a>
                    </p>
                </div>
            </div>
        </div>

        <div id="modal-progress" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:100000; text-align:center; padding-top:150px;">
            <div style="background:#fff; width:500px; margin:0 auto; padding:25px 30px 30px; border-radius:12px; box-shadow:0 5px 20px rgba(0,0,0,0.3); text-align:left;">
                <h3 id="modal-title" style="margin:0 0 20px; font-size:18px; border-bottom:1px solid #ddd; padding-bottom:10px;"><?php _e( 'Processing Task', 'WP-Res' ); ?></h3>
                <div style="background:#f0f0f0; height:32px; border-radius:16px; overflow:hidden; margin:20px 0; position:relative;">
                    <div id="progress-bar" style="background:#0073aa; width:0%; height:100%; transition:width 0.3s; color:#fff; line-height:32px; text-align:center; font-weight:bold;">0%</div>
                </div>
                <div id="progress-msg" style="font-size:14px; color:#555; margin:10px 0 20px; word-break:break-word;"><?php _e( 'Preparing...', 'WP-Res' ); ?></div>
                <div style="text-align:right;">
                    <button id="stop-btn" class="button button-secondary" style="margin-right:10px;"><?php _e( 'Stop Task', 'WP-Res' ); ?></button>
                    <button id="cancel-modal-btn" class="button button-secondary modal-cancel-btn" style="display:none;"><?php _e( 'Cancel', 'WP-Res' ); ?></button>
                    <button id="confirm-btn" class="button button-primary" style="display:none;"><?php _e( 'Confirm', 'WP-Res' ); ?></button>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var l10n = wp_backup_l10n;
            var apiUrl = l10n.api_url;
            var ajaxUrl = l10n.ajax_url;
            var nonce = l10n.nonce;
            var currentTaskId = null;
            var currentType = null;
            var pollInterval = null;
            var isStopping = false;

            // 显示等待模态窗（用于空间检查时的 Loading）
            function showWaitingModal() {
                resetConfirmButton();
                $('#modal-title').text(l10n.space_check_loading);
                $('#progress-bar').hide();
                $('#progress-msg').html(l10n.space_check_msg);
                $('#stop-btn').hide();
                $('#confirm-btn').hide();
                $('#cancel-modal-btn').hide();
                $('#modal-progress').show();
            }

            // 通用模态窗确认（用于显示检查结果）
            function showModalWithConfirm(title, message, canProceed, onConfirm, extraWarning) {
                resetConfirmButton();
                $('#modal-title').text(title);
                $('#progress-bar').hide();               // 空间检查时不需要进度条
                $('#progress-msg').html(message.replace(/\n/g, '<br>') + (extraWarning ? '<br><br><span style="color:#d63638;">⚠️ ' + l10n.extra_risk_warning + '</span><br>' + extraWarning : ''));
                $('#stop-btn').hide();
                $('#cancel-modal-btn').show().text(l10n.modal_button_cancel).off('click').click(closeModal);
                if (canProceed) {
                    $('#confirm-btn').show().text(l10n.modal_button_continue).off('click').click(function() {
                        closeModal();
                        onConfirm();
                    });
                } else {
                    $('#confirm-btn').show().text(l10n.modal_button_continue_risk).off('click').click(function() {
                        closeModal();
                        onConfirm();
                    });
                }
                $('#modal-progress').show();
            }

            // 备份按钮：先显示等待，再检查空间
            $('#btn-bak').click(function() {
                showWaitingModal();
                $.post(ajaxUrl, {
                    action: 'wp_backup_check_space',
                    check_type: 'backup',
                    _wpnonce: nonce
                }, function(res) {
                    closeModal(); // 关闭等待模态窗
                    if (res.success) {
                        var data = res.data;
                        var msg = data.message + '\n' + l10n.available_space + '：' + data.free + '\n' + l10n.required_space + '：' + data.required;
                        showModalWithConfirm(l10n.disk_space_check, msg, data.enough, function() {
                            startTask('backup_start', {}, l10n.backup_title);
                        }, data.zip64_warning);
                    } else {
                        alert(l10n.check_failed + (res.data || l10n.unknown_error));
                    }
                }, 'json').fail(function() {
                    closeModal();
                    alert(l10n.request_failed);
                });
            });

            // 还原按钮：先显示等待，再检查空间
            $('#btn-res').click(function() {
                var file = $('#sel-bak').val();
                if (!file) {
                    showModal(l10n.no_backup_selected, true);
                    $('#progress-msg').html(l10n.select_backup_first);
                    $('#confirm-btn').hide();
                    $('#cancel-modal-btn').show();
                    return;
                }
                showWaitingModal();
                $.post(ajaxUrl, {
                    action: 'wp_backup_check_space',
                    check_type: 'restore',
                    backup_file: file,
                    _wpnonce: nonce
                }, function(res) {
                    closeModal();
                    if (res.success) {
                        var data = res.data;
                        var msg = data.message + '\n' + l10n.available_space + '：' + data.free + '\n' + l10n.required_space + '：' + data.required;
                        showModalWithConfirm(l10n.disk_space_check, msg, data.enough, function() {
                            // 继续原有还原确认流程
                            showModal(l10n.restore_confirm, true);
                            $('#progress-msg').html(l10n.restore_confirm_msg);
                            $('#confirm-btn').one('click', function() {
                                closeModal();
                                startTask('restore_start', {
                                    backup_file: file,
                                    browser_cookie: document.cookie,
                                    current_url: window.location.origin
                                }, l10n.restore_title);
                            });
                            $('#cancel-modal-btn').one('click', closeModal);
                        }, data.zip64_warning);
                    } else {
                        alert(l10n.check_failed + (res.data || l10n.unknown_error));
                    }
                }, 'json').fail(function() {
                    closeModal();
                    alert(l10n.request_failed);
                });
            });

            // ========== 以下为下载、删除、上传、备份还原轮询等 ==========
            $('#btn-download').click(function(e) {
                e.preventDefault();
                var file = $('#sel-bak').val();
                if (!file) { alert(l10n.no_backup_selected); return; }
                window.location.href = ajaxUrl + '?action=wp_backup_download&file=' + encodeURIComponent(file) + '&_wpnonce=' + nonce;
            });

            $('#btn-delete').click(function() {
                var file = $('#sel-bak').val();
                if (!file) { alert(l10n.no_backup_selected); return; }
                if (!confirm(l10n.delete_confirm)) return;
                $.post(ajaxUrl, { action: 'wp_backup_delete', file: file, _wpnonce: nonce }, function(res) {
                    if (res.success) { alert(l10n.delete_success); location.reload(); }
                    else { alert(l10n.delete_failed + (res.data || res.message || l10n.unknown_error)); }
                }, 'json').fail(function() { alert(l10n.request_failed); });
            });

            // ========== 动态分片上传 ==========
            let currentFile = null;
            let currentChunkSize = 2 * 1024 * 1024;
            let minChunkSize = 512 * 1024;
            let maxChunkSize = 10 * 1024 * 1024;
            let consecutiveSuccess = 0;
            let uploadedParts = [];
            let isUploading = false;
            let maxRetries = 5;

            function sleep(ms) { return new Promise(resolve => setTimeout(resolve, ms)); }

            async function uploadChunkWithRetry(formData, start, attempt = 1) {
                return new Promise((resolve, reject) => {
                    const xhr = new XMLHttpRequest();
                    xhr.open('POST', ajaxUrl, true);
                    xhr.timeout = 60000;
                    xhr.onload = function() {
                        if (xhr.status === 200) {
                            try {
                                const res = JSON.parse(xhr.responseText);
                                if (res.success) {
                                    consecutiveSuccess++;
                                    if (consecutiveSuccess >= 3 && currentChunkSize < maxChunkSize) {
                                        currentChunkSize = Math.min(currentChunkSize * 2, maxChunkSize);
                                        $('#upload-status').append('<br><small>' + l10n.network_good + (currentChunkSize/1024/1024).toFixed(1) + 'MB</small>');
                                        consecutiveSuccess = 0;
                                    }
                                    resolve(res);
                                } else {
                                    reject(new Error(res.data || l10n.upload_failed));
                                }
                            } catch(e) { reject(e); }
                        } else if (xhr.status === 413) {
                            currentChunkSize = Math.max(currentChunkSize / 2, minChunkSize);
                            $('#upload-status').html('<span style="color:#d63638;">' + l10n.chunk_too_large + (currentChunkSize/1024/1024).toFixed(1) + 'MB, retrying...</span>');
                            reject(new Error('Chunk too large'));
                        } else {
                            reject(new Error('HTTP ' + xhr.status));
                        }
                    };
                    xhr.onerror = () => reject(new Error(l10n.network_error));
                    xhr.ontimeout = () => reject(new Error(l10n.upload_timeout));
                    xhr.send(formData);
                });
            }

            async function uploadChunkWithBackoff(formData, start, end) {
                let delay = 1000;
                for (let attempt = 1; attempt <= maxRetries; attempt++) {
                    try {
                        return await uploadChunkWithRetry(formData, start, attempt);
                    } catch (error) {
                        if (attempt === maxRetries) throw error;
                        const wait = delay * Math.pow(2, attempt - 1);
                        $('#upload-status').html(`<span style="color:#d63638;">${l10n.chunk_upload_failed} ${start}-${end}, retry in ${wait/1000}s (${attempt}/${maxRetries})...</span>`);
                        await sleep(wait);
                    }
                }
            }

            function mergeIntervals(intervals) {
                if (intervals.length === 0) return [];
                intervals.sort((a, b) => a.start - b.start);
                let merged = [intervals[0]];
                for (let i = 1; i < intervals.length; i++) {
                    let last = merged[merged.length - 1];
                    let curr = intervals[i];
                    if (curr.start <= last.end) {
                        last.end = Math.max(last.end, curr.end);
                    } else {
                        merged.push(curr);
                    }
                }
                return merged;
            }

            function getRemainingIntervals(fileSize, uploaded) {
                let merged = mergeIntervals(uploaded);
                let remaining = [];
                let cursor = 0;
                for (let i = 0; i < merged.length; i++) {
                    if (cursor < merged[i].start) {
                        remaining.push({ start: cursor, end: merged[i].start });
                    }
                    cursor = merged[i].end;
                }
                if (cursor < fileSize) {
                    remaining.push({ start: cursor, end: fileSize });
                }
                return remaining;
            }

            async function getUploadedParts(filename, fileSize) {
                return new Promise((resolve, reject) => {
                    $.post(ajaxUrl, {
                        action: 'wp_backup_upload_status',
                        filename: filename,
                        file_size: fileSize,
                        _wpnonce: nonce
                    }, function(res) {
                        if (res.success) resolve(res.data.parts || []);
                        else reject(new Error(res.data));
                    }, 'json').fail(() => reject(new Error(l10n.upload_status_failed)));
                });
            }

            async function cancelUpload(filename, fileSize) {
                return new Promise((resolve, reject) => {
                    $.post(ajaxUrl, {
                        action: 'wp_backup_upload_cancel',
                        filename: filename,
                        file_size: fileSize,
                        _wpnonce: nonce
                    }, function(res) {
                        if (res.success) resolve();
                        else reject(new Error(res.data));
                    }, 'json').fail(() => reject(new Error(l10n.upload_cancel_failed)));
                });
            }

            async function uploadFileInChunks(file) {
                currentFile = file;
                isUploading = true;
                $('#btn-cancel-upload').show();
                try {
                    const uploaded = await getUploadedParts(file.name, file.size);
                    uploadedParts = uploaded;
                    let remainingIntervals = getRemainingIntervals(file.size, uploadedParts);
                    const totalBytes = file.size;
                    let uploadedBytes = uploadedParts.reduce((sum, p) => sum + (p.end - p.start), 0);
                    let initialPercent = Math.round((uploadedBytes / totalBytes) * 100);

                    $('#upload-progress-container').show();
                    $('#upload-progress-bar').css('width', initialPercent + '%').text(initialPercent + '%');
                    $('#upload-status').html(l10n.preparing_upload + (currentChunkSize/1024/1024).toFixed(1) + 'MB, ' + remainingIntervals.length + ' ' + l10n.remaining_intervals);

                    for (let interval of remainingIntervals) {
                        if (!isUploading) break;
                        let start = interval.start;
                        while (start < interval.end && isUploading) {
                            let chunkEnd = Math.min(start + currentChunkSize, interval.end);
                            const chunk = file.slice(start, chunkEnd);
                            const formData = new FormData();
                            formData.append('action', 'wp_backup_upload_chunk');
                            formData.append('file_chunk', chunk);
                            formData.append('filename', file.name);
                            formData.append('start', start);
                            formData.append('end', chunkEnd);
                            formData.append('file_size', file.size);
                            formData.append('_wpnonce', nonce);
                            try {
                                const response = await uploadChunkWithBackoff(formData, start, chunkEnd);
                                // 判断是否完成合并
                                if (response && response.data && response.data.status === 'success') {
                                    isUploading = false;
                                    $('#upload-progress-bar').css('width', '100%').text('100%');
                                    $('#upload-status').html('<span style="color:green;">' + l10n.upload_success + '</span>');
                                    setTimeout(() => location.reload(), 1000);
                                    return;
                                }
                                // 未完成，更新进度
                                uploadedParts.push({ start: start, end: chunkEnd });
                                uploadedParts = mergeIntervals(uploadedParts);
                                const uploadedNow = uploadedParts.reduce((sum, p) => sum + (p.end - p.start), 0);
                                const percent = Math.round((uploadedNow / totalBytes) * 100);
                                $('#upload-progress-bar').css('width', percent + '%').text(percent + '%');
                                $('#upload-status').text(l10n.chunk_upload_success + start + '-' + chunkEnd + ') ' + percent + '%');
                                start = chunkEnd;
                            } catch (error) {
                                throw new Error(l10n.upload_failed + error.message);
                            }
                        }
                    }

                    // 所有分片处理完毕，最后检查一次状态（防止合并后未及时返回）
                    const finalParts = await getUploadedParts(file.name, file.size);
                    const finalMerged = mergeIntervals(finalParts);
                    const totalCovered = finalMerged.reduce((sum, p) => sum + (p.end - p.start), 0);
                    if (totalCovered >= file.size) {
                        $('#upload-progress-bar').css('width', '100%').text('100%');
                        $('#upload-status').html('<span style="color:green;">' + l10n.upload_success + '</span>');
                        setTimeout(() => location.reload(), 1000);
                    } else {
                        throw new Error(l10n.upload_incomplete);
                    }
                } catch (error) {
                    $('#upload-status').html(l10n.upload_failed + error.message);
                    setTimeout(() => $('#upload-progress-container').hide(), 3000);
                } finally {
                    isUploading = false;
                    $('#btn-cancel-upload').hide();
                }
            }

            $('#btn-cancel-upload').click(async function() {
                if (!currentFile || !isUploading) return;
                if (confirm(l10n.upload_cancel_confirm)) {
                    isUploading = false;
                    $('#upload-status').html(l10n.upload_cancelling);
                    try {
                        await cancelUpload(currentFile.name, currentFile.size);
                        $('#upload-status').html(l10n.upload_cancelled);
                        setTimeout(() => $('#upload-progress-container').hide(), 2000);
                    } catch (error) {
                        $('#upload-status').html(l10n.upload_cancel_failed + error.message);
                    }
                    $('#btn-cancel-upload').hide();
                }
            });

            $('#btn-upload').click(function() { $('#upload-file-input').trigger('click'); });
            $('#upload-file-input').change(function() {
                const file = this.files[0];
                if (!file) return;
                if (!file.name.endsWith('.bgbk')) { alert(l10n.only_bgbk_allowed); return; }
                uploadFileInChunks(file);
            });

            // ========== 备份/还原轮询逻辑（保持不变） ==========
            function resetConfirmButton() {
                $('#confirm-btn').off('click').click(closeModal);
                $('#cancel-modal-btn').off('click').click(closeModal);
            }
            function showModal(title, showCancel = false) {
                resetConfirmButton();
                $('#modal-title').text(title);
                $('#progress-bar').css('width', '0%').text('0%').show();
                $('#progress-msg').text(l10n.preparing);
                $('#stop-btn').hide();
                $('#confirm-btn').hide();
                $('#cancel-modal-btn').hide();
                if (showCancel) {
                    $('#cancel-modal-btn').show().text(l10n.modal_button_cancel);
                    $('#confirm-btn').show().text(l10n.modal_button_confirm);
                } else {
                    $('#stop-btn').show().prop('disabled', false);
                }
                $('#modal-progress').show();
            }
            function closeModal() {
                $('#modal-progress').hide();
                if (pollInterval) clearInterval(pollInterval);
                currentTaskId = null;
                currentType = null;
                isStopping = false;
                resetConfirmButton();
            }
            function stopTask() {
                if (!currentTaskId || isStopping) return;
                isStopping = true;
                $('#stop-btn').prop('disabled', true).text(l10n.stopping);
                $.post(apiUrl, { api_type: 'stop_task', task_id: currentTaskId }, function(res) {
                    if (res.success) {
                        $('#progress-msg').html(l10n.stopping_task);
                        if (pollInterval) clearInterval(pollInterval);
                        setTimeout(function() {
                            $.get(apiUrl, { api_type: currentType + '_status', task_id: currentTaskId }, function(res) {
                                var d = res.data;
                                if (d.status === 'error' || d.status === 'stopped') {
                                    $('#progress-msg').html(l10n.task_stopped);
                                    $('#stop-btn').hide();
                                    $('#confirm-btn').show();
                                    resetConfirmButton();
                                } else {
                                    $('#progress-msg').html(l10n.task_maybe_stopping);
                                    $('#stop-btn').hide();
                                    $('#confirm-btn').show();
                                    resetConfirmButton();
                                }
                            });
                        }, 2000);
                    } else {
                        $('#progress-msg').html(l10n.stop_failed + (res.message || l10n.unknown_error));
                        $('#stop-btn').prop('disabled', false).text(l10n.modal_button_stop);
                        isStopping = false;
                    }
                }, 'json');
            }
            function pollStatus(taskId, type) {
                if (pollInterval) clearInterval(pollInterval);
                pollInterval = setInterval(function() {
                    $.get(apiUrl, { api_type: type + '_status', task_id: taskId }, function(res) {
                        if (!res.success) return;
                        var d = res.data;
                        var percent = d.percent || 0;
                        $('#progress-bar').css('width', percent + '%').text(percent + '%');
                        $('#progress-msg').html(d.message || l10n.processing);
                        if (d.status === 'done' || percent >= 100) {
                            clearInterval(pollInterval);
                            $('#progress-bar').css('background', '#46b450').text('100%');
                            var msgHtml = (type === 'backup') ?
                                '<div style="margin-top:10px; padding:10px; background:#f0fff0; border:1px solid #46b450; border-radius:4px;"><b style="color:#46b450;">' + l10n.backup_complete + '</b><br><span style="color:#666;">' + l10n.backup_complete_msg + '</span></div>' :
                                '<div style="margin-top:10px; padding:10px; background:#f0fff0; border:1px solid #46b450; border-radius:4px;"><b style="color:#46b450;">' + l10n.restore_complete + '</b><br><span style="color:#666;">' + l10n.restore_complete_msg + '</span></div>';
                            $('#progress-msg').html(msgHtml);
                            $('#stop-btn').hide();
                            $('#confirm-btn').show().text(l10n.modal_button_confirm_refresh).off('click').on('click', function() {
                                location.href = window.location.origin + '/wp-admin/tools.php?page=WP-Res';
                            });
                        } else if (d.status === 'error') {
                            clearInterval(pollInterval);
                            $('#progress-msg').html(l10n.error_occurred + (d.message || l10n.unknown_error));
                            $('#stop-btn').hide();
                            $('#confirm-btn').show().text(l10n.modal_button_confirm).off('click').click(closeModal);
                        } else if (d.status === 'stopped') {
                            clearInterval(pollInterval);
                            $('#progress-msg').html(l10n.task_stopped);
                            $('#stop-btn').hide();
                            $('#confirm-btn').show().text(l10n.modal_button_confirm).off('click').click(closeModal);
                        }
                    });
                }, 2000);
            }
            function startTask(apiType, params, title) {
                showModal(title);
                $.post(apiUrl, $.extend({ api_type: apiType }, params), function(res) {
                    if (res.success) {
                        currentTaskId = res.data.task_id;
                        currentType = (apiType === 'backup_start' ? 'backup' : 'restore');
                        pollStatus(currentTaskId, currentType);
                    } else {
                        $('#progress-msg').html(l10n.start_failed + (res.message || l10n.unknown_error));
                        $('#stop-btn').hide();
                        $('#confirm-btn').show();
                        resetConfirmButton();
                    }
                }, 'json');
            }

            $('#stop-btn').click(stopTask);
            $('#confirm-btn').click(closeModal);
            $('#cancel-modal-btn').click(closeModal);

            $('#log-level').change(function() {
                var level = $(this).val();
                $.post(ajaxUrl, { action: 'save_log_level', level: level }, function(res) {
                    if (res.success) {
                        $('#log-save-status').text(l10n.log_level_saved).fadeOut(1500, function() { $(this).text('').show(); });
                    } else {
                        $('#log-save-status').text(l10n.log_level_save_failed).css('color','red').fadeOut(1500, function() { $(this).text('').css('color','#00a32a').show(); });
                    }
                });
            });
            $('#copy-log-path').click(function() {
                var text = $('.log-area code').text();
                navigator.clipboard.writeText(text).then(function() { alert(l10n.copy_path_success); });
            });
        });
        </script>
        <?php
    }
}
new WP_Backup_Restore_Active();
