<?php
/**
 * 插件机制：plugins/{slug}/{slug}.php 发现与元数据解析、启用状态管理、插件 API
 */
defined('APP_BOOT') or exit;

class Plugin
{
    /** @var array|null 插件元数据缓存 slug => meta */
    private static $cache = null;

    /** @var string|null 当前正在执行的插件 slug（加载/钩子回调/设置页回调期间非空） */
    private static $currentSlug = null;

    /** 当前执行上下文所属插件（内核上下文返回 null） */
    public static function currentSlug()
    {
        return self::$currentSlug;
    }

    /**
     * 切换执行上下文（返回先前的 slug 供调用方恢复，支持嵌套）
     *
     * @param string|null $slug 插件 slug；null 表示回到内核上下文
     * @return string|null
     */
    public static function setCurrentSlug($slug)
    {
        $prev = self::$currentSlug;
        self::$currentSlug = $slug;
        return $prev;
    }

    /** @var array 验证码渠道声明：channel => 声明者 slug（请求内有效） */
    private static $verifyProviders = array();

    /** 记录验证码渠道声明（仅 register_verify_provider 内部使用） */
    public static function setVerifyProvider($channel, $slug)
    {
        self::$verifyProviders[$channel] = $slug;
    }

    /** 渠道声明者 slug（未声明返回 null） */
    public static function verifyProviderOf($channel)
    {
        return isset(self::$verifyProviders[$channel]) ? self::$verifyProviders[$channel] : null;
    }

