<?php
/**
 * 外链图片策略：可信主机白名单（站点配置 + 插件/主题声明）、CSP img-src 生成、安全 img 标签输出
 */
defined('APP_BOOT') or exit;

class ExternalMedia
{
    /** 站点配置项：管理员在「安全设置 → 外链图片信任」维护的 JSON 主机列表 */
    const OPTION_KEY = 'img_ext_hosts';

    /** 白名单条目上限：防止配置被写成巨型列表拖慢每次请求的 CSP 生成 */
    const MAX_HOSTS = 32;

    /** @var array|null 本次请求的主机清单缓存（configured + 钩子注入合并结果） */
    private static $cache = null;

    /**
     * 站点配置的可信主机（已规范化、去重、限量）
     *
     * @return array
     */
    public static function configuredHosts()
    {
        return self::sanitizeList(Option::getJson(self::OPTION_KEY, array()));
    }

    /**
     * 生效的可信主机清单 = 站点配置 + 插件/主题经 front_img_src_hosts 过滤器声明
     * 内核不硬编码任何域名：第三方来源一律由管理员在对应插件/主题设置页填写后注入
     *
     * @return array 规范化后的主机规则（可含 *. 通配前缀）
     */
    public static function hosts()
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $extra = apply_filters('front_img_src_hosts', array());
        self::$cache = self::sanitizeList(array_merge(
            self::configuredHosts(),
            is_array($extra) ? $extra : array()
        ));
        return self::$cache;
    }

    /**
     * 主机规范化：仅接受精确域名或 *.example.com 形式；拒绝 IP 字面量、端口、
     * 路径、userinfo 与 localhost（IP 无法表达“可信图床”，且易被写入云元数据地址）
     *
     * @param mixed $raw 原始输入（可含回车、空格、协议前缀）
     * @return string|null 规范化结果；非法返回 null
     */
    public static function normalizeHost($raw)
    {
        if (!is_string($raw)) {
            return null;
        }
        // 容忍管理员粘贴 "https://i0.hdslb.com/xxx" 这类整段 URL：只取主机部分
        $value = strtolower(trim($raw));
        if ($value === '') {
            return null;
        }
        if (strpos($value, '://') !== false) {
            $parts = parse_url($value);
            $value = is_array($parts) && isset($parts['host']) ? $parts['host'] : '';
            if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
                return null;
            }
        }
        $host = rtrim($value, '.');
        if ($host === '' || strlen($host) > 253) {
            return null;
        }
        $wildcard = false;
        if (substr($host, 0, 2) === '*.') {
            $wildcard = true;
            $host = substr($host, 2);
        }
        // 逐标签校验：只允许字母数字与连字符，禁止端口/路径/空白混入
        if (!preg_match('/^[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?(?:\.[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?)+$/', $host)) {
            return null;
        }
        // 上述正则同样能匹配 192.168.1.1，IP 字面量必须单独排除
        if (filter_var($host, FILTER_VALIDATE_IP) !== false || $host === 'localhost') {
            return null;
        }
        return ($wildcard ? '*.' : '') . $host;
    }

    /**
     * 列表清洗：逐项规范化、去重保序、截断到上限
     *
     * @param array $list 原始条目
     * @return array
     */
    public static function sanitizeList(array $list)
    {
        $out = array();
        foreach ($list as $item) {
            $host = self::normalizeHost($item);
            if ($host !== null && !in_array($host, $out, true)) {
                $out[] = $host;
                if (count($out) >= self::MAX_HOSTS) {
                    break;
                }
            }
        }
        return $out;
    }

    /**
     * URL 是否命中可信主机：仅接受 https 绝对地址（http 在 HTTPS 站点会被浏览器按混合内容拦截）
     *
     * @param mixed $url 待判定地址
     * @return bool
     */
    public static function isAllowedUrl($url)
    {
        if (!is_string($url) || $url === '') {
            return false;
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host']) || !isset($parts['scheme'])) {
            return false;
        }
        if (strtolower($parts['scheme']) !== 'https') {
            return false;
        }
        // 带 userinfo 或端口的地址不参与白名单匹配，避免 i0.hdslb.com@evil.com 一类歧义
        if (isset($parts['user']) || isset($parts['pass']) || isset($parts['port'])) {
            return false;
        }
        $host = strtolower($parts['host']);
        foreach (self::hosts() as $rule) {
            if (substr($rule, 0, 2) === '*.') {
                $suffix = substr($rule, 1); // ".hdslb.com"
                // 长度必须大于后缀，排除裸域名本身（*.example.com 不含 example.com）
                if (strlen($host) > strlen($suffix) && substr($host, -strlen($suffix)) === $suffix) {
                    return true;
                }
            } elseif ($host === $rule) {
                return true;
            }
        }
        return false;
    }

    /**
     * CSP img-src 取值：白名单为空时输出与放宽前完全一致（'self' data:）
     *
     * @return string
     */
    public static function cspImgSources()
    {
        $sources = array("'self'", 'data:');
        foreach (self::hosts() as $rule) {
            $sources[] = 'https://' . $rule;
        }
        return implode(' ', $sources);
    }

    /**
     * 前台受限 CSP 完整取值：只随白名单放宽 img-src，脚本/样式等指令维持原水位
     *
     * @return string
     */
    public static function cspHeader()
    {
        return "default-src 'self'; img-src " . self::cspImgSources()
            . "; style-src 'self'; script-src 'self'; font-src 'self'"
            . "; object-src 'none'; base-uri 'self'; frame-ancestors 'self'";
    }

    /**
     * 输出外链图片标签：未命中白名单返回空串（调用方无需再判空，模板可直接 echo）
     *
     * @param string $url  图片地址（须 https 且命中白名单）
     * @param array  $opts alt / class
     * @return string
     */
    public static function imgTag($url, array $opts = array())
    {
        if (!self::isAllowedUrl($url)) {
            return '';
        }
        $tag = '<img src="' . e($url) . '" alt="'
            . e(isset($opts['alt']) ? $opts['alt'] : '') . '"';
        if (!empty($opts['class'])) {
            $tag .= ' class="' . e($opts['class']) . '"';
        }
        // 第三方图床普遍按 Referer 防盗链：内核统一输出 strict-origin-when-cross-origin，
        // 跨站取图会带本站 origin 而被拒（实测 B 站图床返回 403）。单图声明 no-referrer
        // 即可，不改全站 Referrer-Policy。
        return $tag . ' referrerpolicy="no-referrer" loading="lazy" decoding="async">';
    }
}

/**
 * 模板/插件通用简写：返回已转义且带 no-referrer 的 <img>，白名单外返回空串
 *
 * @param string $url  图片地址
 * @param array  $opts alt / class
 * @return string
 */
function ext_img($url, array $opts = array())
{
    return ExternalMedia::imgTag($url, $opts);
}
