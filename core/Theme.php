<?php
/**
 * 模板机制：主题定位与渲染 + 模板 API（模板内禁止直接操作 DB，数据经 $ctx 注入）
 */
defined('APP_BOOT') or exit;

class Theme
{
    /** 语言偏好 cookie 名（Front::langSwitch 写入，langLoad 读取） */
    const LANG_COOKIE = 'cb_theme_lang';

    /** @var string|null 当前主题语言码（zh_CN / en_US），null 表示尚未解析 */
    private static $langCode = null;

    /** @var array|null 当前语言包文案 */
    private static $langMessages = null;

    /** @var array|null 主题中文基线文案 */
    private static $langBaseline = null;

    /** @var string 前台 <html lang> 属性值（BCP47） */
    private static $langLocale = 'zh-CN';

    /** 当前启用主题目录名 */
    public static function current()
    {
        $theme = Option::get('active_theme', 'default');
        if (!preg_match('/^[a-z0-9_-]{1,64}$/', $theme) || !is_dir(self::dirOf($theme))) {
            $theme = 'default';
        }
        return $theme;
    }

    /** 主题绝对路径 */
    public static function dirOf($theme)
    {
        return APP_ROOT . '/themes/' . $theme;
    }

    /** 当前主题绝对路径 */
    public static function dir()
    {
        return self::dirOf(self::current());
    }

    /** 当前主题静态资源 URL（附文件修改时间版本号，避免浏览器缓存旧样式/脚本） */
    public static function assetsUrl($path = '')
    {
        $url = Router::base() . '/themes/' . self::current() . '/' . ltrim($path, '/');
        $file = self::dir() . '/' . ltrim($path, '/');
        if (is_file($file)) {
            $url .= '?v=' . filemtime($file);
        }
        return $url;
    }