    /**
     * 扫描 plugins/ 目录发现全部合法插件
     *
     * @return array slug => 元数据（name/description/version/author/has_settings）
     */
    public static function discover()
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        self::$cache = array();
        $dir = APP_ROOT . '/plugins';
        if (!is_dir($dir)) {
            return self::$cache;
        }
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $main = $dir . '/' . $item . '/' . $item . '.php';
            if (!is_file($main)) {
                continue;
            }
            $meta = self::parseMeta($main);
            if ($meta === null) {
                continue;
            }
            $meta['slug'] = $item;
            self::$cache[$item] = $meta;
        }
        return self::$cache;
    }

    /**
     * 解析插件主文件头部元数据（同 WordPress 格式）
     *
     * @param string $file 主文件路径
     * @return array|null 元数据；缺 Plugin Name 时返回 null（不识别为插件）
     */
    public static function parseMeta($file)
    {
        $head = file_get_contents($file, false, null, 0, 2048);
        if ($head === false) {
            return null;
        }
        $fields = array(
            'name'        => 'Plugin Name',
            'description' => 'Description',
            'version'     => 'Version',
            'author'      => 'Author',
            'requires'    => 'Requires',
        );
        $meta = array();
        foreach ($fields as $key => $label) {
            if (preg_match('/\*\s*' . preg_quote($label, '/') . '\s*:\s*(.+)$/mi', $head, $m)) {
                $meta[$key] = trim($m[1]);
            } else {
                $meta[$key] = '';
            }
        }
        // 头部元数据不合法（缺 Plugin Name）视为非插件
        if ($meta['name'] === '') {
            return null;
        }
        return $meta;
    }

    /**
     * 已启用插件 slug 列表（含自愈：目录已不存在的 slug 自动移除并留痕，
     * 防止“直接删文件夹”式不规范卸载后残留启用状态影响能力判断）
     */
    public static function activeList()
    {
        $list = Option::getJson('active_plugins', array());
        $all = self::discover();
        $valid = array();
        $dropped = array();
        foreach ($list as $slug) {
            if (isset($all[$slug])) {
                $valid[] = $slug;
            } else {
                $dropped[] = $slug;
            }
        }
        if (!empty($dropped)) {
            Option::set('active_plugins', $valid);
            blog_log('plugin', 'plugin.orphan_deactivate', 'success', array(
                'slugs' => implode(',', $dropped),
            ));
        }
        return $valid;
    }

    /** 是否已启用 */
    public static function isActive($slug)
    {
        return in_array($slug, self::activeList(), true);
    }

    /** 加载全部已启用插件主文件（加载期间设置执行上下文，供钩子归属与写入校验使用） */
    public static function loadActive()
    {
        foreach (self::activeList() as $slug) {
            $file = APP_ROOT . '/plugins/' . $slug . '/' . $slug . '.php';
            if (is_file($file)) {
                $prev = self::setCurrentSlug($slug);
                require_once $file;
                self::setCurrentSlug($prev);
            }
        }
    }

    /**
     * 启用插件
     *
     * @param string $slug 插件 slug
     * @return bool
     */
    public static function activate($slug)
    {
        $all = self::discover();
        if (!isset($all[$slug])) {
            return false;
        }
        $list = self::activeList();
        if (!in_array($slug, $list, true)) {
            $list[] = $slug;
            Option::set('active_plugins', $list);
        }
        do_action('plugin_activate', $slug);
        blog_log('plugin', 'plugin.activate', 'success', array('slug' => $slug));
        return true;
    }

    /**
     * 禁用插件
     *
     * @param string $slug 插件 slug
     * @return bool
     */
    public static function deactivate($slug)
    {
        $list = self::activeList();
        $index = array_search($slug, $list, true);
        if ($index === false) {
            return false;
        }
        unset($list[$index]);
        Option::set('active_plugins', array_values($list));
        do_action('plugin_deactivate', $slug);
        blog_log('plugin', 'plugin.deactivate', 'success', array('slug' => $slug));
        return true;
    }

    /**
     * 删除插件目录（管理页二次确认后调用）
     *
     * @param string $slug 插件 slug
     * @return bool
     */
    public static function uninstall($slug)
    {
        $dir = APP_ROOT . '/plugins/' . $slug;
        // 防路径穿越：slug 必须为纯小写字母数字连字符
        if (!preg_match('/^[a-z0-9-]{1,64}$/', $slug) || !is_dir($dir)) {
            return false;
        }
        self::deactivate($slug);
        self::removeDir($dir);
        do_action('plugin_uninstall', $slug);
        // 内核兜底清理残留数据：options 配置、plugin_data 数据、已登记自建表
        self::cleanupData($slug);
        blog_log('plugin', 'plugin.uninstall', 'success', array('slug' => $slug));
        return true;
    }

    /**
     * 清理不规范卸载（直接删文件夹）的残留数据：仅当目录确不存在时执行，
     * 复用卸载清理逻辑（options/plugin_data/自建表全量回收）
     *
     * @param string $slug 插件 slug
     * @return bool
     */
    public static function purgeOrphanData($slug)
    {
        if (!preg_match('/^[a-z0-9-]{1,64}$/', $slug) || is_dir(APP_ROOT . '/plugins/' . $slug)) {
            return false;
        }
        self::cleanupData($slug);
        blog_log('plugin', 'plugin.orphan_cleanup', 'success', array('slug' => $slug));
        return true;
    }

    /**
     * 卸载残留清理：plugin_{slug}_* 选项、plugin_data 中该插件的行、
     * 经 plugin_register_table() 登记的自建表（插件无需自行清理）
     *
     * @param string $slug 插件 slug
     * @return void
     */
    private static function cleanupData($slug)
    {
        try {
            // 先读自建表清单（其 option 行随后会被 LIKE 删除）
            $tables = Option::getJson('plugin_' . $slug . '_tables', array());
            // LIKE 中下划线为通配符，需反斜杠转义确保只匹配 plugin_{slug}_ 前缀
            DB::query('options')
                ->where('option_key', 'LIKE', 'plugin_' . $slug . '\\_%')
                ->delete();
            DB::delete('plugin_data', array('plugin' => $slug));
            foreach ($tables as $name) {
                // 双重白名单校验后才允许拼入 DDL，防注入
                if (preg_match('/^[a-z0-9_]{1,32}$/', $name)) {
                    DB::pdo()->exec('DROP TABLE IF EXISTS `' . plugin_table($slug, $name) . '`');
                }
            }
            Option::resetCache();
        } catch (Exception $ex) {
            error_log('[blog] plugin cleanup failed (' . $slug . '): ' . $ex->getMessage());
        }
    }

    /** 递归删除目录 */
    private static function removeDir($dir)
    {
        $items = scandir($dir);
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir . '/' . $item;
            if (is_dir($path)) {
                self::removeDir($path);
            } else {
                unlink($path);
            }
        }
        rmdir($dir);
    }
}

