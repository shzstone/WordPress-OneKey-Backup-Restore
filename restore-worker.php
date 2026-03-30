<?php
/**
 * 后台工作进程 (restore-worker.php) - v1.0.0
 */
ini_set('display_errors', 1);
error_reporting(E_ALL);

$api_type = isset($argv[1]) ? $argv[1] : '';
$task_id  = isset($argv[2]) ? $argv[2] : '';

$plugin_dir = __DIR__;
$wp_content = dirname($plugin_dir, 2);
$wp_root    = dirname($wp_content);

if (!file_exists($wp_root . '/wp-load.php')) { die("wp-load.php not found\n"); }

define('WP_USE_THEMES', false);
define('WP_ADMIN', true);
require_once $wp_root . '/wp-load.php';
require_once $plugin_dir . '/WP-Res.php';

$logic = new WP_Backup_Restore_Active();
$state_file = $wp_content . '/uploads/wpbkres/state_' . $task_id . '.json';
if (!file_exists($state_file)) { die("State file not found\n"); }

$state_data = json_decode(file_get_contents($state_file), true);
$params = isset($state_data['params']) ? $state_data['params'] : [];

function should_stop_worker($task_id) {
    $state_file = dirname(__DIR__, 2) . '/uploads/wpbkres/state_' . $task_id . '.json';
    if (!file_exists($state_file)) return false;
    $state = json_decode(file_get_contents($state_file), true);
    return isset($state['stop']) && $state['stop'] === true;
}

$logic->set_stop_check_callback(function($task_id) {
    return should_stop_worker($task_id);
});

if ($api_type === 'restore_start') {
    $logic->do_full_restore($task_id, $params);
} else {
    $logic->do_full_backup($task_id, $params);
}
