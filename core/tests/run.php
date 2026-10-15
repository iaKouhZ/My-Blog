<?php
/** CLI 回归测试入口：隔离各组依赖，使用当前 PHP 可执行文件。 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('APP_BOOT', true);
defined('APP_BOOT') or exit;

$failed = false;
foreach (array('markdown_hooks.php', 'services.php', 'database.php', 'sessions.php', 'rewrite.php') as $suite) {
    echo $suite . PHP_EOL;
    $command = array(PHP_BINARY);
    if (php_ini_loaded_file() !== false) {
        $command[] = '-c';
        $command[] = php_ini_loaded_file();
    }
    $command[] = __DIR__ . '/' . $suite;
    $process = proc_open($command, array(0 => STDIN, 1 => STDOUT, 2 => STDERR), $pipes);
    if (!is_resource($process) || proc_close($process) !== 0) {
        $failed = true;
    }
}
exit($failed ? 1 : 0);