/**
 * 读取插件配置项（options 键名 plugin_{slug}_{key}）
 *
 * @param string $slug    插件 slug
 * @param string $key     配置键
 * @param mixed  $default 缺省值
 * @return mixed
 */
function plugin_option($slug, $key, $default = null)
{
    return Option::get('plugin_' . $slug . '_' . $key, $default);
}

/**
 * 插件写入命名空间校验：插件执行上下文（加载/钩子回调/设置页回调）内
 * 只允许写自身 slug 的命名空间；内核上下文（null）放行。
 * 违规写入拒绝并记 security 审计（防插件间配置/数据篡改）
 *
 * @param string $slug 目标插件 slug
 * @return bool true 允许写入 / false 已拒绝
 */
function plugin_guard_write($slug)
{
    $current = Plugin::currentSlug();
    if ($current !== null && $current !== $slug) {
        blog_log('security', 'plugin.write_denied', 'fail', array(
            'target' => $slug, 'by' => $current,
        ));
        return false;
    }
    return true;
}

/**
 * 声明验证码渠道能力（验证码发送插件在加载期调用）。
 * 仅允许在插件执行上下文内调用并强制归属当前插件（防冒名声明）；
 * 内核入口探测（注册/找回/改绑）与核验策略读取仅认声明，不硬编码插件名，
 * 第三方插件声明后即可完整接管对应渠道的验证码能力
 *
 * @param string $channel 渠道标识（目前支持 email/sms）
 * @return bool
 */
function register_verify_provider($channel)
{
    if (!in_array($channel, array('email', 'sms'), true)) {
        return false;
    }
    $slug = Plugin::currentSlug();
    if ($slug === null) {
        return false;
    }
    Plugin::setVerifyProvider($channel, $slug);
    return true;
}

/**
 * 渠道声明者 slug（未声明返回 null）
 *
 * @param string $channel 渠道标识
 * @return string|null
 */
function get_verify_provider($channel)
{
    return Plugin::verifyProviderOf($channel);
}

/**
 * 更新插件配置项
 *
 * @param string $slug  插件 slug
 * @param string $key   配置键
 * @param mixed  $value 值
 * @return void
 */
function plugin_option_update($slug, $key, $value)
{
    if (!plugin_guard_write($slug)) {
        return;
    }
    Option::set('plugin_' . $slug . '_' . $key, $value);
}

/**
 * 插件静态资源 URL
 *
 * @param string $slug 插件 slug
 * @param string $path 相对路径
 * @return string
 */
function plugin_url($slug, $path = '')
{
    return Router::base() . '/plugins/' . $slug . '/' . ltrim($path, '/');
}

/**
 * 注册插件后台设置页（admin_menu 钩子中调用）
 *
 * @param string   $slug     插件 slug
 * @param string   $title    菜单标题
 * @param callable $callback 渲染回调
 * @return void
 */
function register_plugin_page($slug, $title, $callback)
{
    $GLOBALS['cb_plugin_pages'][$slug] = array('title' => $title, 'callback' => $callback);
}

/**
 * 读取插件语言包：slug 与语言码白名单校验（防路径穿越），解析失败静默降级为空数组
 *
 * @param string $plugin 插件 slug
 * @param string $code   语言码（xx_XX）
 * @return array
 */
