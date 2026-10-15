<?php
/** 主题语言服务：语言偏好解析、语言包加载与降级，不承担模板渲染。 */
defined('APP_BOOT') or exit;

class ThemeLocale
{
    /** @var string|null 当前主题语言码（zh_CN / en_US），null 表示尚未解析 */
    private static $langCode = null;

    /** @var array|null 当前语言包文案 */
    private static $langMessages = null;

    /** @var array|null 主题中文基线文案 */
    private static $langBaseline = null;

    /** @var string 前台 <html lang> 属性值（BCP47） */
    private static $langLocale = 'zh-CN';

    /* ---------- 主题多语言（前台 i18n） ---------- */

    /**
     * 主题文案翻译：当前语言包 → 主题中文基线 → $default → 原样返回 key；支持 %s 占位
     *
     * @param string      $key     语义键（theme.{模块}.{语义}）
     * @param array       $args    占位参数
     * @param string|null $default 双侧均缺失时的兜底文案；内核侧调用必传，
     *                             保证无 langs/ 目录的主题行为与硬编码中文时期一致
     * @return string
     */
    public static function t($key, array $args = array(), $default = null)
    {
        self::langLoad();
        $text = null;
        if (isset(self::$langMessages[$key]) && is_string(self::$langMessages[$key])) {
            $text = self::$langMessages[$key];
        } elseif (isset(self::$langBaseline[$key]) && is_string(self::$langBaseline[$key])) {
            $text = self::$langBaseline[$key];
        }
        if ($text === null) {
            $text = $default !== null ? $default : $key;
        }
        return msg_format($text, $args);
    }

    /**
     * 当前主题语言码（如 zh_CN）
     *
     * @return string
     */
    public static function langCode()
    {
        self::langLoad();
        return self::$langCode;
    }

    /**
     * 前台 <html lang> 属性值（BCP47，如 zh-CN / en-US）
     *
     * @return string
     */
    public static function locale()
    {
        self::langLoad();
        return self::$langLocale;
    }

    /**
     * 当前主题可用语言列表（语言切换器用）：仅含主题 langs/ 目录实有语言包
     *
     * @return array 语言码 => 显示名（包内 _name，缺省为语言码本身）
     */
    public static function availableLangs()
    {
        self::langLoad();
        $list = array();
        foreach (self::scanLangPacks() as $code => $file) {
            $data = self::readLangPack($file);
            if ($data === null) {
                continue;
            }
            $list[$code] = isset($data['_name']) && is_string($data['_name']) && $data['_name'] !== ''
                ? $data['_name'] : $code;
        }
        return $list;
    }

    /**
     * 懒加载：解析语言链 访客 cookie 手动选择 → 浏览器 Accept-Language
     * → 后台 admin_locale（主题存在同名包时沿用）→ 中文基线；每请求一次
     */
    private static function langLoad()
    {
        if (self::$langCode !== null) {
            return;
        }
        $baseline = self::readLangPack(Theme::dir() . '/langs/zh_CN.php');
        self::$langBaseline = $baseline !== null ? $baseline : array();

        $packs = self::scanLangPacks();
        $code = null;
        // 1. 访客经语言切换器的手动选择
        $cookie = isset($_COOKIE[Theme::LANG_COOKIE]) && is_string($_COOKIE[Theme::LANG_COOKIE]) ? $_COOKIE[Theme::LANG_COOKIE] : '';
        if (self::isValidLangCode($cookie) && isset($packs[$cookie])) {
            $code = $cookie;
        }
        // 2. 浏览器终端语言（按 q 值降序首个命中主题实有包者胜出）
        if ($code === null) {
            $code = self::detectBrowserLang($packs);
        }
        // 3. 后台设置的后台语言，主题存在对应包则沿用
        if ($code === null) {
            $admin = (string) Option::get('admin_locale', 'zh_CN');
            if (self::isValidLangCode($admin) && isset($packs[$admin])) {
                $code = $admin;
            }
        }
        // 4. 最终降级中文；包文件缺失时基线为空数组，文案由 t() 的 $default 兜底
        if ($code === null) {
            $code = 'zh_CN';
        }
        self::$langCode = $code;
        self::$langMessages = array();
        if ($code !== 'zh_CN' && isset($packs[$code])) {
            $data = self::readLangPack($packs[$code]);
            if ($data !== null) {
                self::$langMessages = $data;
            }
        }
        // html lang：包声明的 _locale 优先（仅允许字母与连字符），否则按语言码转换
        $source = $code === 'zh_CN' ? self::$langBaseline : self::$langMessages;
        $declared = isset($source['_locale']) && is_string($source['_locale']) ? $source['_locale'] : '';
        if ($declared !== '' && preg_match('/^[A-Za-z-]+$/', $declared)) {
            self::$langLocale = $declared;
        } else {
            self::$langLocale = str_replace('_', '-', $code);
        }
    }

