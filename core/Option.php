<?php
/**
 * options 表访问器：单次请求内缓存，站点级配置与开关统一入口
 */
defined('APP_BOOT') or exit;

class Option
{
    /** @var array|null 选项缓存 */
    private static $cache = null;

    /**
     * 载入全部选项到内存
     *
     * @return array
     */
    public static function all()
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $cache = array();
        $rows = DB::query('options')->where('option_key', 'NOT LIKE', 'throttle\_%')
            ->select(array('option_key', 'option_value'));
        foreach ($rows as $row) {
            $cache[$row['option_key']] = $row['option_value'];
        }
        return self::$cache = $cache;
    }

    /**
     * 读取选项
     *
     * @param string $key     选项键
     * @param mixed  $default 缺省值
     * @return mixed
     */
    public static function get($key, $default = null)
    {
        $all = self::all();
        return array_key_exists($key, $all) ? $all[$key] : $default;
    }

    /**
     * 写入选项（存在则更新）
     *
     * @param string $key   选项键
     * @param mixed  $value 选项值（数组将 JSON 编码）
     * @return void
     */
    public static function set($key, $value)
    {
        if (is_array($value)) {
            $value = json_encode($value);
        }
        DB::query('options')->upsert(array('option_key' => $key, 'option_value' => (string) $value), array('option_value'));
        if (self::$cache !== null) {
            self::$cache[$key] = (string) $value;
        }
    }

    /**
     * 读取 JSON 选项为数组
     *
     * @param string $key     选项键
     * @param array  $default 缺省值
     * @return array
     */
    public static function getJson($key, array $default = array())
    {
        $raw = self::get($key, '');
        if (!is_string($raw) || $raw === '') {
            return $default;
        }
        $data = json_decode($raw, true);
        return is_array($data) ? $data : $default;
    }

    /**
     * 安装/卸载场景批量重置缓存
     *
     * @return void
     */
    public static function resetCache()
    {
        self::$cache = null;
    }
}
