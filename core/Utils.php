<?php
/**
 * 通用工具：HTML 转义、客户端 IP、统一输入校验器、脱敏、跳转等
 */
defined('APP_BOOT') or exit;

/**
 * HTML 输出转义：所有输出到页面的变量必须经此函数
 *
 * @param mixed $value 待转义值
 * @return string
 */
function e($value)
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

/**
 * 后台文案翻译（Lang::t 简写）：当前语言包缺失键时自动降级中文基线
 *
 * @param string $key  语义键（admin.{模块}.{语义}）
 * @param array  $args 占位参数（%s 顺序替换）
 * @return string
 */
function admin_t($key, array $args = array())
{
    return Lang::t($key, $args);
}

/**
 * 文案占位格式化：顺序替换 %s（%% 转义为 %）；参数不足时保留原占位符，不抛警告
 * Lang::t / Theme::t / Auth::msgOf / ZipSafe::msgOf 共用的唯一实现
 *
 * @param string $text 含占位的文案
 * @param array  $args 占位参数
 * @return string
 */
function msg_format($text, array $args)
{
    if (empty($args)) {
        return $text;
    }
    $i = 0;
    return preg_replace_callback('/%%|%s/', function ($m) use ($args, &$i) {
        if ($m[0] === '%%') {
            return '%';
        }
        return isset($args[$i]) ? (string) $args[$i++] : $m[0];
    }, $text);
}

/**
 * 是否 HTTPS 环境
 *
 * @return bool
 */
function is_https()
{
    if (!empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
        return true;
    }
    return isset($_SERVER['SERVER_PORT']) && (int) $_SERVER['SERVER_PORT'] === 443;
}

/**
 * 客户端 IP 统一获取入口（全站唯一允许方式，禁止直接读 REMOTE_ADDR）
 * 默认只信 REMOTE_ADDR；仅当 ip_header_enabled=1 且指定标头名时才从标头取值，
 * 且必须经 FILTER_VALIDATE_IP 校验、XFF 逗号链取第一个 IP、非法回退 REMOTE_ADDR。
 * 标头名支持英文逗号分隔多个，在前优先级高，按序依次尝试，某个标头取到合法 IP 即停止
 *
 * @return string
 */
function client_ip()
{
    $remote = isset($_SERVER['REMOTE_ADDR']) ? trim($_SERVER['REMOTE_ADDR']) : '';
    if (!filter_var($remote, FILTER_VALIDATE_IP)) {
        $remote = '0.0.0.0';
    }
    if (Option::get('ip_header_enabled', '0') !== '1') {
        return $remote;
    }
    $raw = (string) Option::get('ip_header_name', 'X-Forwarded-For');
    // 多标头：英文逗号分隔，在前优先；逐个尝试，取到合法 IP 即返回
    foreach (explode(',', $raw) as $name) {
        $name = trim($name);
        // 标头名只允许字母、数字、连字符；空/非法片段跳过
        if ($name === '' || !preg_match('/^[A-Za-z0-9-]+$/', $name)) {
            continue;
        }
        $serverKey = 'HTTP_' . strtoupper(str_replace('-', '_', $name));
        if (!isset($_SERVER[$serverKey]) || trim($_SERVER[$serverKey]) === '') {
            continue;
        }
        $list = explode(',', $_SERVER[$serverKey]);
        $first = trim($list[0]);
        if (filter_var($first, FILTER_VALIDATE_IP)) {
            return $first;
        }
    }
    return $remote;
}

/**
 * 客户端 User-Agent（截断 255）
 *
 * @return string
 */
function client_ua()
{
    $ua = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '';
    return mb_substr($ua, 0, 255);
}

/**
 * 302 跳转并终止
 *
 * @param string $url 目标地址
 * @return void
 */
function redirect($url)
{
    header('Location: ' . $url);
    exit;
}

/**
 * 输出 JSON 响应并终止
 *
 * @param array $data 响应数据（建议含 code/msg）
 * @return void
 */
function json_out(array $data)
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 在 <script> 内输出 JSON 字面量的统一入口（防御纵深）
 * HEX 四标志把 < > & ' " 全部转为 ，即使值未来引入用户可控数据，
 * 也无法经 </script> 或属性逃逸改变文档结构
 *
 * @param mixed $data 任意可 JSON 序列化数据
 * @return string
 */
function json_out_script($data)
{
    return json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE);
}