    /**
     * 读取某主题的配置（后台「模板管理 → 设置」维护，存 options.theme_settings_{dir}）
     *
     * @param string $theme 主题目录名
     * @return array 配置键值对，无配置返回空数组
     */
    public static function settingsOf($theme)
    {
        if (!preg_match('/^[a-z0-9_-]{1,64}$/', $theme)) {
            return array();
        }
        $json = Option::get('theme_settings_' . $theme, '');
        if ($json === '') {
            return array();
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : array();
    }

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
     * 语言切换链接：携带当前页地址作为切换后的回跳目标
     *
     * @param string $code 目标语言码
     * @return string
     */
    public static function langSwitchUrl($code)
    {
        $redirect = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
        // 仅接受站内相对路径（与评论提交回跳同口径），否则切换后回首页
        if (!preg_match('#^[A-Za-z0-9/_.?=&%-]+$#', $redirect)
            || strpos($redirect, '//') !== false
            || strpos($redirect, Router::base() . '/') !== 0) {
            $redirect = Router::base() . '/';
        }
        return Router::url('lang_switch', array('code' => $code, 'redirect' => $redirect));
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
        $baseline = self::readLangPack(self::dir() . '/langs/zh_CN.php');
        self::$langBaseline = $baseline !== null ? $baseline : array();

        $packs = self::scanLangPacks();
        $code = null;
        // 1. 访客经语言切换器的手动选择
        $cookie = isset($_COOKIE[self::LANG_COOKIE]) ? (string) $_COOKIE[self::LANG_COOKIE] : '';
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
        $dir = self::dir() . '/langs';
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

    /**
     * 渲染主题模板
     *
     * @param string $template 模板名（index/single/page/archive/search/404/login/register/forgot）
     * @param array  $ctx      上下文数据
     * @return void
     */
    public static function render($template, array $ctx = array())
    {
        $GLOBALS['cb_ctx'] = $ctx;
        $file = self::dir() . '/' . $template . '.php';
        if (!is_file($file)) {
            $file = self::dir() . '/index.php';
        }
        // 模板函数文件（主题自有钩子/助手）
        $functions = self::dir() . '/functions.php';
        if (is_file($functions)) {
            require_once $functions;
        }
        extract($ctx, EXTR_SKIP);
        include $file;
    }

    /** 引入主题局部模板（header/footer/sidebar 等） */
    public static function part($name)
    {
        $file = self::dir() . '/' . $name . '.php';
        if (is_file($file)) {
            include $file;
        }
    }

    /**
     * 读取某主题的设置清单（主题目录内可选 settings.php 声明，后台设置页据此渲染）
     *
     * 清单为 key => 定义数组；定义字段白名单：label/type/hint/maxlength/options/default。
     * type 支持 text/textarea/checkbox/select；非法条目整体丢弃。
     * 插件可经 theme_settings_schema 过滤器追加字段。
     *
     * @param string $theme 主题目录名
     * @return array 清洗后的字段清单，未提供返回空数组
     */
    public static function settingsSchema($theme)
    {
        static $cache = array();
        if (array_key_exists($theme, $cache)) {
            return $cache[$theme];
        }
        $schema = array();
        if (preg_match('/^[a-z0-9_-]{1,64}$/', $theme)) {
            $file = self::dirOf($theme) . '/settings.php';
            if (is_file($file)) {
                $raw = include $file;
                if (is_array($raw)) {
                    $schema = self::sanitizeSchema($raw);
                }
            }
        }
        $schema = apply_filters('theme_settings_schema', $schema, $theme);
        $cache[$theme] = is_array($schema) ? $schema : array();
        return $cache[$theme];
    }

    /** 清单清洗：键名与字段定义白名单校验，防止主题/插件注入非法字段结构 */
    private static function sanitizeSchema(array $raw)
    {
        $schema = array();
        foreach ($raw as $key => $def) {
            if (!is_string($key) || !preg_match('/^[a-z0-9_]{1,64}$/', $key) || !is_array($def)) {
                continue;
            }
            $type = isset($def['type']) ? (string) $def['type'] : 'text';
            if (!in_array($type, array('text', 'textarea', 'checkbox', 'select'), true)) {
                continue;
            }
            $field = array(
                'label' => isset($def['label']) ? mb_substr((string) $def['label'], 0, 100) : $key,
                'type'  => $type,
                'hint'  => isset($def['hint']) ? mb_substr((string) $def['hint'], 0, 255) : '',
                'default' => isset($def['default']) ? mb_substr((string) $def['default'], 0, 500) : '',
            );
            $maxLen = isset($def['maxlength']) ? (int) $def['maxlength'] : 255;
            $field['maxlength'] = max(1, min($maxLen, 500));
            if ($type === 'select') {
                // 选项键同样白名单校验；键即存储值，值为展示文案
                $opts = isset($def['options']) && is_array($def['options']) ? $def['options'] : array();
                $options = array();
                foreach ($opts as $optValue => $optLabel) {
                    $optKey = (string) $optValue;
                    if (preg_match('/^[a-z0-9_]{1,64}$/', $optKey)) {
                        $options[$optKey] = mb_substr((string) $optLabel, 0, 100);
                    }
                }
                if (empty($options)) {
                    continue;
                }
                $field['options'] = $options;
            }
            $schema[$key] = $field;
        }
        return $schema;
    }

    /**
     * 扫描 themes/ 全部主题元数据（读取 style.css 头部）
     *
     * @return array 目录名 => 元数据
     */
    public static function discover()
    {
        $themes = array();
        $dir = APP_ROOT . '/themes';
        if (!is_dir($dir)) {
            return $themes;
        }
        foreach (scandir($dir) as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $css = $dir . '/' . $item . '/style.css';
            if (!is_file($css)) {
                continue;
            }
            $meta = self::headerOf($css);
            $meta['dir'] = $item;
            if ($meta['name'] === '') {
                $meta['name'] = $item;
            }
            $themes[$item] = $meta;
        }
        return $themes;
    }

    /**
     * 解析 style.css 头部注释元数据（Theme Name/Author/Version/Description，只读前 2048 字节）
     *
     * @param string $cssFile style.css 路径
     * @return array name/author/version/description（缺字段为空串）
     */
    public static function headerOf($cssFile)
    {
        $head = file_get_contents($cssFile, false, null, 0, 2048);
        $meta = array('name' => '', 'author' => '', 'version' => '', 'description' => '');
        if ($head === false) {
            return $meta;
        }
        $map = array('name' => 'Theme Name', 'author' => 'Author', 'version' => 'Version', 'description' => 'Description');
        foreach ($map as $key => $label) {
            if (preg_match('/\/\*[\s\S]*?' . preg_quote($label, '/') . '\s*:\s*([^\n*]+)/i', $head, $m)) {
                $meta[$key] = trim($m[1]);
            }
        }
        return $meta;
    }
}

/* ---------- 模板 API（供 themes/ 内模板调用） ---------- */

/** 站点名 */
function site_name()
{
    return Option::get('site_name', '个人博客');
}

/** 一句话座右铭 */
function site_motto()
{
    return Option::get('site_motto', '');
}

/** 站点描述（SEO） */
function site_description()
{
    return Option::get('site_description', '');
}

/** 当前页面标题（含站点名后缀） */
function page_title()
{
    $ctx = cb_ctx();
    $title = isset($ctx['title']) && $ctx['title'] !== '' ? $ctx['title'] . ' - ' : '';
    return $title . site_name();
}

/** 读取模板上下文 */
function cb_ctx()
{
    return isset($GLOBALS['cb_ctx']) ? $GLOBALS['cb_ctx'] : array();
}

/** 站点作者卡片信息（首位管理员） */
function site_author()
{
    static $author = null;
    if ($author === false) {
        return null;
    }
    if ($author !== null) {
        return $author;
    }
    $author = DB::query('users')
        ->where('role', '=', 'admin')
        ->where('status', '=', 1)
        ->orderBy('id', 'ASC')
        ->first(array('id', 'username', 'nickname', 'avatar', 'signature'));
    if ($author === null) {
        $author = false;
        return null;
    }
    return $author;
}

/** 头像 URL（空头像回退默认） */
function avatar_url($avatar)
{
    if (!empty($avatar)) {
        return Router::base() . '/' . ltrim($avatar, '/');
    }
    return assets_url('admin/default-avatar.svg');
}

/**
 * 自定义导航项（后台「导航管理」维护，存 options.nav_items，按保存顺序返回）。
 * 支持一层父子层级：顶层项含 children 数组（可能为空）；子项仅 title/url。
 * 顶层项链接可为空（纯文本分组标签，仅在有子项时保留）。
 * 旧数据（无 children 字段）自动兼容，逐项补空 children。
 */
function nav_items()
{
    static $items = null;
    if ($items !== null) {
        return $items;
    }
    $items = array();
    $json = Option::get('nav_items', '');
    if ($json === '') {
        return $items;
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return $items;
    }
    foreach ($decoded as $row) {
        if (!isset($row['title'], $row['url']) || $row['title'] === '') {
            continue;
        }
        $children = array();
        if (isset($row['children']) && is_array($row['children'])) {
            foreach ($row['children'] as $child) {
                if (isset($child['title'], $child['url'])
                    && $child['title'] !== '' && $child['url'] !== '') {
                    $children[] = array(
                        'title' => (string) $child['title'],
                        'url'   => (string) $child['url'],
                    );
                }
            }
        }
        // 空链接顶层项仅作分组标签：无子项时无展示意义，丢弃
        if ((string) $row['url'] === '' && empty($children)) {
            continue;
        }
        $items[] = array(
            'title'    => (string) $row['title'],
            'url'      => (string) $row['url'],
            'children' => $children,
        );
    }
    return $items;
}

/**
 * 独立页面导航列表（如“关于我”）
 * 仅返回后台勾选了“显示在侧边栏导航”的已发布页面；新建页面默认不自动加入
 */
function nav_pages()
{
    static $pages = null;
    if ($pages !== null) {
        return $pages;
    }
    $navIds = array();
    foreach (Option::getJson('nav_page_ids', array()) as $navId) {
        $navIds[] = (int) $navId;
    }
    $navIds = array_values(array_unique($navIds));
    if (empty($navIds)) {
        $pages = array();
        return $pages;
    }
    $pages = DB::query('posts')
        ->where('is_page', '=', 1)
        ->where('status', '=', 'published')
        ->whereIn('id', $navIds)
        ->orderBy('created_at', 'ASC')
        ->select(array('id', 'title', 'slug'));
    return $pages;
}

/** 分类导航列表（含文章数） */
function nav_categories()
{
    static $list = null;
    if ($list !== null) {
        return $list;
    }
    $list = DB::query('categories')
        ->orderBy('sort', 'ASC')
        ->select(array('id', 'name', 'slug'));
    // 附带已发布文章数（一次聚合查询）
    $counts = array();
    $rows = DB::query('posts')
        ->where('status', '=', 'published')
        ->where('is_page', '=', 0)
        ->select(array('category_id'));
    foreach ($rows as $row) {
        $cid = (int) $row['category_id'];
        $counts[$cid] = isset($counts[$cid]) ? $counts[$cid] + 1 : 1;
    }
    foreach ($list as &$cat) {
        $cat['count'] = isset($counts[(int) $cat['id']]) ? $counts[(int) $cat['id']] : 0;
    }
    unset($cat);
    return $list;
}

/** 当前文章列表（由控制器注入） */
function the_posts()
{
    $ctx = cb_ctx();
    return isset($ctx['posts']) ? $ctx['posts'] : array();
}

/** 文章详情 */
function the_post()
{
    $ctx = cb_ctx();
    return isset($ctx['post']) ? $ctx['post'] : null;
}

/** 渲染文章正文：Markdown→安全 HTML，并应用 post_content 过滤器 */
function render_content($markdown)
{
    $html = Markdown::render($markdown);
    return apply_filters('post_content', $html);
}

/** 分页 HTML */
function paginate($page, $totalPages, $route, array $params = array())
{
    if ($totalPages <= 1) {
        return '';
    }
    $html = '<nav class="pagination">';
    $urlOf = function ($p) use ($route, $params) {
        $params['page'] = $p;
        return Router::url($route, $params);
    };
    if ($page > 1) {
        $html .= '<a class="page-link" href="' . e($urlOf($page - 1)) . '">« ' . e(theme_t('theme.common.page_prev', array(), '上一页')) . '</a>';
    }
    for ($i = 1; $i <= $totalPages; $i++) {
        if ($totalPages > 10 && abs($i - $page) > 3 && $i !== 1 && $i !== $totalPages) {
            if ($i === 2 || $i === $totalPages - 1) {
                $html .= '<span class="page-dots">…</span>';
            }
            continue;
        }
        if ($i === $page) {
            $html .= '<span class="page-link current">' . $i . '</span>';
        } else {
            $html .= '<a class="page-link" href="' . e($urlOf($i)) . '">' . $i . '</a>';
        }
    }
    if ($page < $totalPages) {
        $html .= '<a class="page-link" href="' . e($urlOf($page + 1)) . '">' . e(theme_t('theme.common.page_next', array(), '下一页')) . ' »</a>';
    }
    return $html . '</nav>';
}

/**
 * 后台分页导航（layui-laypage 结构）：仅多于 1 页时输出；
 * 页码 >10 时折叠为首尾 + 当前页附近，其余省略号
 *
 * @param int    $page       当前页码
 * @param int    $totalPages 总页数
 * @param string $urlBase    m=模块/动作 参数前缀（以 & 结尾），助手追加 page=N
 * @return string
 */
function admin_pager($page, $totalPages, $urlBase)
{
    if ($totalPages <= 1) {
        return '';
    }
    $visible = array();
    for ($i = 1; $i <= $totalPages; $i++) {
        if ($totalPages <= 10 || $i === 1 || $i === $totalPages || abs($i - $page) <= 2) {
            $visible[] = $i;
        }
    }
    $html = '<div class="layui-box layui-laypage">';
    if ($page > 1) {
        $html .= '<a href="' . e(site_base_admin($urlBase . 'page=' . ($page - 1))) . '">« ' . e(admin_t('admin.common.page_prev')) . '</a>';
    }
    $prev = 0;
    foreach ($visible as $i) {
        if ($prev > 0 && $i - $prev > 1) {
            $html .= '<span>…</span>';
        }
        if ($i === (int) $page) {
            // laypage 当前页：em 双层结构（底色层 + 文本层）为 layui 约定标记
            $html .= '<span class="layui-laypage-curr"><em class="layui-laypage-em"></em><em>' . $i . '</em></span>';
        } else {
            $html .= '<a href="' . e(site_base_admin($urlBase . 'page=' . $i)) . '">' . $i . '</a>';
        }
        $prev = $i;
    }
    if ($page < $totalPages) {
        $html .= '<a href="' . e(site_base_admin($urlBase . 'page=' . ($page + 1))) . '">' . e(admin_t('admin.common.page_next')) . ' »</a>';
    }
    return $html . '</div>';
}

/** 页脚版权行 */
function copyright_line()
{
    $startYear = Option::get('site_created_year', date('Y'));
    $currentYear = date('Y');
    $range = $startYear === $currentYear ? $currentYear : $startYear . '-' . $currentYear;
    return '© ' . $range . ' ' . site_name() . ' All rights Reserved.';
}

/** 当前主题配置项（后台「模板管理 → 设置」维护，未设置返回缺省值） */
function theme_setting($key, $default = '')
{
    $settings = Theme::settingsOf(Theme::current());
    if (isset($settings[$key]) && (string) $settings[$key] !== '') {
        return (string) $settings[$key];
    }
    return $default;
}

/** ICP 备案号（未配置返回空字符串，前台据此隐藏） */
function icp_number()
{
    return theme_setting('icp_number');
}

/** 公安备案号全文（如“京公网安备 11040102700068 号”，未配置返回空字符串） */
function gongan_number()
{
    return theme_setting('gongan_number');
}

/** 从公安备案文本中提取纯数字编号（用于拼接官方查询链接），提取失败返回空字符串 */
function gongan_code($gongan = null)
{
    if ($gongan === null) {
        $gongan = gongan_number();
    }
    if (preg_match('/(\d{6,})/', $gongan, $m)) {
        return $m[1];
    }
    return '';
}

/** 页头输出点（meta + front_head 钩子） */
function theme_head($extra = '')
{
    echo '<meta charset="utf-8">' . "\n";
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">' . "\n";
    echo '<title>' . e(page_title()) . '</title>' . "\n";
    $desc = site_description();
    if ($desc !== '') {
        echo '<meta name="description" content="' . e($desc) . '">' . "\n";
    }
    echo '<link rel="stylesheet" href="' . e(Theme::assetsUrl('style.css')) . '">' . "\n";
    if ($extra !== '') {
        echo $extra . "\n";
    }
    do_action('front_head');
}

/** 页尾输出点 */
function theme_footer()
{
    do_action('front_footer');
    echo '<script src="' . e(Theme::assetsUrl('theme.js')) . '"></script>' . "\n";
}

/** 评论列表渲染数据 */
function the_comments()
{
    $ctx = cb_ctx();
    return isset($ctx['comments']) ? $ctx['comments'] : array();
}

/**
 * 主题文案翻译（Theme::t 简写）：模板与前台控制器共用；
 * 当前语言包与主题中文基线均缺键时回退 $default（内核侧必传）
 *
 * @param string      $key     语义键（theme.{模块}.{语义}）
 * @param array       $args    占位参数（%s 顺序替换）
 * @param string|null $default 兜底中文文案
 * @return string
 */
function theme_t($key, array $args = array(), $default = null)
{
    return Theme::t($key, $args, $default);
}

/**
 * 前台日期格式化：格式串随主题语言包切换（theme.common.date_format），
 * 无语言包主题回退中文格式，与 date_fmt() 输出一致
 *
 * @param string $datetime Y-m-d H:i:s
 * @return string
 */
function theme_date($datetime)
{
    $ts = strtotime($datetime);
    if ($ts === false) {
        return '';
    }
    return date(theme_t('theme.common.date_format', array(), 'Y 年 n 月 j 日'), $ts);
}