function plugin_lang_pack($plugin, $code)
{
    if (!preg_match('/^[a-z0-9][a-z0-9_-]*$/', $plugin) || !preg_match('/^[a-z]{2}_[A-Z]{2}$/', $code)) {
        return array();
    }
    $file = APP_ROOT . '/plugins/' . $plugin . '/langs/' . $code . '.php';
    if (!is_file($file)) {
        return array();
    }
    $data = include $file;
    return is_array($data) ? $data : array();
}

/**
 * 插件文案翻译：插件自带语言包 plugins/{slug}/langs/{code}.php（return array(...)，
 * 文件头须带 APP_BOOT 守卫），zh_CN.php 为必备基线。
 * 查找顺序：当前语言插件包 → 插件 zh_CN 基线 → $default → 原样返回键名。
 * 语言缺省取站点默认语言（Lang::code()，即 admin_locale），外发邮件等无请求上下文的
 * 场景同样适用；可用 $lang 强制指定。
 *
 * @param string $plugin  插件 slug
 * @param string $key     文案键
 * @param array  $args    msg_format 位置参数（%s 占位）
 * @param string $default 中文缺省（包内缺失时兜底，保证未附带语言包时可运行）
 * @param string $lang    强制语言码（null 表示站点默认）
 * @return string
 */
function plugin_t($plugin, $key, array $args = array(), $default = '', $lang = null)
{
    static $packs = array();
    $code = ($lang !== null && $lang !== '') ? $lang : Lang::code();
    if (!isset($packs[$plugin])) {
        $packs[$plugin] = array();
    }
    if (!array_key_exists($code, $packs[$plugin])) {
        $packs[$plugin][$code] = plugin_lang_pack($plugin, $code);
    }
    $text = null;
    if (isset($packs[$plugin][$code][$key]) && is_string($packs[$plugin][$code][$key]) && $packs[$plugin][$code][$key] !== '') {
        $text = $packs[$plugin][$code][$key];
    } elseif ($code !== 'zh_CN') {
        if (!array_key_exists('zh_CN', $packs[$plugin])) {
            $packs[$plugin]['zh_CN'] = plugin_lang_pack($plugin, 'zh_CN');
        }
        if (isset($packs[$plugin]['zh_CN'][$key]) && is_string($packs[$plugin]['zh_CN'][$key]) && $packs[$plugin]['zh_CN'][$key] !== '') {
            $text = $packs[$plugin]['zh_CN'][$key];
        }
    }
    if ($text === null) {
        $text = $default !== '' ? $default : $key;
    }
    return msg_format($text, $args);
}

/**
 * 获取已注册的插件设置页
 *
 * @return array
 */
function get_plugin_pages()
{
    return isset($GLOBALS['cb_plugin_pages']) ? $GLOBALS['cb_plugin_pages'] : array();
}

/* ================= 插件通用数据存储（plugin_data 表） ================= */

/**
 * 写入插件全局数据（ttl>0 时为临时缓存，到期自动失效）
 *
 * @param string $slug  插件 slug
 * @param string $key   数据键（≤191 字符）
 * @param mixed  $value 值（数组自动 JSON 编码）
 * @param int    $ttl   存活秒数，0 表示永久
 * @return void
 */
function plugin_data_set($slug, $key, $value, $ttl = 0)
{
    plugin_data_write($slug, 'global', 0, $key, $value, $ttl);
}

/**
 * 读取插件全局数据（过期行视为不存在）
 *
 * @param string $slug    插件 slug
 * @param string $key     数据键
 * @param mixed  $default 缺省值
 * @return mixed
 */
function plugin_data_get($slug, $key, $default = null)
{
    return plugin_data_read($slug, 'global', 0, $key, $default);
}

/**
 * 删除插件全局数据
 */
function plugin_data_delete($slug, $key)
{
    if (!plugin_guard_write($slug)) {
        return;
    }
    DB::delete('plugin_data', array('plugin' => $slug, 'scope' => 'global', 'user_id' => 0, 'data_key' => $key));
}

/**
 * 写入插件用户级数据（替代 usermeta：第三方账号绑定等场景）
 */
