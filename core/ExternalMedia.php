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

    /** @var array|null 本次请求的主机清单缓存 */
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
     * 生效的可信主机清单 = 站点配置 + 已启用扩展经管理员确认的静态声明子集。
     *
     * @return array 规范化后的主机规则（可含 *. 通配前缀）
     */
    public static function hosts()
    {
        if (self::$cache !== null) {
            return self::$cache;
        }
        $hosts = self::configuredHosts();
        $active = array('plugin' => Plugin::activeList(), 'theme' => array(Theme::current()));
        foreach ($active as $kind => $ids) {
            foreach ($ids as $id) {
                $declaration = self::declaration($kind, $id);
                if ($declaration['valid'] && self::isApproved($kind, $id, $declaration['digest'])) {
                    $hosts = array_merge($hosts, self::approvedSelection($kind, $id, $declaration));
                }
            }
        }
        self::$cache = self::sanitizeList($hosts);
        return self::$cache;
    }

    /**
     * 读取静态声明；不存在清单表示有效的空声明，不执行扩展 PHP。
     *
     * @param string $kind plugin 或 theme
     * @param string $id 插件 slug 或主题目录名
     * @return array valid、hosts 与 digest
     */
    public static function declaration($kind, $id)
    {
        $invalid = array('valid' => false, 'hosts' => array(), 'digest' => '');
        if ($kind === 'plugin') {
            if (!is_string($id) || !preg_match('/^[a-z0-9-]{1,64}$/', $id)) {
                return $invalid;
            }
            $all = Plugin::discover();
            $dir = isset($all[$id]) ? Plugin::locate($id) : '';
        } elseif ($kind === 'theme') {
            if (!is_string($id) || !preg_match('/^[a-z0-9_-]{1,64}$/', $id)) {
                return $invalid;
            }
            $dir = Theme::dirOf($id);
            if (!is_file($dir . '/style.css')) {
                $dir = '';
            }
        } else {
            return $invalid;
        }
        if ($dir === '') {
            return $invalid;
        }
        $file = $dir . '/external-media.json';
        $hosts = array();
        if (file_exists($file)) {
            if (!is_file($file)) {
                return $invalid;
            }
            $raw = file_get_contents($file, false, null, 0, 32769);
            if (!is_string($raw) || strlen($raw) > 32768) {
                return $invalid;
            }
            $data = is_string($raw) ? json_decode($raw) : null;
            if (!is_object($data) || !isset($data->img_hosts) || !is_array($data->img_hosts)
                || count($data->img_hosts) > self::MAX_HOSTS) {
                return $invalid;
            }
            foreach ($data->img_hosts as $item) {
                $host = self::strictHost($item);
                if ($host === null) {
                    return $invalid;
                }
                if (!in_array($host, $hosts, true)) {
                    $hosts[] = $host;
                }
            }
            sort($hosts, SORT_STRING);
        }
        $digest = hash('sha256', $kind . "\0" . $id . "\0" . json_encode($hosts));
        return array('valid' => true, 'hosts' => $hosts, 'digest' => $digest);
    }

    /** 扩展自身设置键：兼容既有插件 img_hosts 设置，超长 slug 使用短键。 */
    private static function selectionKey($kind, $id)
    {
        $legacy = 'plugin_' . $id . '_img_hosts';
        if ($kind === 'plugin' && strlen($legacy) <= 64) {
            return $legacy;
        }
        return 'img_hosts_' . $kind . '_' . substr(hash('sha256', $id), 0, 40);
    }

    /** 声明确认记录与可编辑设置分离，避免更换文件后沿用旧授权。 */
    private static function approvalKey($kind, $id)
    {
        return 'img_approval_' . $kind . '_' . substr(hash('sha256', $id), 0, 40);
    }

    /** 返回当前可编辑设置；仅初次没有设置时使用清单默认值。 */
    private static function selection($kind, $id, array $declared)
    {
        $key = self::selectionKey($kind, $id);
        $all = Option::all();
        if (!array_key_exists($key, $all)) {
            return $declared;
        }
        $parts = preg_split('/[\r\n,;]+/', (string) $all[$key]);
        $saved = self::sanitizeList(is_array($parts) ? $parts : array());
        return array_values(array_intersect($declared, $saved));
    }

    /** 当前声明是否经管理员确认。 */
    private static function isApproved($kind, $id, $digest)
    {
        $saved = Option::getJson(self::approvalKey($kind, $id), array());
        return isset($saved['digest'], $saved['hosts']) && is_string($saved['digest'])
            && is_array($saved['hosts']) && $digest !== '' && hash_equals($digest, $saved['digest']);
    }

    /** 已批准域名与扩展设置取交集，扩展自行修改设置不能扩大管理员批准范围。 */
    private static function approvedSelection($kind, $id, array $declaration)
    {
        $saved = Option::getJson(self::approvalKey($kind, $id), array());
        $approved = isset($saved['hosts']) && is_array($saved['hosts']) ? self::sanitizeList($saved['hosts']) : array();
        return array_values(array_intersect(self::selection($kind, $id, $declaration['hosts']), $approved));
    }

    /** 会话密钥绑定预览内容，防止客户端用未展示过的新清单伪造确认。 */
    private static function approvalToken($kind, $id, $digest, array $selected)
    {
        if (empty($_SESSION['img_approval_secret']) || !is_string($_SESSION['img_approval_secret'])) {
            $_SESSION['img_approval_secret'] = bin2hex(random_bytes(32));
        }
        return hash_hmac('sha256', $kind . "\0" . $id . "\0" . $digest . "\0" . json_encode($selected), $_SESSION['img_approval_secret']);
    }

    /**
     * 获取管理页展示及确认所需的信息；令牌绑定当前管理员会话和声明。
     *
     * @param string $kind plugin 或 theme
     * @param string $id 扩展标识
     * @return array valid、hosts、selected、digest、token、approved
     */
    public static function approvalInfo($kind, $id)
    {
        $info = self::declaration($kind, $id);
        $info['approved'] = $info['valid'] && self::isApproved($kind, $id, $info['digest']);
        $info['selected'] = !$info['valid'] ? array() : ($info['approved']
            ? self::approvedSelection($kind, $id, $info)
            : self::selection($kind, $id, $info['hosts']));
        $info['token'] = $info['valid'] ? self::approvalToken($kind, $id, $info['digest'], $info['selected']) : '';
        return $info;
    }

    /**
     * 验证确认令牌及设置：任何无效项或非声明子集均拒绝。
     *
     * @param string $kind plugin 或 theme
     * @param string $id 扩展标识
     * @param string $token 管理页签发的确认令牌
     * @param string $text 用户选择的主机文本
     * @param bool $requireApproved 是否仅允许修改已批准的声明
     * @return array|false 规范化主机数组或 false
     */
    public static function validateApproval($kind, $id, $token, $text, $requireApproved = false)
    {
        $info = self::declaration($kind, $id);
        $approved = $info['valid'] && self::isApproved($kind, $id, $info['digest']);
        $selected = !$info['valid'] ? array() : ($approved
            ? self::approvedSelection($kind, $id, $info)
            : self::selection($kind, $id, $info['hosts']));
        if (!$info['valid'] || !is_string($token) || strlen($token) !== 64
            || !hash_equals(self::approvalToken($kind, $id, $info['digest'], $selected), $token)
            || ($requireApproved && !$approved)
            || !is_string($text) || strlen($text) > 12000) {
            return false;
        }
        $hosts = array();
        foreach (preg_split('/[\r\n,;]+/', $text) as $part) {
            if (trim($part) === '') {
                continue;
            }
            $host = self::strictHost($part);
            if ($host === null || !in_array($host, $info['hosts'], true)) {
                return false;
            }
            if (!in_array($host, $hosts, true)) {
                $hosts[] = $host;
            }
        }
        sort($hosts, SORT_STRING);
        if (!$requireApproved && $hosts !== $selected) {
            return false;
        }
        return $hosts;
    }

    /** 确认扩展声明并保存用户选择，返回是否成功。 */
    public static function approve($kind, $id, $token, $text)
    {
        return self::saveApproval($kind, $id, $token, $text, false);
    }

    /** 修改已确认的扩展设置，不得借此确认新声明。 */
    public static function updateApproved($kind, $id, $token, $text)
    {
        return self::saveApproval($kind, $id, $token, $text, true);
    }

    /** 将管理员选择和确认版本一起持久化。 */
    private static function saveApproval($kind, $id, $token, $text, $requireApproved)
    {
        $lock = 'img_approval_' . substr(hash('sha256', $kind . "\0" . $id), 0, 40);
        if (!DB::lock($lock, 5)) {
            return false;
        }
        try {
            // 锁内刷新缓存并再次比对令牌，避免两个管理员覆盖彼此刚确认的选择。
            Option::resetCache();
            $hosts = self::validateApproval($kind, $id, $token, $text, $requireApproved);
            if ($hosts === false) {
                return false;
            }
            $current = self::declaration($kind, $id);
            $approved = $current['valid'] && self::isApproved($kind, $id, $current['digest']);
            $selected = !$current['valid'] ? array() : ($approved
                ? self::approvedSelection($kind, $id, $current)
                : self::selection($kind, $id, $current['hosts']));
            if (!$current['valid'] || !hash_equals(self::approvalToken($kind, $id, $current['digest'], $selected), $token)) {
                return false;
            }
            try {
                DB::transaction(function () use ($kind, $id, $hosts, $current) {
                    Option::set(self::selectionKey($kind, $id), implode("\n", $hosts));
                    Option::set(self::approvalKey($kind, $id), array('digest' => $current['digest'], 'hosts' => $hosts));
                });
            } catch (Throwable $error) {
                Option::resetCache();
                throw $error;
            }
            self::$cache = null;
            return true;
        } finally {
            DB::unlock($lock);
        }
    }

    /** 获取当前启用扩展的确认状态，供后台设置展示。 */
    public static function activeApprovals()
    {
        $result = array('plugin' => array(), 'theme' => array());
        foreach (Plugin::activeList() as $slug) {
            $result['plugin'][$slug] = self::approvalInfo('plugin', $slug);
        }
        $theme = Theme::current();
        $result['theme'][$theme] = self::approvalInfo('theme', $theme);
        return $result;
    }

    /** 扩展声明和设置只接受主机字面量，不接受 URL 或路径。 */
    private static function strictHost($value)
    {
        if (!is_string($value)) {
            return null;
        }
        $value = trim($value);
        if (!preg_match('/^(?:\*\.)?[a-z0-9](?:[a-z0-9.-]*[a-z0-9])?\.?$/i', $value)) {
            return null;
        }
        return self::normalizeHost($value);
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
        // 浏览器会把反斜杠当作路径分隔符，并忽略部分控制字符，必须先拒绝解析歧义。
        if (!is_string($url) || $url === '' || strpos($url, '\\') !== false
            || preg_match('/[\x00-\x20\x7f]/', $url)
            || !preg_match('#^https://#i', $url)) {
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
        // URL 中必须是实际主机，不能把通配符、编码字符等规则外内容送入后缀匹配。
        if (strpos($host, '*') !== false || self::normalizeHost($host) !== $host) {
            return false;
        }
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
