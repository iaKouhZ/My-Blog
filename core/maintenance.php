<?php
/** CLI 数据结构升级入口：HTTP 不可访问，不加载前台、后台或第三方插件。 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('APP_BOOT', true);
define('APP_ROOT', dirname(__DIR__));
defined('APP_BOOT') or exit;

if (count($argv) !== 2 || $argv[1] !== 'upgrade') {
    fwrite(STDERR, 'Usage: php core/maintenance.php upgrade' . PHP_EOL);
    exit(1);
}
foreach (array('Config', 'Utils', 'DB', 'Option', 'Hook', 'Auth', 'Logger', 'Schema') as $class) {
    require __DIR__ . '/' . $class . '.php';
}
try {
    if (!Config::load(APP_ROOT . '/config.php')) {
        throw new RuntimeException('site is not installed');
    }
    DB::init(Config::get('db'));
    $timezone = Option::get('timezone', 'Asia/Shanghai');
    date_default_timezone_set(in_array($timezone, timezone_identifiers_list(), true) ? $timezone : 'Asia/Shanghai');
    Schema::upgrade();
    Logger::write('security', 'schema.upgrade', 'success', array('version' => 2), 0, 'system');
    echo 'Schema upgrade complete.' . PHP_EOL;
} catch (Throwable $ex) {
    if (DB::pdo() !== null) {
        Logger::write('security', 'schema.upgrade', 'fail', array('reason' => 'upgrade_failed'), 0, 'system');
    }
    // PDO 诊断可能含连接信息，CLI 同样不输出原始异常。
    fwrite(STDERR, $ex instanceof PDOException ? 'Schema upgrade failed; check database availability and privileges.' . PHP_EOL : $ex->getMessage() . PHP_EOL);
    exit(1);
}