/**
 * 统一输入校验器：整数
 *
 * @param string $key     参数名
 * @param int    $default 缺省值
 * @param string $source  get/post/request
 * @return int
 */
function input_int($key, $default = 0, $source = 'request')
{
    $src = input_source($source);
    if (!isset($src[$key]) || $src[$key] === '') {
        return $default;
    }
    $valid = filter_var($src[$key], FILTER_VALIDATE_INT);
    return $valid !== false ? (int) $valid : $default;
}

/**
 * 统一输入校验器：文本（长度上限，去首尾空白）
 *
 * @param string $key     参数名
 * @param string $default 缺省值
 * @param int    $maxLen  最大长度
 * @param string $source  get/post/request
 * @return string
 */
function input_text($key, $default = '', $maxLen = 255, $source = 'request')
{
    $src = input_source($source);
    if (!isset($src[$key]) || !is_string($src[$key])) {
        return $default;
    }
    $value = trim($src[$key]);
    if ($value === '') {
        return $default;
    }
    return mb_substr($value, 0, $maxLen);
}

/**
 * 统一输入校验器：长文本（正文等）
 *
 * @param string $key     参数名
 * @param string $default 缺省值
 * @param int    $maxLen  最大长度
 * @return string
 */
function input_longtext($key, $default = '', $maxLen = 200000)
{
    $src = input_source('post');
    if (!isset($src[$key]) || !is_string($src[$key])) {
        return $default;
    }
    $value = $src[$key];
    return mb_substr($value, 0, $maxLen);
}

/**
 * 统一输入校验器：邮箱
 *
 * @param string $key     参数名
 * @param string $default 缺省值
 * @param string $source  get/post/request
 * @return string 合法邮箱或空串
 */
function input_email($key, $default = '', $source = 'request')
{
    $src = input_source($source);
    $value = isset($src[$key]) ? trim((string) $src[$key]) : '';
    if ($value === '') {
        return $default;
    }
    return filter_var($value, FILTER_VALIDATE_EMAIL) ? mb_substr($value, 0, 128) : '';
}

/**
 * 统一输入校验器：中国大陆手机号
 *
 * @param string $key     参数名
 * @param string $default 缺省值
 * @param string $source  get/post/request
 * @return string 合法手机号或空串
 */
function input_phone($key, $default = '', $source = 'request')
{
    $src = input_source($source);
    $value = isset($src[$key]) ? trim((string) $src[$key]) : '';
    return preg_match('/^1[3-9]\d{9}$/', $value) ? $value : $default;
}

/**
 * 统一输入校验器：别名 slug（小写字母数字连字符）
 *
 * @param string $key     参数名
 * @param string $default 缺省值
 * @param string $source  get/post/request
 * @return string
 */
function input_slug($key, $default = '', $source = 'request')
{
    $src = input_source($source);
    $value = isset($src[$key]) ? trim((string) $src[$key]) : '';
    if (!preg_match('/^[a-z0-9][a-z0-9-]{0,99}$/', $value)) {
        return $default;
    }
    return $value;
}

/**
 * 统一输入校验器：枚举白名单
 *
 * @param string $key     参数名
 * @param array  $allowed 允许值列表
 * @param string $default 缺省值
 * @param string $source  get/post/request
 * @return string
 */
function input_enum($key, array $allowed, $default = '', $source = 'request')
{
    $src = input_source($source);
    $value = isset($src[$key]) ? (string) $src[$key] : '';
    return in_array($value, $allowed, true) ? $value : $default;
}

/**
 * 统一输入校验器：口令/密钥原文（密码、AccessKeySecret、SMTP 授权码等）
 * 只做字符串化与长度上限，不 trim、不过滤——口令原文须逐字节保留，
 * 强度判定由 Auth::validate_password_strength() 另行负责
 *
 * @param string $key     参数名
 * @param string $default 缺省值
 * @param int    $maxLen  最大长度（字节）
 * @param string $source  get/post/request
 * @return string
 */
function input_password($key, $default = '', $maxLen = 128, $source = 'post')
{
    $src = input_source($source);
    if (!isset($src[$key]) || !is_string($src[$key]) || $src[$key] === '') {
        return $default;
    }
    return substr($src[$key], 0, $maxLen);
}

/**
 * 取输入源数组
 */
function input_source($source)
{
    if ($source === 'get') {
        return $_GET;
    }
    if ($source === 'post') {
        return $_POST;
    }
    return array_merge($_GET, $_POST);
}

