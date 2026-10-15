<?php
/**
 * 钩子机制：语义与 WordPress 一致的 add_action/do_action/add_filter/apply_filters
 */
defined('APP_BOOT') or exit;
require_once __DIR__ . '/PluginContext.php';

class Hook
{
    /** @var array 已注册钩子：hook => priority => [entry,...]；entry 含回调与归属插件 */
    private static $hooks = array();

    /**
     * 注册动作或过滤器（同时记录归属插件，供分发时恢复执行上下文）
     *
     * @param string   $hook     钩子名
     * @param callable $callback 回调
     * @param int      $priority 优先级，小者先执行
     * @return void
     */
    public static function add($hook, $callback, $priority = 10)
    {
        $owner = PluginContext::current();
        self::$hooks[$hook][$priority][] = array('cb' => $callback, 'owner' => $owner);
        ksort(self::$hooks[$hook]);
    }

    /**
     * 指定钩子是否有已注册的回调（供分发器判断插件自定义路由等）
     *
     * @param string $hook 钩子名
     * @return bool
     */
    public static function has($hook)
    {
        return !empty(self::$hooks[$hook]);
    }

    /**
     * 触发动作钩子
     *
     * @param string $hook 钩子名
     * @return void
     */
    public static function doAction($hook)
    {
        $args = array_slice(func_get_args(), 1);
        if (empty(self::$hooks[$hook])) {
            return;
        }
        foreach (self::$hooks[$hook] as $entries) {
            foreach ($entries as $entry) {
                PluginContext::run($entry['owner'], $entry['cb'], $args);
            }
        }
    }

    /**
     * 触发过滤器钩子并返回过滤后的值
     *
     * @param string $hook  钩子名
     * @param mixed  $value 待过滤值
     * @return mixed
     */
    public static function applyFilters($hook, $value)
    {
        $args = array_slice(func_get_args(), 2);
        if (empty(self::$hooks[$hook])) {
            return $value;
        }
        foreach (self::$hooks[$hook] as $entries) {
            foreach ($entries as $entry) {
                $params = array_merge(array($value), $args);
                $value = PluginContext::run($entry['owner'], $entry['cb'], $params);
            }
        }
        return $value;
    }
}

/**
 * 注册动作钩子
 */
function add_action($hook, $callback, $priority = 10)
{
    Hook::add($hook, $callback, $priority);
}

/**
 * 触发动作钩子
 */
function do_action($hook)
{
    $args = func_get_args();
    call_user_func_array(array('Hook', 'doAction'), $args);
}

/**
 * 判断钩子是否有注册回调
 */
function has_action($hook)
{
    return Hook::has($hook);
}

/**
 * 注册过滤器钩子
 */
function add_filter($hook, $callback, $priority = 10)
{
    Hook::add($hook, $callback, $priority);
}

/**
 * 应用过滤器钩子
 */
function apply_filters($hook, $value)
{
    $args = func_get_args();
    return call_user_func_array(array('Hook', 'applyFilters'), $args);
}
