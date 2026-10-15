<?php
/** CLI 回归测试公共断言；禁止通过 HTTP 执行。 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
defined('APP_BOOT') or exit;
error_reporting(E_ALL);
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});

class TestSuite
{
    private static $passed = 0;
    private static $failed = 0;

    /** 每个用例独立报告，失败只打印名称与受控断言消息。 */
    public static function run($name, $test)
    {
        try {
            call_user_func($test);
            self::$passed++;
        } catch (Throwable $ex) {
            self::$failed++;
            fwrite(STDERR, 'FAIL ' . $name . ': ' . $ex->getMessage() . PHP_EOL);
        }
    }

    /** 严格断言，不把业务数据或口令写入测试输出。 */
    public static function check($condition, $message = 'assertion failed')
    {
        if (!$condition) {
            throw new RuntimeException($message);
        }
    }

    /** 返回进程状态，便于本地与 CI 使用相同入口。 */
    public static function finish()
    {
        echo self::$passed . ' passed, ' . self::$failed . ' failed' . PHP_EOL;
        return self::$failed === 0 ? 0 : 1;
    }
}
