<?php
/**
 * Plugin Name: WP一键备份还原
 * Description: 批量处理、兼容序列化的安全域名替换、Session保持、严格目录排除。
 * Version: 1.0.0
 * Author: BG Tech
 */

if ( ! defined( 'ABSPATH' ) ) exit;

class WP_Backup_Restore_Active {

    const BACKUP_DIR = 'wpbkres';
    const BACKUP_EXT = '.bgbk';
    const BATCH_SIZE = 500;
    const COMMIT_INTERVAL = 20;

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

    private function log_message($message, $level = 'INFO') {
        $level_values = [
            'ERROR'   => self::LOG_ERROR,
            'WARNING' => self::LOG_WARNING,
            'INFO'    => self::LOG_INFO,
            'DEBUG'   => self::LOG_DEBUG,
        ];
        $msg_level = isset( $level_values[ $level ] ) ? $level_values[ $level ] : self::LOG_INFO;
        if ( $msg_level > $this->get_log_level_value() ) return;
        $log_file = rtrim(ABSPATH, '/\\') . '/wp-content/uploads/' . self::BACKUP_DIR . '/restore.log';
        $timestamp = date('Y-m-d H:i:s');
        @file_put_contents( $log_file, "[$timestamp] [$level] $message" . PHP_EOL, FILE_APPEND | LOCK_EX );
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
        $file = rtrim(ABSPATH, '/\\') . '/wp-content/uploads/' . self::BACKUP_DIR . '/state_' . $task_id . '.json';
        if (!file_exists($file)) return false;
        $data = json_decode(file_get_contents($file), true);
        return isset($data['stop']) && $data['stop'] === true;
    }

    public function update_worker_state( $task_id, $data ) {
        $dir = rtrim(ABSPATH, '/\\') . '/wp-content/uploads/' . self::BACKUP_DIR;
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        $file = $dir . '/state_' . $task_id . '.json';
        $state = file_exists( $file ) ? json_decode( file_get_contents( $file ), true ) : [];
        $new_state = array_merge( (array)$state, (array)$data );
        file_put_contents( $file, json_encode( $new_state ), LOCK_EX );
    }

    // ==================== 备份引擎 ====================

    public function do_full_backup( $task_id, $params ) {
        set_time_limit( 0 );
        @ini_set( 'memory_limit', '512M' );
        $backup_dir = rtrim(ABSPATH, '/\\') . '/wp-content/uploads/' . self::BACKUP_DIR;
        $backup_name = 'backup_' . date( 'Ymd_His' ) . self::BACKUP_EXT;
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

    // ==================== 还原引擎 ====================

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

            // 先替换域名
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

            // 后恢复 Session
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

    // ==================== 辅助函数 ====================

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
        $dir = rtrim(ABSPATH, '/\\') . '/wp-content/uploads/' . self::BACKUP_DIR;
        $this->remove_directory($dir . '/temp_' . $task_id);
        foreach (glob(ABSPATH . '.wp_restore_*') as $f) @unlink($f);
        @unlink(rtrim(ABSPATH, '/\\') . '/database.sql');
        @unlink(rtrim(ABSPATH, '/\\') . '/siteinfo.json');
    }

    public function cleanup_orphaned_temp() {
        $dir = rtrim(ABSPATH, '/\\') . '/wp-content/uploads/' . self::BACKUP_DIR;
        foreach (glob($dir . '/temp_*') as $t) {
            if (is_dir($t) && (time() - filemtime($t) > 3600)) $this->remove_directory($t);
        }
        foreach (glob($dir . '/state_*.json') as $f) {
            if (time() - filemtime($f) > 86400) @unlink($f);
        }
        $log = $dir . '/restore.log';
        if (file_exists($log) && filesize($log) > 10 * 1024 * 1024) @unlink($log);
    }

    public function get_backup_list() {
        $dir = rtrim(ABSPATH, '/\\') . '/wp-content/uploads/' . self::BACKUP_DIR;
        return glob($dir . '/*.bgbk') ?: [];
    }

    // ==================== UI ====================

    public function enqueue_scripts($hook) {
        if ($hook !== 'tools_page_wp-backup-restore') return;
        wp_enqueue_script( 'jquery' );
        wp_localize_script( 'jquery', 'wp_ajax', array(
            'api_url' => plugins_url( 'restore-api.php', __FILE__ ),
            'ajax_url' => admin_url( 'admin-ajax.php' ),
            'current_level' => $this->log_level
        ) );
    }

    public function add_admin_menu() {
        add_management_page( 'WP备份还原', 'WP备份还原', 'manage_options', 'wp-backup-restore', array( $this, 'admin_page' ) );
    }

