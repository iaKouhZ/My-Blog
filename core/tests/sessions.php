<?php
/** 真实 PHP 会话配置回归：隔离会话目录与名称，验证固定会话 ID 不被采纳。 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('APP_BOOT', true);
defined('APP_BOOT') or exit;
require __DIR__ . '/support.php';
require dirname(__DIR__) . '/Utils.php';
session_save_path(sys_get_temp_dir());
TestSuite::run('strict cookie session rejects a chosen nonexistent ID', function () {
    $_SERVER['HTTPS'] = 'off';
    $_SERVER['SERVER_PORT'] = '80';
    $chosen = bin2hex(random_bytes(24));
    session_id($chosen);
    start_secure_session('myblog_test_sid');
    try {
        TestSuite::check(session_id() !== $chosen);
        TestSuite::check(ini_get('session.use_strict_mode') === '1' && ini_get('session.use_only_cookies') === '1');
        TestSuite::check(ini_get('session.use_trans_sid') === '0');
        $params = session_get_cookie_params();
        TestSuite::check($params['httponly'] && !$params['secure'] && $params['samesite'] === 'Lax');
        TestSuite::check(session_cache_limiter() === 'nocache');
    } finally {
        session_destroy();
    }
});
TestSuite::run('HTTPS session cookies are secure', function () {
    $_SERVER['HTTPS'] = 'on';
    start_secure_session('myblog_test_sid');
    try {
        TestSuite::check(session_get_cookie_params()['secure']);
    } finally {
        session_destroy();
    }
});
exit(TestSuite::finish());
