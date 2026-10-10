<?php
/**
 * 后台多语言：语言包扫描发现、按需加载、中文基线降级
 * 目录约定（AGENTS.md §2.3）：中文基线包放 core/langs/zh_CN.php（最终降级方案）；
 * 其余语言包一律放 assets/langs/xx_XX.php，仅允许手动上传，无后台上传入口。
 * 语言包为可执行 PHP（return array），assets/langs 由重写规则 + 包文件头
 * defined('APP_BOOT') or exit 双重屏蔽直接访问。
 */
defined('APP_BOOT') or exit;

class Lang
{
    /** @var string|null 当前语言码（zh_CN / en_US），null 表示尚未加载 */
    private static $code = null;

    /** @var array|null 当前语言包文案（zh_CN 时为空数组，直接用基线） */
    private static $messages = null;

    /** @var array|null 中文基线文案 */
    private static $baseline = null;

    /** @var string <html lang> 属性值 */
    private static $htmlLocale = 'zh-CN';

    /**
     * 翻译查询：当前语言包 → 中文基线 → 原样返回 key；支持 %s 占位
     *
     * @param string $key  语义键（admin.{模块}.{语义}）
     * @param array  $args 占位参数
     * @return string
     */
    public static function t($key, array $args = array())
    {
        self::load();
        $text = null;
        if (isset(self::$messages[$key]) && is_string(self::$messages[$key])) {
            $text = self::$messages[$key];
        } elseif (isset(self::$baseline[$key]) && is_string(self::$baseline[$key])) {
            $text = self::$baseline[$key];
        }
        if ($text === null) {
            // 双侧均缺失：原样返回 key，便于排查遗漏
            $text = $key;
        }
        return msg_format($text, $args);
    }

    /**
     * 当前语言码（如 zh_CN）
     *
     * @return string
     */
    public static function code()
    {
        self::load();
        return self::$code;
    }

    /**
     * 当前 <html lang> 属性值（BCP47，如 zh-CN / en-US）
     *
     * @return string
     */
    public static function locale()
    {
        self::load();
        return self::$htmlLocale;
    }

    /**
     * 可用语言列表（设置页下拉用）：中文基线恒在，其余来自 assets/langs 扫描；
     * 目录不存在或为空时列表仅含中文——即"无语言包自动降级中文"
     *
     * @return array 语言码 => 显示名
     */
    public static function available()
    {
        self::load();
        $name = isset(self::$baseline['_name']) ? (string) self::$baseline['_name'] : '中文（简体）';
        $list = array('zh_CN' => $name);
        foreach (self::scanPackFiles() as $code => $file) {
            $data = self::readPack($file);
            if ($data === null) {
                continue;
            }
            $list[$code] = isset($data['_name']) && is_string($data['_name']) && $data['_name'] !== ''
                ? $data['_name'] : $code;
        }
        return $list;
    }

    /**
     * 语言码是否有对应实体（基线或 assets/langs 包文件）
     *
     * @param string $code 语言码
     * @return bool
     */
    public static function exists($code)
    {
        if ($code === 'zh_CN') {
            return true;
        }
        $files = self::scanPackFiles();
        return isset($files[$code]);
    }

    /** 懒加载：读 admin_locale 选项，校验合法性后加载包与基线（每请求一次） */
    private static function load()
    {
        if (self::$code !== null) {
            return;
        }
        $baseline = self::readPack(APP_ROOT . '/core/langs/zh_CN.php');
        self::$baseline = $baseline !== null ? $baseline : array();

        $code = (string) Option::get('admin_locale', 'zh_CN');
        if (!self::isValidCode($code) || !self::exists($code)) {
            // 存储值非法或包文件已被删除：自动降级中文
            $code = 'zh_CN';
        }
        self::$code = $code;
        self::$messages = array();
        if ($code !== 'zh_CN') {
            $files = self::scanPackFiles();
            $data = isset($files[$code]) ? self::readPack($files[$code]) : null;
            if ($data !== null) {
                self::$messages = $data;
            }
        }
        // html lang：包声明的 _locale 优先（仅允许字母与连字符），否则按语言码转换
        $declared = isset(self::$messages['_locale']) && is_string(self::$messages['_locale'])
            ? self::$messages['_locale'] : '';
        if ($declared !== '' && preg_match('/^[A-Za-z-]+$/', $declared)) {
            self::$htmlLocale = $declared;
        } else {
            self::$htmlLocale = str_replace('_', '-', $code);
        }
    }

    /**
     * 扫描 assets/langs 下合法语言包文件
     *
     * @return array 语言码 => 文件绝对路径
     */
    private static function scanPackFiles()
    {
        $dir = APP_ROOT . '/assets/langs';
        $found = array();
        if (!is_dir($dir)) {
            return $found;
        }
        $files = glob($dir . '/*.php');
        if (!is_array($files)) {
            return $found;
        }
        foreach ($files as $file) {
            $code = basename($file, '.php');
            if (self::isValidCode($code)) {
                $found[$code] = $file;
            }
        }
        return $found;
    }

    /**
     * 读取语言包：include 处于方法作用域（隔离全局变量），返回值必须是数组
     *
     * @param string $file 包文件绝对路径
     * @return array|null 非法包返回 null
     */
    private static function readPack($file)
    {
        if (!is_file($file)) {
            return null;
        }
        $data = include $file;
        return is_array($data) ? $data : null;
    }

    /**
     * 语言码格式白名单：xx_XX（两位小写 + 下划线 + 两位大写）
     *
     * @param string $code 语言码
     * @return bool
     */
    private static function isValidCode($code)
    {
        return is_string($code) && (bool) preg_match('/^[a-z]{2}_[A-Z]{2}$/', $code);
    }
}