/**
 * 列表日期范围筛选生效窗口（日志中心/评论管理共用，GET 参数 from/to）
 * 首次进入（两参数均未提交）默认"一个月前 ~ 当天"；
 * 已显式提交（含清空为空值）则严格按提交值，单侧为空即该侧不限
 *
 * @return array [from, to]，yyyy-MM-dd 或空字符串
 */
function filter_date_range()
{
    $src = input_source('get');
    $from = input_text('from', '', 10, 'get');
    $to = input_text('to', '', 10, 'get');
    $from = preg_match('/^\d{4}-\d{2}-\d{2}$/', $from) ? $from : '';
    $to = preg_match('/^\d{4}-\d{2}-\d{2}$/', $to) ? $to : '';
    // 仅作存在性判断区分"首次进入"与"清空后提交"，参数值本身仍经校验器取得
    if ($from === '' && $to === '' && !isset($src['from']) && !isset($src['to'])) {
        $to = date('Y-m-d');
        $from = date('Y-m-d', strtotime('-1 month'));
    }
    return array($from, $to);
}

/**
 * 邮箱脱敏：a***@x.com
 *
 * @param string $email 邮箱
 * @return string
 */
function mask_email($email)
{
    $at = strpos($email, '@');
    if ($at === false) {
        return '***';
    }
    $name = substr($email, 0, $at);
    $keep = substr($name, 0, 1);
    return $keep . '***@' . substr($email, $at + 1);
}

/**
 * 手机号脱敏：138****1234
 *
 * @param string $phone 手机号
 * @return string
 */
function mask_phone($phone)
{
    if (!preg_match('/^\d{11}$/', $phone)) {
        return '***';
    }
    return substr($phone, 0, 3) . '****' . substr($phone, 7);
}

/**
 * CSV 单元格公式注入中和：以 = + - @ 或 TAB/CR 开头的值前置单引号，
 * 防止导出的 CSV 在 Excel/WPS 中被当作公式执行（DDE/HYPERLINK 外泄）
 *
 * @param string $value 原始单元格值
 * @return string
 */
function csv_safe($value)
{
    $value = (string) $value;
    if ($value !== '' && strpbrk(substr($value, 0, 1), '=+-@' . "\t\r") !== false) {
        return "'" . $value;
    }
    return $value;
}

/**
 * 随机字符串（字母数字）
 *
 * @param int $len 长度
 * @return string
 */
function random_string($len = 32)
{
    $out = '';
    $chars = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $len; $i++) {
        $out .= $chars[random_int(0, $max)];
    }
    return $out;
}

/**
 * 当前时间（Y-m-d H:i:s）
 *
 * @return string
 */
function now()
{
    return date('Y-m-d H:i:s');
}

/**
 * Markdown 原文转纯文本摘要
 *
 * @param string $markdown 原文
 * @param int    $len      截取长度
 * @return string
 */
function excerpt_of($markdown, $len = 150)
{
    $text = preg_replace('/```[\s\S]*?```/u', ' ', (string) $markdown);
    $text = preg_replace('/[`*#>\[\]!\|\-]{1,3}/u', '', $text);
    $text = preg_replace('/\s+/u', ' ', trim($text));
    return mb_substr($text, 0, $len);
}

/**
 * 站点根相对 URL（拼接基础路径）
 *
 * @param string $path 以 / 开头的站内路径
 * @return string
 */
function site_url_path($path)
{
    return Router::base() . $path;
}

/**
 * 静态资源 URL（附文件修改时间版本号，避免浏览器缓存旧资源）
 *
 * @param string $path assets 下相对路径，如 admin/style.css
 * @return string
 */
function assets_url($path)
{
    $url = Router::base() . '/assets/' . ltrim($path, '/');
    $file = APP_ROOT . '/assets/' . ltrim($path, '/');
    if (is_file($file)) {
        $url .= '?v=' . filemtime($file);
    }
    return $url;
}

/**
 * 闪存消息写入（一次有效）
 *
 * @param string $type success/error
 * @param string $text 消息内容
 * @return void
 */
function flash_set($type, $text)
{
    $_SESSION['_flash'][] = array('type' => $type, 'text' => $text);
}

/**
 * 取出并清空全部闪存消息
 *
 * @return array
 */
function flash_pull()
{
    if (empty($_SESSION['_flash'])) {
        return array();
    }
    $list = $_SESSION['_flash'];
    unset($_SESSION['_flash']);
    return $list;
}

