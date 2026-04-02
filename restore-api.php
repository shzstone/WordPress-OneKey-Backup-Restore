<?php
/**
 * 独立 API 入口 (restore-api.php) - v1.0.0
 */
ini_set('display_errors', 0);
error_reporting(0);
while (ob_get_level()) { ob_end_clean(); }
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache, no-store, must-revalidate');

// 语言选择
function get_preferred_language() {
    // 允许通过参数 lang 指定，例如 ?lang=en 或 ?lang=zh
    if (isset($_GET['lang']) && in_array($_GET['lang'], ['en', 'zh'])) {
        return $_GET['lang'];
    }
    if (isset($_POST['lang']) && in_array($_POST['lang'], ['en', 'zh'])) {
        return $_POST['lang'];
    }
    // 通过 HTTP Accept-Language 检测
    if (isset($_SERVER['HTTP_ACCEPT_LANGUAGE'])) {
        $langs = explode(',', $_SERVER['HTTP_ACCEPT_LANGUAGE']);
        foreach ($langs as $lang) {
            $lang = strtolower(substr(trim($lang), 0, 2));
            if ($lang === 'zh') {
                return 'zh';
            }
            if ($lang === 'en') {
                return 'en';
            }
        }
    }
    // 默认英文（便于国际用户）
    return 'en';
}

$lang = get_preferred_language();

// 定义消息翻译数组
$messages = [
    'en' => [
        'waiting_response' => 'Waiting for process response...',
        'init_task' => 'Starting background process...',
        'start_failed' => 'Server does not support exec, popen, or curl for background process. Please contact your hosting provider to enable these functions, or manually run: php ',
        'task_not_exist' => 'Task does not exist',
        'stop_requested' => 'Stop request sent',
        'stop_cleaning' => 'cleaning...',
        'invalid_api_type' => 'Invalid api_type',
    ],
    'zh' => [
        'waiting_response' => '等待进程响应...',
        'init_task' => '正在启动后台进程...',
        'start_failed' => '服务器不支持 exec、popen 或 curl 等后台启动方式。请联系主机提供商开启这些函数，或手动运行命令：php ',
        'task_not_exist' => '任务不存在',
        'stop_requested' => '停止请求已发送',
        'stop_cleaning' => '正在清理...',
        'invalid_api_type' => '无效的 api_type',
    ]
];

function get_msg($key) {
    global $lang, $messages;
    return $messages[$lang][$key] ?? $messages['en'][$key];
}

$current_dir = __DIR__;
$wp_content  = dirname($current_dir, 2);
$state_path  = $wp_content . '/uploads/wpbkres';
if (!is_dir($state_path)) { @mkdir($state_path, 0755, true); }

$raw_input = file_get_contents('php://input');
$post_data = [];
if ($raw_input) { parse_str($raw_input, $post_data); }
$request = array_merge($_GET, $_POST, $post_data);
$api_type = isset($request['api_type']) ? $request['api_type'] : '';

// 1. 进度查询
if ($api_type === 'restore_status' || $api_type === 'backup_status') {
    $task_id = isset($request['task_id']) ? preg_replace('/[^a-z0-9_\-.]/i', '', $request['task_id']) : '';
    $file = $state_path . '/state_' . $task_id . '.json';
    clearstatcache(true, $file);
    if (!file_exists($file)) {
        echo json_encode(['success' => true, 'data' => ['percent' => 1, 'message' => get_msg('waiting_response'), 'status' => 'init']]);
        exit;
    }
    $data = json_decode(file_get_contents($file), true);
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

// 2. 启动任务（多级后备）
if ($api_type === 'restore_start' || $api_type === 'backup_start') {
    $task_id = ($api_type === 'backup_start' ? 'bak_' : 'res_') . uniqid();
    $worker_script = $current_dir . '/restore-worker.php';
    $output_log = $state_path . '/worker_debug.log';

    $state = [
        'task_id' => $task_id,
        'status'  => 'init',
        'percent' => 1,
        'message' => get_msg('init_task'),
        'params'  => $request
    ];
    file_put_contents($state_path . '/state_' . $task_id . '.json', json_encode($state));

    $started = false;
    // 方式1：exec + nohup
    $exec_enabled = function_exists('exec') && !in_array('exec', array_map('trim', explode(',', ini_get('disable_functions'))));
    if ($exec_enabled) {
        $php_bin = is_executable('/usr/local/bin/php') ? '/usr/local/bin/php' : 'php';
        $cmd = sprintf('nohup %s %s %s %s > %s 2>&1 &', $php_bin, escapeshellarg($worker_script), escapeshellarg($api_type), escapeshellarg($task_id), escapeshellarg($output_log));
        exec($cmd);
        $started = true;
    }
    // 方式2：popen
    if (!$started && function_exists('popen')) {
        $php_bin = PHP_BINARY;
        $cmd = sprintf('%s %s %s %s > %s 2>&1 &', $php_bin, escapeshellarg($worker_script), escapeshellarg($api_type), escapeshellarg($task_id), escapeshellarg($output_log));
        $handle = popen($cmd, 'r');
        if ($handle !== false) {
            pclose($handle);
            $started = true;
        }
    }
    // 方式3：异步 curl
    if (!$started && function_exists('curl_version')) {
        $self_url = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        $post_data = http_build_query(['api_type' => 'async_trigger', 'task_id' => $task_id, 'type' => $api_type]);
        $ch = curl_init();
        curl_setopt($ch, CURLOPT_URL, $self_url);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, $post_data);
        curl_setopt($ch, CURLOPT_TIMEOUT_MS, 100);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_NOSIGNAL, 1);
        curl_exec($ch);
        curl_close($ch);
        $started = true;
    }

    if (!$started) {
        echo json_encode(['success' => false, 'message' => get_msg('start_failed') . $worker_script . ' ' . $api_type . ' ' . $task_id]);
        exit;
    }

    echo json_encode(['success' => true, 'data' => ['task_id' => $task_id]]);
    exit;
}

// 3. 异步触发（用于 curl 方式）
if ($api_type === 'async_trigger') {
    $task_id = isset($request['task_id']) ? preg_replace('/[^a-z0-9_\-.]/i', '', $request['task_id']) : '';
    $type    = isset($request['type']) ? $request['type'] : '';
    $worker_script = $current_dir . '/restore-worker.php';
    $output_log = $state_path . '/worker_debug.log';
    $php_bin = PHP_BINARY;
    $cmd = sprintf('%s %s %s %s > %s 2>&1 &', $php_bin, escapeshellarg($worker_script), escapeshellarg($type), escapeshellarg($task_id), escapeshellarg($output_log));
    exec($cmd);
    exit;
}

// 4. 停止任务
if ($api_type === 'stop_task') {
    $task_id = isset($request['task_id']) ? preg_replace('/[^a-z0-9_\-.]/i', '', $request['task_id']) : '';
    $file = $state_path . '/state_' . $task_id . '.json';
    if (!file_exists($file)) {
        echo json_encode(['success' => false, 'message' => get_msg('task_not_exist')]);
        exit;
    }
    $data = json_decode(file_get_contents($file), true);
    $data['stop'] = true;
    $data['message'] = get_msg('stop_requested') . ' ' . get_msg('stop_cleaning');
    file_put_contents($file, json_encode($data), LOCK_EX);
    echo json_encode(['success' => true, 'message' => get_msg('stop_requested')]);
    exit;
}

echo json_encode(['success' => false, 'message' => get_msg('invalid_api_type')]);
