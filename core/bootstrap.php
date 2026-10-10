<?php
/**
 * 内核引导：加载 config、DB、Session、Hook、Logger、插件；统一安全头与错误处理
 */

define('APP_BOOT', true);
define('APP_ROOT', dirname(__DIR__));
define('APP_VERSION', '1.0.0');

require APP_ROOT . '/core/Config.php';
require APP_ROOT . '/core/Utils.php';
require APP_ROOT . '/core/DB.php';
require APP_ROOT . '/core/Hook.php';
require APP_ROOT . '/core/Option.php';
require APP_ROOT . '/core/Lang.php';
require APP_ROOT . '/core/Csrf.php';
require APP_ROOT . '/core/Router.php';
require APP_ROOT . '/core/Auth.php';
require APP_ROOT . '/core/Logger.php';
require APP_ROOT . '/core/Markdown.php';
require APP_ROOT . '/core/Plugin.php';
require APP_ROOT . '/core/Theme.php';

/**
 * 错误处理：debug=0 时禁止向页面输出路径/SQL/堆栈，仅写服务器错误日志
 */
function app_error_handler($errno, $errstr, $errfile, $errline)
{
    error_log(sprintf('[blog] %s at %s:%d', $errstr, $errfile, $errline));
    if (app_debug_enabled()) {
        return false; // 调试模式交给默认输出
    }
    return true;
}

/**
 * 读取 debug 开关：DB 不可用时 Option 查询会抛错，必须回退安全默认 0（不泄露）
 */
function app_debug_enabled()
{
    try {
        return Option::get('debug', '0') === '1';
    } catch (Throwable $ignored) {
        return false;
    }
}

/**
 * 500 兜底文案：零依赖双语（异常时 DB 可能不可用，只读 Accept-Language 首语言族）
 */
function app_500_message()
{
    $al = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? strtolower(trim($_SERVER['HTTP_ACCEPT_LANGUAGE'])) : '';
    $first = trim(preg_replace('/;.*/', '', strtok($al, ',')));
    if ($first !== '' && strpos($first, 'zh') !== 0) {
        return 'Internal server error, please try again later.';
    }
    return '服务器内部错误，请稍后再试';
}

function app_exception_handler($ex)
{
    error_log('[blog] exception: ' . $ex->getMessage() . ' at ' . $ex->getFile() . ':' . $ex->getLine());
    if (!headers_sent()) {
        http_response_code(500);
    }
    if (app_debug_enabled()) {
        echo '<pre>' . e($ex->getMessage()) . '</pre>';
    } else {
        echo app_500_message();
    }
    exit;
}

set_error_handler('app_error_handler');
set_exception_handler('app_exception_handler');

// 未安装（config.php 不存在）时跳转安装程序；安装程序自身不加载本引导
if (!Config::load(APP_ROOT . '/config.php')) {
    $dir = str_replace('\\', '/', dirname(isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/'));
    // 从 user/、install/ 子目录入口进入时回退到站点根，避免拼出 /user/install/ 错误路径
    $dir = preg_replace('#/(user|install)$#', '', $dir);
    $base = ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
    // 相对路径 Location：不拼接 HTTP_HOST（Host 头可伪造，拼绝对地址会形成开放重定向）
    header('Location: ' . $base . '/install/');
    exit;
}

// 数据库连接
DB::init(array(
    'host'    => Config::get('db.host', '127.0.0.1'),
    'port'    => Config::get('db.port', 3306),
    'name'    => Config::get('db.name', ''),
    'user'    => Config::get('db.user', ''),
    'pass'    => Config::get('db.pass', ''),
    'prefix'  => Config::get('db.prefix', 'cb_'),
    'charset' => 'utf8mb4',
));

// 站点时区：默认 UTC+8（Asia/Shanghai），影响 now()/date_fmt()/日志等全部时间
// 非法值回退默认，避免 date() 报警告
$timezone = Option::get('timezone', 'Asia/Shanghai');
if (!in_array($timezone, timezone_identifiers_list(), true)) {
    $timezone = 'Asia/Shanghai';
}
date_default_timezone_set($timezone);

// 错误显示策略：debug 关闭时绝不向页面暴露错误
if (Option::get('debug', '0') === '1') {
    ini_set('display_errors', '1');
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', '0');
    error_reporting(E_ALL);
    ini_set('log_errors', '1');
}

// Session：HttpOnly + SameSite=Lax + HTTPS 下 Secure；空闲超时在 Auth::checkSessionTimeout 处理
$secure = is_https();
session_set_cookie_params(array(
    'lifetime' => 0,
    'path'     => '/',
    'secure'   => $secure,
    'httponly' => true,
    'samesite' => 'Lax',
));
session_name('cb_sid');
session_start();

Auth::checkSessionTimeout();

// 安全响应头（内核统一输出）
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-XSS-Protection: 1; mode=block');

// 加载已启用插件并触发 init
Plugin::loadActive();
do_action('init');

// 审计日志留存期惰性清理（每日最多一次）
Logger::purgeExpired();

// 插件临时缓存（plugin_data 带 expires_at 的行）惰性清理，每日最多一次
plugin_data_purge_expired();

// IP 限流计数器（options 表 throttle_* 行）惰性清理，每日最多一次
ip_throttle_purge();
