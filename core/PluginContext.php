<?php
/** 插件执行上下文：独立于插件发现与钩子分发，异常时也恢复调用方归属。 */
defined('APP_BOOT') or exit;

class PluginContext
{
    private static $slug = null;

    /** 当前归属；null 表示内核或主题代码。 */
    public static function current()
    {
        return self::$slug;
    }

    /** 切换归属并返回旧值，供兼容入口使用。 */
    public static function set($slug)
    {
        $previous = self::$slug;
        self::$slug = $slug;
        return $previous;
    }

    /** 在指定归属下调用回调，支持嵌套并透传返回值与异常。 */
    public static function run($slug, $callback, array $args = array())
    {
        $previous = self::set($slug);
        try {
            return call_user_func_array($callback, $args);
        } finally {
            self::set($previous);
        }
    }
}