function plugin_user_set($slug, $userId, $key, $value)
{
    plugin_data_write($slug, 'user', (int) $userId, $key, $value, 0);
}

/**
 * 读取插件用户级数据
 */
function plugin_user_get($slug, $userId, $key, $default = null)
{
    return plugin_data_read($slug, 'user', (int) $userId, $key, $default);
}

/**
 * 删除插件用户级数据
 */
function plugin_user_delete($slug, $userId, $key)
{
    if (!plugin_guard_write($slug)) {
        return;
    }
    DB::delete('plugin_data', array('plugin' => $slug, 'scope' => 'user', 'user_id' => (int) $userId, 'data_key' => $key));
}

/** 统一写入：存在则更新，不存在则插入（唯一索引兜底；跨命名空间写入被拒绝） */
function plugin_data_write($slug, $scope, $userId, $key, $value, $ttl)
{
    if (!plugin_guard_write($slug)) {
        return;
    }
    if (is_array($value)) {
        $value = json_encode($value);
    }
    $where = array('plugin' => $slug, 'scope' => $scope, 'user_id' => (int) $userId, 'data_key' => $key);
    $row = array(
        'data_value' => (string) $value,
        'expires_at' => $ttl > 0 ? date('Y-m-d H:i:s', time() + (int) $ttl) : null,
        'updated_at' => now(),
    );
    $exists = DB::query('plugin_data')->whereMap($where)->value('id');
    if ($exists !== null) {
        DB::update('plugin_data', $row, array('id' => (int) $exists));
    } else {
        DB::insert('plugin_data', array_merge($where, $row, array('created_at' => now())));
    }
}

/** 统一读取：行不存在或已过期返回缺省值 */
function plugin_data_read($slug, $scope, $userId, $key, $default)
{
    $row = DB::query('plugin_data')
        ->where('plugin', '=', $slug)
        ->where('scope', '=', $scope)
        ->where('user_id', '=', (int) $userId)
        ->where('data_key', '=', $key)
        ->first();
    if ($row === null) {
        return $default;
    }
    if ($row['expires_at'] !== null && strtotime($row['expires_at']) < time()) {
        return $default;
    }
    return $row['data_value'];
}

/**
 * 过期临时数据清理（每日最多一次，由 bootstrap 惰性触发）
 *
 * @return void
 */
function plugin_data_purge_expired()
{
    $last = (int) Option::get('plugin_data_purge_at', 0);
    if (time() - $last < 86400) {
        return;
    }
    Option::set('plugin_data_purge_at', time());
    try {
        // expires_at 为 NULL 的行不会命中 < 比较，永久数据不受影响
        DB::query('plugin_data')->where('expires_at', '<', now())->delete();
    } catch (Exception $ex) {
        error_log('[blog] plugin_data purge failed: ' . $ex->getMessage());
    }
}

/* ================= 插件自建表（复杂场景才用，卸载自动 DROP） ================= */

/**
 * 登记插件自建表：表名限定 [a-z0-9_]{1,32}，卸载时内核自动 DROP
 *
 * @param string $slug 插件 slug
 * @param string $name 表短名（不含前缀）
 * @return bool
 */
function plugin_register_table($slug, $name)
{
    if (!preg_match('/^[a-z0-9_]{1,32}$/', $name)) {
        return false;
    }
    if (!plugin_guard_write($slug)) {
        return false;
    }
    $key = 'plugin_' . $slug . '_tables';
    $tables = Option::getJson($key, array());
    if (!in_array($name, $tables, true)) {
        $tables[] = $name;
        Option::set($key, $tables);
    }
    return true;
}

/**
 * 插件自建表完整表名（含前缀，slug 中连字符转下划线以满足标识符规则）
 *
 * @param string $slug 插件 slug
 * @param string $name 表短名
 * @return string
 */
function plugin_table($slug, $name)
{
    return DB::table('plugin_' . str_replace('-', '_', $slug) . '_' . $name);
}