    public function admin_page() {
        $backups = $this->get_backup_list();
        ?>
        <div class="wrap">
            <h1>WP一键备份还原</h1>
            <div style="background:#fff; padding:20px; border:1px solid #ccd0d4; margin-top:20px; border-radius:8px;">
                <div style="margin-bottom:20px;">
                    <button id="btn-bak" class="button button-primary button-large">全站备份</button>
                    <select id="sel-bak" style="margin:0 15px; width:300px; vertical-align:top;">
                        <?php foreach($backups as $f) echo "<option value='$f'>".basename($f)."</option>"; ?>
                    </select>
                    <button id="btn-res" class="button button-large">全站还原</button>
                </div>
                <hr style="margin:15px 0;">
                <div style="background:#f9f9f9; padding:12px; border-radius:6px;">
                    <label><strong>调试日志级别：</strong></label>
                    <select id="log-level" style="margin-left:10px;">
                        <option value="OFF" <?php selected( $this->log_level, 'OFF' ); ?>>OFF</option>
                        <option value="ERROR" <?php selected( $this->log_level, 'ERROR' ); ?>>ERROR</option>
                        <option value="WARNING" <?php selected( $this->log_level, 'WARNING' ); ?>>WARNING</option>
                        <option value="INFO" <?php selected( $this->log_level, 'INFO' ); ?>>INFO</option>
                        <option value="DEBUG" <?php selected( $this->log_level, 'DEBUG' ); ?>>DEBUG</option>
                    </select>
                    <span id="log-save-status" style="margin-left:10px; color:green;"></span>
                    <p class="description">日志文件位于 <code>wp-content/uploads/wpbkres/restore.log</code></p>
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
                    <button id="confirm-btn" class="button button-primary" style="display:none;">确定</button>
                </div>
            </div>
        </div>

        <script>
        jQuery(document).ready(function($) {
            var apiUrl = wp_ajax.api_url;
            var ajaxUrl = wp_ajax.ajax_url;
            var currentTaskId = null;
            var currentType = null;
            var pollInterval = null;
            var isStopping = false;

            $('#log-level').change(function() {
                var level = $(this).val();
                $.post(ajaxUrl, { action: 'save_log_level', level: level }, function(res) {
                    if (res.success) {
                        $('#log-save-status').text('已保存').fadeOut(1500, function() { $(this).text('').show(); });
                    } else {
                        $('#log-save-status').text('保存失败').css('color','red').fadeOut(1500, function() { $(this).text('').css('color','green').show(); });
                    }
                });
            });

            function resetConfirmButton() {
                $('#confirm-btn').off('click').click(closeModal);
            }

            function showModal(title) {
                resetConfirmButton();
                $('#modal-title').text(title);
                $('#progress-bar').css('width', '0%').text('0%');
                $('#progress-msg').text('准备中...');
                $('#stop-btn').show().prop('disabled', false);
                $('#confirm-btn').hide();
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
						if (type === 'backup') {
							$('#progress-msg').html(
								'<div style="margin-top:10px; padding:10px; background:#f0fff0; border:1px solid #46b450; border-radius:4px;">' +
								'<b style="color:#46b450; font-size:16px;">✔ 备份圆满完成！</b><br>' +
								'<span style="color:#666;">全站文件与数据库已成功备份。</span><br>' +
								'</div>'
							);
						} else {
							$('#progress-msg').html(
								'<div style="margin-top:10px; padding:10px; background:#f0fff0; border:1px solid #46b450; border-radius:4px;">' +
								'<b style="color:#46b450; font-size:16px;">✔ 还原圆满完成！</b><br>' +
								'<span style="color:#666;">全站数据已成功还原。</span><br>' +
								'<span style="color:#d63638;">提示：由于数据库已更新，点击下方按钮刷新后，可能需要重新登录。</span>' +
								'</div>'
							);
						}
						$('#stop-btn').hide();
						$('#confirm-btn')
							.show()
							.text('确定并手动刷新页面')
							.off('click')
							.on('click', function() {
								$(this).prop('disabled', true).text('正在跳转...');
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

            $('#btn-bak').click(function() {
                startTask('backup_start', {}, '全站备份进行中');
            });

            $('#btn-res').click(function() {
                var file = $('#sel-bak').val();
                if (!file) {
                    showModal('提示');
                    $('#progress-msg').html('请先选择一个备份文件。');
                    $('#stop-btn').hide();
                    $('#confirm-btn').show();
                    resetConfirmButton();
                    return;
                }
                var allCookies = document.cookie;
                var currentOrigin = window.location.origin;
                showModal('确认还原');
                $('#progress-msg').html('此操作将覆盖现有数据，不可逆！确认要继续吗？');
                $('#stop-btn').hide();
                $('#confirm-btn').show().one('click', function() {
                    closeModal();
                    startTask('restore_start', { 
                        backup_file: file,
                        browser_cookie: allCookies,
                        current_url: currentOrigin
                    }, '全站还原进行中');
                });
            });

            $('#stop-btn').click(stopTask);
            $('#confirm-btn').click(closeModal);
        });
        </script>
        <?php
    }
}
new WP_Backup_Restore_Active();