    /**
     * 浏览器语言探测：解析 Accept-Language，按 q 值降序取首个命中主题包的语言码。
     * 标头仅作白名单匹配，不参与任何路径拼接
     *
     * @param array $packs 语言码 => 文件绝对路径
     * @return string|null 命中的语言码，未命中返回 null
     */
    private static function detectBrowserLang(array $packs)
    {
        if (empty($packs)) {
            return null;
        }
        $header = isset($_SERVER['HTTP_ACCEPT_LANGUAGE']) ? trim((string) $_SERVER['HTTP_ACCEPT_LANGUAGE']) : '';
        // 长度钳制：标头为用户可控输入，防爆栈式超长串
        if ($header === '' || strlen($header) > 512) {
            return null;
        }
        $prefs = array();
        $order = 0;
        foreach (explode(',', $header) as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            $q = 1.0;
            $tag = $part;
            if (strpos($part, ';') !== false) {
                $seg = explode(';', $part, 2);
                $tag = trim($seg[0]);
                if (preg_match('/q=([0-9.]+)/', $seg[1], $qm)) {
                    $q = (float) $qm[1];
                }
            }
            if ($q <= 0) {
                continue;
            }
            $prefs[] = array('tag' => strtolower($tag), 'q' => $q, 'order' => $order++);
        }
        // q 值降序；同 q 保持标头原序（usort 不稳定，需次序字段兜底）
        usort($prefs, function ($a, $b) {
            if ($a['q'] === $b['q']) {
                return $a['order'] - $b['order'];
            }
            return $a['q'] > $b['q'] ? -1 : 1;
        });
        foreach ($prefs as $pref) {
            $hit = self::matchLangPack($pref['tag'], $packs);
            if ($hit !== null) {
                return $hit;
            }
        }
        return null;
    }

    /**
     * 单个语言标签匹配主题包：先精确（en-us → en_US），再按主标签族
     * （en / en-gb / zh-hans → 首个 en_* / zh_* 包）
     *
     * @param string $tag   已转小写的 BCP47 标签
     * @param array  $packs 语言码 => 文件绝对路径
     * @return string|null
     */
    private static function matchLangPack($tag, array $packs)
    {
        if (!preg_match('/^([a-z]{2})(?:-([a-z]{2}))?/', $tag, $m)) {
            return null;
        }
        if (isset($m[2]) && $m[2] !== '') {
            $exact = $m[1] . '_' . strtoupper($m[2]);
            if (isset($packs[$exact])) {
                return $exact;
            }
        }
        foreach (array_keys($packs) as $code) {
            if (strpos($code, $m[1] . '_') === 0) {
                return $code;
            }
        }
        return null;
    }

    /**
     * 扫描当前主题 langs/ 目录下的合法语言包
     *
     * @return array 语言码 => 文件绝对路径
     */
    private static function scanLangPacks()
    {
        $dir = Theme::dir() . '/langs';
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
            if (self::isValidLangCode($code)) {
                $found[$code] = $file;
            }
        }
        return $found;
    }

    /**
     * 读取主题语言包：include 处于方法作用域（隔离全局变量），返回值必须是数组
     *
     * @param string $file 包文件绝对路径
     * @return array|null 非法包返回 null
     */
    private static function readLangPack($file)
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
    private static function isValidLangCode($code)
    {
        return is_string($code) && (bool) preg_match('/^[a-z]{2}_[A-Z]{2}$/', $code);
    }

}