/**
 * 限流计数器键中的客户端 IP 归一化：
 * IPv4 原样使用；IPv6 折叠为 /64 前缀（同一运营商分配块），
 * 防止客户端在 /64 内轮换源地址使每个请求命中全新计数器桶、绕过限流
 *
 * @param string $ip 已验证的客户端 IP
 * @return string
 */
function throttle_ip_key($ip)
{
    // 先验合法性再转换（inet_pton 对非法输入会告警，不做错误抑制）
    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
        return $ip;
    }
    $packed = inet_pton($ip);
    if ($packed === false) {
        return $ip;
    }
    // IPv4-mapped IPv6（::ffff:a.b.c.d，双栈监听下 REMOTE_ADDR 的常见形态）：
    // 内嵌的是 IPv4，按其自身归一（否则全部折叠成 :: 单桶，全站 IPv4 访客共限）
    if (substr($packed, 0, 12) === "\x00\x00\x00\x00\x00\x00\x00\x00\x00\x00\xff\xff") {
        return inet_ntop(substr($packed, 12));
    }
    // 普通 IPv6：取前 8 字节（/64），后 8 字节清零
    return inet_ntop(substr($packed, 0, 8) . str_repeat("\x00", 8));
}

/**
 * IP 维度分钟窗口限流：options 表独立计数器，不依赖审计表聚合。
 * 计数器键含客户端 IP 散列，被限流的请求由调用方自行决定是否写审计，
 * 避免攻击者以高频请求向只增不改的审计表持续灌数据。
 * 读-改-写经 MySQL 命名锁互斥，并发请求不会因竞态少计而超放
 *
 * @param string $bucket       业务桶名（login/verify_send 等）
 * @param int    $maxPerMinute 同一 IP 每分钟上限
 * @return bool true 放行 / false 超限
 */
function ip_throttle_allow($bucket, $maxPerMinute)
{
    $key = 'throttle_' . $bucket . '_' . md5(throttle_ip_key(client_ip()));
    $now = time();
    // 3 秒等待：临界区仅一次 options 读写（毫秒级），零超时会把双击提交等
    // 轻微并发误判为限流；等待超时仍取不到才按超限处理（fail-closed）
    if (!DB::lock('throttle', 3)) {
        return false;
    }
    try {
        // Option 缓存可能在等锁之前已载入；必须在锁内读取最新计数，避免并发请求覆盖。
        $stored = DB::query('options')->where('option_key', '=', $key)->value('option_value');
        $parts = explode('|', (string) $stored);
        $start = isset($parts[0]) && $parts[0] !== '' ? (int) $parts[0] : 0;
        $count = isset($parts[1]) ? (int) $parts[1] : 0;
        if ($now - $start >= 60) {
            $start = $now;
            $count = 0;
        }
        $count++;
        Option::set($key, $start . '|' . $count);
        return $count <= $maxPerMinute;
    } finally {
        DB::unlock('throttle');
    }
}

/**
 * 限流计数器惰性清理（每日最多一次，由 bootstrap 触发）：
 * 删除窗口开始时间超过 1 小时的过期计数器行，防止 options 表无限增长
 *
 * @return void
 */
function ip_throttle_purge()
{
    $last = (int) Option::get('throttle_last_purge', 0);
    if (time() - $last < 86400) {
        return;
    }
    Option::set('throttle_last_purge', time());
    try {
        // LIKE 中下划线为通配符，需反斜杠转义确保只匹配 throttle_ 前缀
        $rows = DB::query('options')
            ->where('option_key', 'LIKE', 'throttle\_%')
            ->select(array('option_key', 'option_value'));
        foreach ($rows as $row) {
            if ($row['option_key'] === 'throttle_last_purge') {
                continue;
            }
            $parts = explode('|', (string) $row['option_value']);
            // 格式为 start|count（窗口起点|计数）：start 是判龄时间戳，
            // 活跃窗口（<1h）保留，过期窗口删除
            $start = isset($parts[0]) ? (int) $parts[0] : 0;
            if ($start > 0 && $start < time() - 3600) {
                DB::delete('options', array('option_key' => $row['option_key']));
            }
        }
    } catch (Exception $ex) {
        error_log('[blog] throttle purge failed: ' . $ex->getMessage());
    }
}

/**
 * 时间友好格式化
 *
 * @param string $datetime Y-m-d H:i:s
 * @return string
 */
function date_fmt($datetime)
{
    $ts = strtotime($datetime);
    if ($ts === false) {
        return '';
    }
    return date('Y 年 n 月 j 日', $ts);
}
