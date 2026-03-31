<?php
/**
 * Plugin Name: WP一键备份还原
 * Description: 批量处理、兼容序列化的安全域名替换、Session保持、严格目录排除。提供现代UI、备份文件管理、分片上传（动态分片、断点续传、指数退避重试）。
 * Version: 1.0.1
 * Author: BG Tech
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
            $this->log_message( "日志文件超过512KB已清空", 'INFO' );
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
        if ( ! current_user_can( 'manage_options' ) ) wp_die( '无权限' );
        $level = isset( $_POST['level'] ) ? sanitize_text_field( $_POST['level'] ) : 'INFO';
        $allowed = array( 'OFF', 'ERROR', 'WARNING', 'INFO', 'DEBUG' );
        if ( in_array( $level, $allowed ) ) {
            update_option( 'wp_backup_log_level', $level );
            $this->log_level = $level;
            $this->log_message( "日志级别已更改为: $level", 'INFO' );
            wp_send_json_success( array( 'level' => $level ) );
        } else {
            wp_send_json_error( '无效级别' );
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
        $this->log_message( "已清理旧任务残留文件", 'INFO' );
    }
    
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
            $this->update_worker_state( $task_id, ['status' => 'processing', 'message' => '扫描全站文件...', 'percent' => 5] );
            $files = $this->get_file_list( ABSPATH, $backup_dir );
            $total_files = count($files);
            $temp_zip = $temp_dir . '/' . $backup_name . '.part';
            $zip = new ZipArchive();
            if ($zip->open( $temp_zip, ZipArchive::CREATE ) !== true) throw new Exception("无法创建压缩包");
            foreach ( $files as $i => $f ) {
                if ( $this->should_stop($task_id) ) throw new Exception("任务已被用户停止");
                if ( is_file( $f ) ) $zip->addFile( $f, str_replace( rtrim(ABSPATH, '/\\') . '/', '', $f ) );
                if ( $i % 1000 == 0 ) {
                    $p = 5 + round(($i / $total_files) * 50);
                    $this->update_worker_state( $task_id, ['message' => "打包文件进度: $i/$total_files", 'percent' => $p] );
                }
            }
            $zip->close();
            $this->update_worker_state( $task_id, ['message' => '开始导出数据库...', 'percent' => 60] );
            $sql_file = $temp_dir . '/database.sql';
            $this->export_db_streaming( $sql_file, $task_id );
            $zip->open( $temp_zip );
            $zip->addFile( $sql_file, 'database.sql' );
            $zip->addFromString( 'siteinfo.json', json_encode( ['siteurl' => home_url()] ) );
            $zip->close();
            rename( $temp_zip, $backup_dir . '/' . $backup_name );
            $this->remove_directory( $temp_dir );
            $this->update_worker_state( $task_id, ['status' => 'done', 'percent' => 100, 'message' => '备份圆满完成！'] );
        } catch ( Exception $e ) {
            $this->update_worker_state( $task_id, ['status' => 'error', 'message' => $e->getMessage()] );
        }
    }
    
    public function do_full_restore( $task_id, $params ) {
        set_time_limit( 0 );
        @ini_set( 'memory_limit', '512M' );
        $backup_file = isset($params['backup_file']) ? $params['backup_file'] : '';
        $current_site_url = isset($params['current_url']) ? untrailingslashit($params['current_url']) : untrailingslashit(home_url());
        $root_path = rtrim(ABSPATH, '/\\') . '/';
        $this->log_message( "=== 还原开始 ===", 'INFO' );
        $this->log_message( "任务ID: $task_id", 'INFO' );
        $this->log_message( "备份文件: $backup_file", 'INFO' );
        $this->log_message( "当前目标域名: $current_site_url", 'INFO' );
        try {
            if (empty($backup_file) || !file_exists($backup_file)) throw new Exception("备份文件不存在");
            $this->update_worker_state( $task_id, ['status' => 'processing', 'message' => '正在解压全站文件...', 'percent' => 10] );
            $zip = new ZipArchive();
            if ($zip->open($backup_file) === true) {
                $this->log_message( "开始解压文件，共 " . $zip->numFiles . " 个", 'INFO' );
                for ($i = 0; $i < $zip->numFiles; $i++) {
                    if ($this->should_stop($task_id)) throw new Exception("任务被停止");
                    $filename = $zip->getNameIndex($i);
                    $zip->extractTo($root_path, $filename);
                    if ($i % 500 == 0) {
                        $p = 10 + round(($i / $zip->numFiles) * 30);
                        $this->update_worker_state( $task_id, ['message' => "解压文件: $i/" . $zip->numFiles, 'percent' => $p] );
                    }
                }
                $zip->close();
                $this->log_message( "解压完成", 'INFO' );
            } else {
                throw new Exception("无法打开备份文件");
            }
            $sql_file = $root_path . 'database.sql';
            if (file_exists($sql_file)) {
                $prefix = 'tmp_' . uniqid() . '_';
                $this->update_worker_state( $task_id, ['message' => '准备批量导入数据库...', 'percent' => 45] );
                $this->import_sql_streaming_v2( $sql_file, $prefix, $task_id );
                $this->update_worker_state( $task_id, ['message' => '正在原子替换表结构...', 'percent' => 85] );
                $this->swap_temp_tables( $prefix );
                @unlink($sql_file);
                $this->log_message( "数据库导入并切换完成", 'INFO' );
            }
            $siteinfo = $root_path . 'siteinfo.json';
            if (file_exists($siteinfo)) {
                $info = json_decode(file_get_contents($siteinfo), true);
                if (isset($info['siteurl'])) {
                    $old_url = untrailingslashit($info['siteurl']);
                    if ($old_url !== $current_site_url) {
                        $this->update_worker_state( $task_id, ['message' => "执行兼容性域名替换: $old_url -> $current_site_url"] );
                        $this->replace_domain_safe($old_url, $current_site_url);
                    }
                }
                @unlink($siteinfo);
            }
            if (isset($params['browser_cookie'])) {
                $this->update_worker_state( $task_id, ['message' => '正在修复登录状态...'] );
                $this->preserve_session_enhanced($params['browser_cookie']);
            }
            $this->cleanup_temp_files($task_id);
            $this->update_worker_state( $task_id, ['status' => 'done', 'percent' => 100, 'message' => '全站还原成功！'] );
            $this->log_message( "还原流程全部完成", 'INFO' );
        } catch ( Exception $e ) {
            $this->log_message( "异常: " . $e->getMessage(), 'ERROR' );
            $this->update_worker_state( $task_id, ['status' => 'error', 'message' => $e->getMessage()] );
        }
    }
    
    private function export_db_streaming( $file, $task_id ) {
        global $wpdb;
        $tables = $wpdb->get_results( "SHOW TABLES", ARRAY_N );
        $total = count($tables);
        file_put_contents( $file, "SET FOREIGN_KEY_CHECKS=0;\nSET AUTOCOMMIT=0;\n" );
        foreach ( $tables as $i => $t ) {
            $table = $t[0];
            $this->update_worker_state( $task_id, ['message' => "导出表: $table", 'percent' => 60 + round(($i/$total)*30)] );
            $create = $wpdb->get_row( "SHOW CREATE TABLE `$table`", ARRAY_N );
            file_put_contents( $file, "\nSTART TRANSACTION;\nDROP TABLE IF EXISTS `$table`;\n" . $create[1] . ";\n", FILE_APPEND );
            $offset = 0;
            while ( true ) {
                if ($this->should_stop($task_id)) throw new Exception("停止");
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
        $this->log_message( "SQL 文件总语句数: $total_statements", 'DEBUG' );
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
                        'message' => "数据库还原进度：$q_count / $total_statements 条SQL语句",
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
        $this->log_message( "开始执行 replace_domain_safe: $old -> $new", 'INFO' );
        $sql1 = "UPDATE {$wpdb->options} SET option_value = '$new_esc' WHERE option_name IN ('siteurl','home')";
        $this->log_message( "执行SQL: $sql1", 'DEBUG' );
        $result1 = $wpdb->query($sql1);
        $this->log_message( "更新 siteurl/home，影响行数: " . ($result1 !== false ? $result1 : '失败'), 'INFO' );
        $sql2 = "UPDATE {$wpdb->posts} SET post_content = REPLACE(post_content, '$old_esc', '$new_esc')";
        $this->log_message( "执行SQL: $sql2", 'DEBUG' );
        $result2 = $wpdb->query($sql2);
        $this->log_message( "更新 post_content，影响行数: " . ($result2 !== false ? $result2 : '失败'), 'INFO' );
        $sql3 = "UPDATE {$wpdb->posts} SET guid = REPLACE(guid, '$old_esc', '$new_esc')";
        $this->log_message( "执行SQL: $sql3", 'DEBUG' );
        $result3 = $wpdb->query($sql3);
        $this->log_message( "更新 guid，影响行数: " . ($result3 !== false ? $result3 : '失败'), 'INFO' );
        $this->deep_replace($wpdb->options, 'option_id', 'option_value', $old, $new);
        $sql4 = "UPDATE {$wpdb->postmeta} SET meta_value = REPLACE(meta_value, '$old_esc', '$new_esc')";
        $this->log_message( "执行SQL: $sql4", 'DEBUG' );
        $result4 = $wpdb->query($sql4);
        $this->log_message( "更新 postmeta，影响行数: " . ($result4 !== false ? $result4 : '失败'), 'INFO' );
        $wpdb->query('COMMIT');
        $this->log_message( "已执行 COMMIT", 'INFO' );
        wp_cache_flush();
        $this->log_message( "replace_domain_safe 执行完毕", 'INFO' );
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
                    $this->log_message( "Session 恢复成功，用户: $username", 'DEBUG' );
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
        $this->log_message( "开始切换临时表，共 " . count($tables) . " 个", 'DEBUG' );
        foreach($tables as $t) {
            $temp = $t[0]; $real = substr($temp, strlen($prefix));
            $wpdb->query("DROP TABLE IF EXISTS `$real` ");
            $wpdb->query("RENAME TABLE `$temp` TO `$real` ");
        }
        $this->log_message( "表切换完成", 'DEBUG' );
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
    
    public function ajax_download_backup() {
        while (ob_get_level()) ob_end_clean();
        if ( ! current_user_can( 'manage_options' ) ) wp_die( '无权限', '', array( 'response' => 403 ) );
        if ( ! wp_verify_nonce( $_GET['_wpnonce'], 'wp_backup_action' ) ) wp_die( '无效请求', '', array( 'response' => 403 ) );
        $file = isset( $_GET['file'] ) ? sanitize_text_field( $_GET['file'] ) : '';
        if ( empty( $file ) ) wp_die( '文件参数缺失', '', array( 'response' => 400 ) );
        $backup_dir = $this->get_backup_dir();
        $real_path = realpath( $backup_dir . '/' . basename( $file ) );
        if ( ! $real_path || strpos( $real_path, $backup_dir ) !== 0 || ! file_exists( $real_path ) ) {
            wp_die( '无效文件', '', array( 'response' => 404 ) );
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
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '无权限' );
        if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'wp_backup_action' ) ) wp_send_json_error( '无效请求' );
        $file = isset( $_POST['file'] ) ? sanitize_text_field( $_POST['file'] ) : '';
        if ( empty( $file ) ) wp_send_json_error( '文件参数缺失' );
        $backup_dir = $this->get_backup_dir();
        $real_path = realpath( $backup_dir . '/' . basename( $file ) );
        if ( ! $real_path || strpos( $real_path, $backup_dir ) !== 0 || ! file_exists( $real_path ) ) {
            wp_send_json_error( '无效文件' );
        }
        if ( unlink( $real_path ) ) {
            wp_send_json_success( [ 'message' => '已删除' ] );
        } else {
            wp_send_json_error( '删除失败' );
        }
    }
    
    public function ajax_upload_chunk() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '无权限' );
        if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'wp_backup_action' ) ) wp_send_json_error( '无效请求' );
        
        $filename = isset( $_POST['filename'] ) ? sanitize_file_name( $_POST['filename'] ) : '';
        $start = isset( $_POST['start'] ) ? intval( $_POST['start'] ) : -1;
        $end = isset( $_POST['end'] ) ? intval( $_POST['end'] ) : -1;
        $file_size = isset( $_POST['file_size'] ) ? intval( $_POST['file_size'] ) : 0;
        
        if ( empty( $filename ) || $start < 0 || $end <= $start || ! isset( $_FILES['file_chunk'] ) ) {
            wp_send_json_error( '参数错误' );
        }
        
        $chunk = $_FILES['file_chunk'];
        if ( $chunk['error'] !== UPLOAD_ERR_OK ) {
            wp_send_json_error( '分片上传错误' );
        }
        
        $backup_dir = $this->get_backup_dir();
        $temp_dir = $backup_dir . '/upload_temp';
        if ( ! is_dir( $temp_dir ) ) wp_mkdir_p( $temp_dir );
        
        $task_id = md5( $filename . $file_size . get_current_user_id() );
        $part_file = $temp_dir . '/' . $task_id . '_' . $start . '_' . $end . '.part';
        
        if ( ! move_uploaded_file( $chunk['tmp_name'], $part_file ) ) {
            wp_send_json_error( '保存分片失败' );
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
                wp_send_json_error( '无法创建最终文件，请检查目录权限' );
            }
            usort( $meta['parts'], function($a, $b) { return $a['start'] - $b['start']; } );
            foreach ( $meta['parts'] as $part ) {
                $part_path = $temp_dir . '/' . $task_id . '_' . $part['start'] . '_' . $part['end'] . '.part';
                if ( ! file_exists( $part_path ) ) {
                    fclose( $handle );
                    @unlink( $final_file );
                    wp_send_json_error( '分片文件丢失，合并失败' );
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
            wp_send_json_success( [ 'message' => '上传并合并完成' ] );
        } else {
            wp_send_json_success( [ 'message' => '分片接收成功' ] );
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
        return $covered >= $size;
    }
    
    public function ajax_upload_status() {
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '无权限' );
        if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'wp_backup_action' ) ) wp_send_json_error( '无效请求' );
        
        $filename = isset( $_POST['filename'] ) ? sanitize_file_name( $_POST['filename'] ) : '';
        $file_size = isset( $_POST['file_size'] ) ? intval( $_POST['file_size'] ) : 0;
        if ( empty( $filename ) ) wp_send_json_error( '参数错误' );
        
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
        if ( ! current_user_can( 'manage_options' ) ) wp_send_json_error( '无权限' );
        if ( ! wp_verify_nonce( $_POST['_wpnonce'], 'wp_backup_action' ) ) wp_send_json_error( '无效请求' );
        
        $filename = isset( $_POST['filename'] ) ? sanitize_file_name( $_POST['filename'] ) : '';
        $file_size = isset( $_POST['file_size'] ) ? intval( $_POST['file_size'] ) : 0;
        if ( empty( $filename ) ) wp_send_json_error( '参数错误' );
        
        $temp_dir = $this->get_backup_dir() . '/upload_temp';
        $task_id = md5( $filename . $file_size . get_current_user_id() );
        $pattern = $temp_dir . '/' . $task_id . '_*';
        foreach ( glob( $pattern ) as $file ) @unlink( $file );
        @unlink( $temp_dir . '/' . $task_id . '_meta.json' );
        
        wp_send_json_success( [ 'message' => '已中断并清理临时文件' ] );
    }
    
    public function enqueue_scripts($hook) {
        if ($hook !== 'tools_page_wp-backup-restore') return;
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
        wp_localize_script( 'jquery', 'wp_ajax', array(
            'api_url' => plugins_url( 'restore-api.php', __FILE__ ),
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'current_level' => $this->log_level,
            'nonce' => wp_create_nonce( 'wp_backup_action' )
        ) );
    }
    
    public function add_admin_menu() {
        add_management_page( 'WP备份还原', 'WP备份还原', 'manage_options', 'wp-backup-restore', array( $this, 'admin_page' ) );
    }
    
    public function admin_page() {
        $backups = $this->get_backup_list();
        ?>
        <div class="wrap">
            <h1 style="font-weight:600; margin-bottom:24px;">🔄 WP一键备份还原</h1>
            <div class="wp-backup-card">
                <div style="margin-bottom:20px;">
                    <button id="btn-bak" class="button button-primary button-large" style="display:inline-flex; align-items:center; gap:6px;">
                        <span class="dashicons dashicons-backup"></span> 立即备份
                    </button>
                </div>
                <div style="margin-bottom:12px; display:flex; flex-wrap:wrap; align-items:center; gap:12px;">
                    <select id="sel-bak" style="min-width:260px; max-width:100%;">
                        <?php if(empty($backups)): ?>
                            <option value="">暂无备份文件</option>
                        <?php else: ?>
                            <?php foreach($backups as $f): ?>
                                <option value="<?php echo esc_attr($f); ?>"><?php echo esc_html(basename($f)); ?></option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <button id="btn-download" class="button button-secondary" style="display:inline-flex; align-items:center; gap:6px;">
                        <span class="dashicons dashicons-download"></span> 下载
                    </button>
                    <button id="btn-delete" class="button button-secondary button-danger" style="display:inline-flex; align-items:center; gap:6px;">
                        <span class="dashicons dashicons-trash"></span> 删除
                    </button>
                </div>
                <div style="margin-bottom:20px;">
                    <button id="btn-upload" class="button button-secondary" style="display:inline-flex; align-items:center; gap:6px;">
                        <span class="dashicons dashicons-upload"></span> 上传备份
                    </button>
                    <button id="btn-cancel-upload" class="button button-secondary button-danger" style="display:inline-flex; align-items:center; gap:6px; margin-left:10px; display:none;">
                        <span class="dashicons dashicons-no-alt"></span> 中断上传
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
                        <span style="font-size:1.2em;">↩️</span> 全站还原
                    </button>
                </div>
                <hr style="margin:20px 0;">
                <div class="log-area">
                    <label><strong>📋 调试日志级别：</strong></label>
                    <select id="log-level" style="margin-left:12px; border-radius:4px;">
                        <option value="OFF" <?php selected( $this->log_level, 'OFF' ); ?>>OFF</option>
                        <option value="ERROR" <?php selected( $this->log_level, 'ERROR' ); ?>>ERROR</option>
                        <option value="WARNING" <?php selected( $this->log_level, 'WARNING' ); ?>>WARNING</option>
                        <option value="INFO" <?php selected( $this->log_level, 'INFO' ); ?>>INFO</option>
                        <option value="DEBUG" <?php selected( $this->log_level, 'DEBUG' ); ?>>DEBUG</option>
                    </select>
                    <span id="log-save-status" style="margin-left:12px; color:#00a32a;"></span>
                    <p class="description" style="margin-top:10px;">
                        📁 日志文件：<code><?php echo esc_html( $this->get_backup_dir() . '/restore.log' ); ?></code>
                        <a href="javascript:void(0);" id="copy-log-path" class="copy-path-btn">📋 复制路径</a>
                    </p>
                </div>
            </div>
        </div>
        
        <div id="modal-progress" style="display:none; position:fixed; top:0; left:0; width:100%; height:100%; background:rgba(0,0,0,0.7); z-index:100000; text-align:center; padding-top:150px;">
            <div style="background:#fff; width:500px; margin:0 auto; padding:25px 30px 30px; border-radius:12px; box-shadow:0 5px 20px rgba(0,0,0,0.3); text-align:left;">
                <h3 id="modal-title" style="margin:0 0 20px; font-size:18px; border-bottom:1px solid #ddd; padding-bottom:10px;">正在处理任务</h3>
                <div style="background:#f0f0f0; height:32px; border-radius:16px; overflow:hidden; margin:20px 0; position:relative;">
                    <div id="progress-bar" style="background:#0073aa; width:0%; height:100%; transition:width 0.3s; color:#fff; line-height:32px; text-align:center; font-weight:bold;">0%</div>
                </div>
                <div id="progress-msg" style="font-size:14px; color:#555; margin:10px 0 20px; word-break:break-word;">准备中...</div>
                <div style="text-align:right;">
                    <button id="stop-btn" class="button button-secondary" style="margin-right:10px;">停止任务</button>
                    <button id="cancel-modal-btn" class="button button-secondary modal-cancel-btn" style="display:none;">取消</button>
                    <button id="confirm-btn" class="button button-primary" style="display:none;">确定</button>
                </div>
            </div>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            var apiUrl = wp_ajax.api_url;
            var ajaxUrl = wp_ajax.ajax_url;
            var nonce = wp_ajax.nonce;
            var currentTaskId = null;
            var currentType = null;
            var pollInterval = null;
            var isStopping = false;
            
            $('#btn-download').click(function(e) {
                e.preventDefault();
                var file = $('#sel-bak').val();
                if (!file) { alert('请选择一个备份文件'); return; }
                window.location.href = ajaxUrl + '?action=wp_backup_download&file=' + encodeURIComponent(file) + '&_wpnonce=' + nonce;
            });
            
            $('#btn-delete').click(function() {
                var file = $('#sel-bak').val();
                if (!file) { alert('请选择一个备份文件'); return; }
                if (!confirm('确定要删除此备份文件吗？不可恢复！')) return;
                $.post(ajaxUrl, { action: 'wp_backup_delete', file: file, _wpnonce: nonce }, function(res) {
                    if (res.success) { alert('删除成功'); location.reload(); }
                    else { alert('删除失败：' + (res.data || res.message || '未知错误')); }
                }, 'json').fail(function() { alert('请求失败，请检查网络连接'); });
            });
            
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
                                        $('#upload-status').append(`<br><small>网络良好，分片大小提升至 ${(currentChunkSize/1024/1024).toFixed(1)}MB</small>`);
                                        consecutiveSuccess = 0;
                                    }
                                    resolve(res);
                                } else {
                                    reject(new Error(res.data || '上传失败'));
                                }
                            } catch(e) { reject(e); }
                        } else if (xhr.status === 413) {
                            currentChunkSize = Math.max(currentChunkSize / 2, minChunkSize);
                            $('#upload-status').html(`<span style="color:#d63638;">单片过大，已降低至 ${(currentChunkSize/1024/1024).toFixed(1)}MB，重试中...</span>`);
                            reject(new Error('Chunk too large'));
                        } else {
                            reject(new Error(`HTTP ${xhr.status}`));
                        }
                    };
                    xhr.onerror = () => reject(new Error('网络错误'));
                    xhr.ontimeout = () => reject(new Error('上传超时'));
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
                        $('#upload-status').html(`<span style="color:#d63638;">区间 ${start}-${end} 上传失败，${wait/1000}秒后重试 (${attempt}/${maxRetries})...</span>`);
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
                    }, 'json').fail(() => reject(new Error('查询状态失败')));
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
                    }, 'json').fail(() => reject(new Error('中断请求失败')));
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
                    $('#upload-status').html(`准备上传，动态分片大小 ${(currentChunkSize/1024/1024).toFixed(1)}MB，剩余 ${remainingIntervals.length} 个区间`);
                    
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
                                if (response && response.message === '上传并合并完成') {
                                    isUploading = false;
                                    $('#upload-status').html('<span style="color:#46b450;">✓ 上传成功，正在刷新页面...</span>');
                                    setTimeout(() => location.reload(), 1500);
                                    return;
                                }
                                uploadedParts.push({ start: start, end: chunkEnd });
                                uploadedParts = mergeIntervals(uploadedParts);
                                const uploadedNow = uploadedParts.reduce((sum, p) => sum + (p.end - p.start), 0);
                                const percent = Math.round((uploadedNow / totalBytes) * 100);
                                $('#upload-progress-bar').css('width', percent + '%').text(percent + '%');
                                $('#upload-status').text(`区间 ${start}-${chunkEnd} 上传成功 (${percent}%)`);
                                start = chunkEnd;
                            } catch (error) {
                                throw new Error(`上传失败: ${error.message}`);
                            }
                        }
                    }
                    
                    if (isUploading) {
                        const finalParts = await getUploadedParts(file.name, file.size);
                        if (finalParts.length === 0) {
                            $('#upload-status').html('<span style="color:#46b450;">✓ 上传成功，正在刷新页面...</span>');
                            setTimeout(() => location.reload(), 1500);
                            return;
                        }
                        const finalMerged = mergeIntervals(finalParts);
                        const totalCovered = finalMerged.reduce((sum, p) => sum + (p.end - p.start), 0);
                        if (totalCovered >= file.size) {
                            $('#upload-status').html('<span style="color:#46b450;">✓ 上传成功，正在刷新页面...</span>');
                            setTimeout(() => location.reload(), 1500);
                        } else {
                            throw new Error('上传未完全完成');
                        }
                    }
                } catch (error) {
                    $('#upload-status').html(`<span style="color:#d63638;">✗ 上传失败：${error.message}</span>`);
                    setTimeout(() => $('#upload-progress-container').hide(), 3000);
                } finally {
                    isUploading = false;
                    $('#btn-cancel-upload').hide();
                }
            }
            
            $('#btn-cancel-upload').click(async function() {
                if (!currentFile || !isUploading) return;
                if (confirm('确定要中断上传并清理已上传的临时文件吗？')) {
                    isUploading = false;
                    $('#upload-status').html('正在中断并清理临时文件...');
                    try {
                        await cancelUpload(currentFile.name, currentFile.size);
                        $('#upload-status').html('已中断上传，临时文件已清理');
                        setTimeout(() => $('#upload-progress-container').hide(), 2000);
                    } catch (error) {
                        $('#upload-status').html(`中断失败：${error.message}`);
                    }
                    $('#btn-cancel-upload').hide();
                }
            });
            
            $('#btn-upload').click(function() { $('#upload-file-input').trigger('click'); });
            $('#upload-file-input').change(function() {
                const file = this.files[0];
                if (!file) return;
                if (!file.name.endsWith('.bgbk')) { alert('只允许 .bgbk 格式的备份文件'); return; }
                uploadFileInChunks(file);
            });
            
            function resetConfirmButton() {
                $('#confirm-btn').off('click').click(closeModal);
                $('#cancel-modal-btn').off('click').click(closeModal);
            }
            function showModal(title, showCancel = false) {
                resetConfirmButton();
                $('#modal-title').text(title);
                $('#progress-bar').css('width', '0%').text('0%');
                $('#progress-msg').text('准备中...');
                $('#stop-btn').hide();
                $('#confirm-btn').hide();
                $('#cancel-modal-btn').hide();
                if (showCancel) {
                    $('#cancel-modal-btn').show().text('取消');
                    $('#confirm-btn').show().text('确认');
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
                $('#stop-btn').prop('disabled', true).text('停止中...');
                $.post(apiUrl, { api_type: 'stop_task', task_id: currentTaskId }, function(res) {
                    if (res.success) {
                        $('#progress-msg').html('<span style="color:#d63638;">⏸️ 正在停止任务并清理临时文件...</span>');
                        if (pollInterval) clearInterval(pollInterval);
                        setTimeout(function() {
                            $.get(apiUrl, { api_type: currentType + '_status', task_id: currentTaskId }, function(res) {
                                var d = res.data;
                                if (d.status === 'error' || d.status === 'stopped') {
                                    $('#progress-msg').html('任务已停止，临时文件已清理。');
                                    $('#stop-btn').hide();
                                    $('#confirm-btn').show();
                                    resetConfirmButton();
                                } else {
                                    $('#progress-msg').html('任务可能仍在停止中，请稍后手动刷新页面。');
                                    $('#stop-btn').hide();
                                    $('#confirm-btn').show();
                                    resetConfirmButton();
                                }
                            });
                        }, 2000);
                    } else {
                        $('#progress-msg').html('<span style="color:#d63638;">停止请求失败：' + (res.message || '未知错误') + '</span>');
                        $('#stop-btn').prop('disabled', false).text('停止任务');
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
                        $('#progress-msg').html(d.message || '处理中...');
                        if (d.status === 'done' || percent >= 100) {
                            clearInterval(pollInterval);
                            $('#progress-bar').css('background', '#46b450').text('100%');
                            var msgHtml = (type === 'backup') ? 
                                '<div style="margin-top:10px; padding:10px; background:#f0fff0; border:1px solid #46b450; border-radius:4px;"><b style="color:#46b450;">✔ 备份圆满完成！</b><br><span style="color:#666;">全站文件与数据库已成功备份。</span></div>' :
                                '<div style="margin-top:10px; padding:10px; background:#f0fff0; border:1px solid #46b450; border-radius:4px;"><b style="color:#46b450;">✔ 还原圆满完成！</b><br><span style="color:#666;">全站数据已成功还原。</span><br><span style="color:#d63638;">提示：由于数据库已更新，请手动刷新页面，可能需要重新登录。</span></div>';
                            $('#progress-msg').html(msgHtml);
                            $('#stop-btn').hide();
                            $('#confirm-btn').show().text('确定并刷新页面').off('click').on('click', function() {
                                location.href = window.location.origin + '/wp-admin/tools.php?page=wp-backup-restore';
                            });
                        } else if (d.status === 'error') {
                            clearInterval(pollInterval);
                            $('#progress-msg').html('<span style="color:#d63638;">❌ 错误：' + (d.message || '未知错误') + '</span>');
                            $('#stop-btn').hide();
                            $('#confirm-btn').show().text('确定').off('click').click(closeModal);
                        } else if (d.status === 'stopped') {
                            clearInterval(pollInterval);
                            $('#progress-msg').html('任务已手动停止，临时文件已清理。');
                            $('#stop-btn').hide();
                            $('#confirm-btn').show().text('确定').off('click').click(closeModal);
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
                        $('#progress-msg').html('启动失败：' + (res.message || '未知错误'));
                        $('#stop-btn').hide();
                        $('#confirm-btn').show();
                        resetConfirmButton();
                    }
                }, 'json');
            }
            $('#btn-bak').click(function() { startTask('backup_start', {}, '全站备份进行中'); });
            $('#btn-res').click(function() {
                var file = $('#sel-bak').val();
                if (!file) {
                    showModal('提示', true);
                    $('#progress-msg').html('请先选择一个备份文件。');
                    $('#confirm-btn').hide();
                    $('#cancel-modal-btn').show();
                    return;
                }
                var allCookies = document.cookie;
                var currentOrigin = window.location.origin;
                showModal('确认还原', true);
                $('#progress-msg').html('⚠️ 此操作将覆盖现有数据，不可逆！确认要继续吗？');
                $('#confirm-btn').one('click', function() {
                    closeModal();
                    startTask('restore_start', { backup_file: file, browser_cookie: allCookies, current_url: currentOrigin }, '全站还原进行中');
                });
                $('#cancel-modal-btn').one('click', closeModal);
            });
            $('#stop-btn').click(stopTask);
            $('#confirm-btn').click(closeModal);
            $('#cancel-modal-btn').click(closeModal);
            
            $('#log-level').change(function() {
                var level = $(this).val();
                $.post(ajaxUrl, { action: 'save_log_level', level: level }, function(res) {
                    if (res.success) {
                        $('#log-save-status').text('已保存').fadeOut(1500, function() { $(this).text('').show(); });
                    } else {
                        $('#log-save-status').text('保存失败').css('color','red').fadeOut(1500, function() { $(this).text('').css('color','#00a32a').show(); });
                    }
                });
            });
            $('#copy-log-path').click(function() {
                var text = $('.log-area code').text();
                navigator.clipboard.writeText(text).then(function() { alert('路径已复制'); });
            });
        });
        </script>
        <?php
    }
}
new WP_Backup_Restore_Active();
