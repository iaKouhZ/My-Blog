<?php
/**
 * CSRF 防护：token 与 Session 及 config.php 密钥绑定，所有改状态请求必须校验
 */
defined('APP_BOOT') or exit;

class Csrf
{
    /**
     * 获取当前会话的 CSRF token（不存在则生成）
     *
     * @return string
     */
    public static function token()
    {
        if (empty($_SESSION['_csrf'])) {
            $_SESSION['_csrf'] = hash_hmac(
                'sha256',
                bin2hex(random_bytes(16)) . '|' . session_id(),
                (string) Config::get('key')
            );
        }
        return $_SESSION['_csrf'];
    }

    /**
     * 输出隐藏表单域
     *
     * @return string HTML
     */
    public static function field()
    {
        return '<input type="hidden" name="_csrf" value="' . e(self::token()) . '">';
    }

    /**
     * 校验 token（默认取 POST 中的 _csrf）
     *
     * @param string|null $token 待校验 token
     * @return bool
     */
    public static function check($token = null)
    {
        if ($token === null) {
            $token = input_password('_csrf', '', 128, 'post');
        }
        if (!is_string($token) || $token === '' || empty($_SESSION['_csrf'])) {
            return false;
        }
        return hash_equals($_SESSION['_csrf'], $token);
    }

    /**
     * 校验失败直接终止请求（用于状态变更入口）
     *
     * @return void
     */
    public static function verifyOrDie()
    {
        if (!self::check()) {
            http_response_code(419);
            exit('CSRF token check failed');
        }
    }
}
