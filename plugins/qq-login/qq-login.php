<?php
/**
 * Plugin Name: QQ登录
 * Description: 使用 QQ 账号登录站点：登录页注入 QQ 图标入口，个人资料页支持绑定/解绑/换绑；回调地址 /qqlogin-callback（需在 QQ 开放平台登记）
 * Version: 1.0.0
 * Author: Blog Team
 * Requires: 1.0
 */
defined('APP_BOOT') or exit;

/* QQ 互联 OAuth2.0 端点（仅允许官方域名，禁止改动为其它地址） */
define('QQ_LOGIN_AUTH_URL', 'https://graph.qq.com/oauth2.0/authorize');
define('QQ_LOGIN_TOKEN_URL', 'https://graph.qq.com/oauth2.0/token');
define('QQ_LOGIN_OPENID_URL', 'https://graph.qq.com/oauth2.0/me');
define('QQ_LOGIN_USERINFO_URL', 'https://graph.qq.com/user/get_user_info');

/** state 有效期（秒）：超时的授权流程一律拒绝 */
define('QQ_LOGIN_STATE_TTL', 600);

/* ================= 配置读取 ================= */

/** APP ID */
function qq_login_appid()
{
    return (string) plugin_option('qq-login', 'appid', '');
}

/** APP Key（仅在服务端参与请求，禁止输出到页面与日志） */
function qq_login_appkey()
{
    return (string) plugin_option('qq-login', 'appkey', '');
}

/**
 * 网站域名（含协议，管理员手工填写）：QQ 回调要求绝对 URL，
 * 不能依赖当前请求域名（反向代理/多域名场景不可靠）
 *
 * @return string 末尾不带斜杠，未配置返回空串
 */
function qq_login_domain()
{
    return rtrim((string) plugin_option('qq-login', 'domain', ''), '/');
}

/**
 * 域名输入规范化：补全 https://、去尾斜杠；白名单校验防注入
 *
 * @param string $input 原始输入
 * @return string|false 规范化结果；非法返回 false，空串表示清空
 */
function qq_login_normalize_domain($input)
{
    $value = trim((string) $input);
    if ($value === '') {
        return '';
    }
    if (!preg_match('#^https?://#i', $value)) {
        $value = 'https://' . $value;
    }
    // 仅允许协议 + 域名/端口 + 可选子目录，杜绝空格与特殊字符
    if (!preg_match('#^https?://[A-Za-z0-9.-]+(:\d{1,5})?(/[A-Za-z0-9/._-]*)?$#', $value)) {
        return false;
    }
    return rtrim($value, '/');
}

/** 是否已完成配置（域名缺失同样视为未配置：回调地址无法拼成绝对 URL） */
function qq_login_configured()
{
    return qq_login_appid() !== '' && qq_login_appkey() !== '' && qq_login_domain() !== '';
}

/**
 * state 签名密钥：首次使用时惰性生成并持久化（卸载时由内核随 options 清理）
 *
 * @return string
 */
function qq_login_secret()
{
    $secret = (string) plugin_option('qq-login', 'secret', '');
    if ($secret === '') {
        $secret = bin2hex(random_bytes(32));
        plugin_option_update('qq-login', 'secret', $secret);
    }
    return $secret;
}

/**
 * 站内前台路由 URL（兼容伪静态与回退两种模式）
 *
 * @param string $path 路由路径
 * @return string
 */
function qq_login_front_url($path)
{
    if (Router::rewriteEnabled()) {
        return Router::base() . '/' . $path;
    }
    return Router::base() . '/index.php?r=' . rawurlencode($path);
}

/** QQ 开放平台登记的回调地址（绝对 URL：配置的域名 + 站内路径） */
function qq_login_callback_url()
{
    return qq_login_domain() . qq_login_front_url('qqlogin-callback');
}

/* ================= 授权链接与 state 防伪 ================= */

/**
 * 生成 QQ 授权链接：state = 随机数.HMAC 签名，随机数同时写入会话，
 * 回调时双重校验（防 CSRF + 防重放）
 *
 * @param string $action login（登录）或 bind（绑定/换绑）
 * @return string
 */
function qq_login_auth_url($action)
{
    $nonce = bin2hex(random_bytes(16));
    $_SESSION['qq_login_state'] = array('nonce' => $nonce, 'action' => $action, 'at' => time());
    $sig = hash_hmac('sha256', $nonce . '|' . $action, qq_login_secret());
    $params = array(
        'response_type' => 'code',
        'client_id'     => qq_login_appid(),
        'redirect_uri'  => qq_login_callback_url(),
        'state'         => $nonce . '.' . $sig,
        'scope'         => 'get_user_info',
    );
    return QQ_LOGIN_AUTH_URL . '?' . http_build_query($params);
}

/**
 * 校验回调 state：HMAC 验签 + 会话随机数一致 + 动作一致 + 未超时，
 * 校验通过后立即清除会话 state（一次性，防重放）
 *
 * @param string $rawState 回调携带的 state
 * @return string|false 通过返回动作名（login/bind），失败返回 false
 */
function qq_login_verify_state($rawState)
{
    $dot = strpos($rawState, '.');
    if ($dot === false) {
        return false;
    }
    $nonce = substr($rawState, 0, $dot);
    $sig = substr($rawState, $dot + 1);

    $action = false;
    foreach (array('login', 'bind') as $candidate) {
        if (hash_equals(hash_hmac('sha256', $nonce . '|' . $candidate, qq_login_secret()), $sig)) {
            $action = $candidate;
            break;
        }
    }
    if ($action === false) {
        return false;
    }
    // 会话侧校验：必须由本站发起、动作一致、未超时
    if (empty($_SESSION['qq_login_state'])) {
        return false;
    }
    $local = $_SESSION['qq_login_state'];
    unset($_SESSION['qq_login_state']);
    if (!hash_equals((string) $local['nonce'], $nonce)
        || $local['action'] !== $action
        || time() - (int) $local['at'] > QQ_LOGIN_STATE_TTL
    ) {
        return false;
    }
    return $action;
}

/* ================= OAuth 请求（cURL，强制 TLS 证书校验） ================= */

/**
 * HTTPS GET：仅接受 200 响应，失败返回 false
 *
 * @param string $url 请求地址
 * @return string|false
 */
function qq_login_http_get($url)
{
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
    curl_setopt($ch, CURLOPT_USERAGENT, 'my-blog/1.0 qq-login plugin');
    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false || (int) $code !== 200) {
        return false;
    }
    return $body;
}

/** 剥离 QQ 接口的 callback( ... ) JSONP 外壳 */
function qq_login_strip_jsonp($body)
{
    if (strpos($body, 'callback(') !== false) {
        $left = strpos($body, '(');
        $right = strrpos($body, ')');
        if ($left !== false && $right !== false && $right > $left) {
            return substr($body, $left + 1, $right - $left - 1);
        }
    }
    return $body;
}

/** code 换 access_token（响应为查询串格式；错误时 QQ 返回 JSONP 错误体） */
function qq_login_get_token($code)
{
    $params = array(
        'grant_type'    => 'authorization_code',
        'client_id'     => qq_login_appid(),
        'client_secret' => qq_login_appkey(),
        'code'          => $code,
        'redirect_uri'  => qq_login_callback_url(),
    );
    $body = qq_login_http_get(QQ_LOGIN_TOKEN_URL . '?' . http_build_query($params));
    if ($body === false) {
        return false;
    }
    $inner = qq_login_strip_jsonp($body);
    $err = json_decode($inner, true);
    if (is_array($err) && !empty($err['error'])) {
        plugin_log('qq-login.token', array('result' => 'fail', 'reason' => 'api_error'));
        return false;
    }
    $parsed = array();
    parse_str($inner, $parsed);
    return isset($parsed['access_token']) && $parsed['access_token'] !== '' ? $parsed['access_token'] : false;
}

/** access_token 换 openid */
function qq_login_get_openid($token)
{
    $body = qq_login_http_get(QQ_LOGIN_OPENID_URL . '?access_token=' . urlencode($token));
    if ($body === false) {
        return false;
    }
    $data = json_decode(qq_login_strip_jsonp($body), true);
    if (!is_array($data) || !empty($data['error']) || empty($data['openid'])) {
        return false;
    }
    return (string) $data['openid'];
}

/** 拉取 QQ 昵称（失败返回空串，不阻断主流程） */
function qq_login_get_nickname($token, $openid)
{
    $params = array(
        'access_token'       => $token,
        'oauth_consumer_key' => qq_login_appid(),
        'openid'             => $openid,
        'format'             => 'json',
    );
    $body = qq_login_http_get(QQ_LOGIN_USERINFO_URL . '?' . http_build_query($params));
    if ($body === false) {
        return '';
    }
    $data = json_decode($body, true);
    if (is_array($data) && isset($data['ret']) && (int) $data['ret'] === 0 && isset($data['nickname'])) {
        return mb_substr((string) $data['nickname'], 0, 64);
    }
    return '';
}

/* ================= 绑定关系存取（plugin_data 用户级作用域） ================= */

/** 按 openid 反查本站用户 ID（未绑定返回 0） */
function qq_login_user_by_openid($openid)
{
    $row = DB::query('plugin_data')
        ->where('plugin', '=', 'qq-login')
        ->where('scope', '=', 'user')
        ->where('data_key', '=', 'qq_openid')
        ->where('data_value', '=', $openid)
        ->first();
    return $row ? (int) $row['user_id'] : 0;
}

/* ================= 自定义路由认领 ================= */

/** 认领 OAuth 回调与解绑动作两个前台路径 */
add_filter('route_parse', 'qq_login_claim_route', 10);
function qq_login_claim_route($route, $path)
{
    if ($path === 'qqlogin-callback') {
        return array('route' => 'qq_callback', 'params' => array());
    }
    if ($path === 'qqlogin/unbind') {
        return array('route' => 'qq_unbind', 'params' => array());
    }
    return $route;
}

/* ================= OAuth 回调 ================= */

add_action('front_route_qq_callback', 'qq_login_handle_callback');

/**
 * QQ 授权回调：IP 限流 → state 校验 → code 换身份 → 登录或绑定
 */
function qq_login_handle_callback()
{
    if (!qq_login_configured()) {
        redirect(Router::url('home'));
    }

    // 复用内核限流，锁超时必须拒绝；IPv6 按 /64 归一，防轮换地址绕过。
    if (!ip_throttle_allow('qq_callback', 10)) {
        plugin_log('qq-login.callback', array('result' => 'fail', 'reason' => 'rate_limited'));
        flash_set('error', '操作过于频繁，请稍后再试');
        redirect(Router::url('login'));
    }

    $code = input_text('code', '', 256, 'get');
    $state = input_text('state', '', 256, 'get');
    if ($code === '' || $state === '') {
        flash_set('error', '回调参数缺失，请从登录页重新发起 QQ 登录');
        redirect(Router::url('login'));
    }

    $action = qq_login_verify_state($state);
    if ($action === false) {
        plugin_log('qq-login.callback', array('result' => 'fail', 'reason' => 'state_invalid'));
        flash_set('error', '安全校验未通过，请从登录页重新发起 QQ 登录');
        redirect(Router::url('login'));
    }

    $token = qq_login_get_token($code);
    if ($token === false) {
        flash_set('error', 'QQ 授权失败，请重试');
        redirect($action === 'bind' ? site_base_admin('profile') : Router::url('login'));
    }
    $openid = qq_login_get_openid($token);
    if ($openid === false) {
        flash_set('error', '获取 QQ 身份失败，请重试');
        redirect($action === 'bind' ? site_base_admin('profile') : Router::url('login'));
    }
    $nickname = qq_login_get_nickname($token, $openid);

    if ($action === 'bind') {
        qq_login_do_bind($openid, $nickname);
        return;
    }
    qq_login_do_login($openid);
}

/** 绑定/换绑流程（回调时用户必须已登录，会话在跳转 QQ 期间保持） */
function qq_login_do_bind($openid, $nickname)
{
    $profileUrl = site_base_admin('profile');
    if (!Auth::check()) {
        flash_set('error', '请先登录后再绑定 QQ');
        redirect(Router::url('login'));
    }
    Auth::require_cap('edit_profile');
    if (!empty($_SESSION['pwd_expired'])) {
        blog_log('auth', 'password.expired', 'fail', array('source' => 'qq.bind'));
        redirect(site_base_admin('profile/password'));
    }
    $uid = Auth::id();

    // 一个 QQ 只能绑定一个账号：查重与写入必须串行——uk_lookup 唯一键不含 data_value，
    // DB 层无法兜底同一 openid 并发绑两个账号，故按 openid 维度加命名锁消除 TOCTOU
    $lockName = 'qqbind-' . md5($openid);
    if (!DB::lock($lockName, 5)) {
        plugin_log('qq-login.bind', array('result' => 'fail', 'reason' => 'lock_timeout', 'user_id' => $uid));
        flash_set('error', '操作过于频繁，请稍后再试');
        redirect($profileUrl);
    }
    try {
        $boundUid = qq_login_user_by_openid($openid);
        if ($boundUid > 0 && $boundUid !== $uid) {
            plugin_log('qq-login.bind', array('result' => 'fail', 'reason' => 'openid_taken', 'user_id' => $uid));
            flash_set('error', '该 QQ 已绑定其他账号，无法重复绑定');
            redirect($profileUrl);
        }

        // 换绑 = 直接覆盖当前用户的旧绑定（旧 openid 行被唯一索引覆盖）
        plugin_user_set('qq-login', $uid, 'qq_openid', $openid);
        if ($nickname !== '') {
            plugin_user_set('qq-login', $uid, 'qq_nickname', $nickname);
        } else {
            plugin_user_delete('qq-login', $uid, 'qq_nickname');
        }
    } finally {
        DB::unlock($lockName);
    }
    blog_log('user', 'qq.bind', 'success', array('user_id' => $uid));
    flash_set('success', 'QQ 绑定成功，此后可在登录页使用 QQ 图标直接登录');
    redirect($profileUrl);
}

/**
 * QQ 登录流程：按绑定关系找到账号并建立会话
 *
 * 通道身份认定（有意设计）：QQ 通道经 OAuth + OpenID 绑定关系完成身份核验，
 * 视为号主本人的独立身份凭据；口令侧的失败锁定（locked_until）仅限制口令通道，
 * 不阻断本通道——账号遭受暴力破解时号主仍可经 QQ 正常登录。
 * 封禁/禁用/注销状态在两通道同等生效。
 */
function qq_login_do_login($openid)
{
    $uid = qq_login_user_by_openid($openid);
    if ($uid === 0) {
        blog_log('auth', 'login.qq', 'fail', array('reason' => 'unbound'));
        flash_set('error', '该 QQ 尚未绑定本站账号：请先用账号密码登录，到后台「个人资料」绑定 QQ 后再试');
        redirect(Router::url('login'));
    }

    $user = DB::query('users')->where('id', '=', $uid)->first();
    // 与账密登录同口径的可用性校验（禁用/封禁/注销一律拒绝）
    if (!$user
        || (int) $user['status'] !== 1
        || !empty($user['is_banned'])
        || !empty($user['is_deleted'])
    ) {
        blog_log('auth', 'login.qq', 'fail', array('user_id' => $uid, 'reason' => 'user_unavailable'));
        flash_set('error', '账号状态异常，无法登录');
        redirect(Router::url('login'));
    }

    Auth::loginUser($user);
    blog_log('auth', 'login.qq', 'success', array('user_id' => $uid));
    redirect(Router::url('home'));
}

/* ================= 解绑动作（前台 POST + CSRF） ================= */

add_action('front_route_qq_unbind', 'qq_login_handle_unbind');

function qq_login_handle_unbind()
{
    $profileUrl = site_base_admin('profile');
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        redirect($profileUrl);
    }
    Csrf::verifyOrDie();
    if (!Auth::check()) {
        redirect(Router::url('login'));
    }
    Auth::require_cap('edit_profile');
    if (!empty($_SESSION['pwd_expired'])) {
        blog_log('auth', 'password.expired', 'fail', array('source' => 'qq.unbind'));
        redirect(site_base_admin('profile/password'));
    }
    $uid = Auth::id();
    plugin_user_delete('qq-login', $uid, 'qq_openid');
    plugin_user_delete('qq-login', $uid, 'qq_nickname');
    blog_log('user', 'qq.unbind', 'success', array('user_id' => $uid));
    flash_set('success', '已解除 QQ 绑定');
    redirect($profileUrl);
}

/* ================= 登录页入口（auth_form_footer 钩子） ================= */

add_action('auth_form_footer', 'qq_login_render_entry');

/**
 * 登录按钮下方输出 QQ 图标入口（居中、无文字）
 *
 * @param string $page 认证页标识（仅 login 输出）
 */
function qq_login_render_entry($page)
{
    if ($page !== 'login' || !qq_login_configured()) {
        return;
    }
    $iconUrl = plugin_url('qq-login', 'assets/icon_QQ.png');
    echo '<p style="text-align:center;margin:18px 0 4px">'
        . '<a href="' . e(qq_login_auth_url('login')) . '" rel="nofollow" title="QQ 登录">'
        . '<img src="' . e($iconUrl) . '" alt="QQ 登录" style="width:40px;height:40px;vertical-align:middle">'
        . '</a></p>';
}

/* ================= 个人资料卡片（profile_cards 钩子） ================= */

add_action('profile_cards', 'qq_login_render_profile_card');

/** 绑定状态卡片：未绑定→绑定按钮；已绑定→状态 + 解绑 + 换绑（含文字说明） */
function qq_login_render_profile_card()
{
    if (!qq_login_configured()) {
        return;
    }
    $uid = Auth::id();
    $openid = (string) plugin_user_get('qq-login', $uid, 'qq_openid', '');
    echo '<div class="card">' . "\n";
    echo '    <h3>QQ 账号绑定</h3>' . "\n";
    if ($openid !== '') {
        $nickname = (string) plugin_user_get('qq-login', $uid, 'qq_nickname', '');
        echo '    <div class="form-row"><label>绑定状态</label><div>已绑定';
        if ($nickname !== '') {
            echo '（' . e($nickname) . '）';
        }
        echo '</div></div>' . "\n";
        echo '    <form method="post" action="' . e(qq_login_front_url('qqlogin/unbind')) . '" '
            . 'style="display:inline" onsubmit="return confirm(\'确认解除 QQ 绑定？解绑后将无法使用 QQ 登录本站。\');">';
        echo Csrf::field();
        echo '<button class="btn gray" type="submit">解除绑定</button></form> ';
        echo '<a class="btn" href="' . e(qq_login_auth_url('bind')) . '">换绑 QQ</a>' . "\n";
        echo '    <p class="tip">绑定后可在登录页点击 QQ 图标直接登录；一个 QQ 只能绑定一个账号，换绑将自动替换原绑定。</p>' . "\n";
    } else {
        echo '    <div class="form-row"><label>绑定状态</label><div>未绑定</div></div>' . "\n";
        echo '    <a class="btn" href="' . e(qq_login_auth_url('bind')) . '">绑定 QQ</a>' . "\n";
        echo '    <p class="tip">绑定后可在登录页点击 QQ 图标直接登录，无需输入账号密码。</p>' . "\n";
    }
    echo '</div>' . "\n";
}

/* ================= 后台设置页 ================= */

add_action('admin_menu', 'qq_login_register_page');
function qq_login_register_page()
{
    register_plugin_page('qq-login', 'QQ登录', 'qq_login_settings_page');
}

/** 设置页：网站域名 / APP ID / APP Key 配置 + 回调地址展示（供开放平台登记） */
function qq_login_settings_page()
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // 后台 POST 已由内核统一校验 CSRF
        $appid = input_text('qq_appid', '', 64, 'post');
        $appkey = input_text('qq_appkey', '', 128, 'post');
        $domain = qq_login_normalize_domain(input_text('qq_domain', '', 128, 'post'));
        plugin_option_update('qq-login', 'appid', $appid);
        // APP Key 留空表示保持原值不变（避免明文回显导致的泄露风险）
        if ($appkey !== '') {
            plugin_option_update('qq-login', 'appkey', $appkey);
        }
        if ($domain === false) {
            echo '<div class="flash err">网站域名格式不合法，未保存该项（需形如 https://blog.example.com）</div>';
        } else {
            plugin_option_update('qq-login', 'domain', $domain);
        }
        plugin_log('qq-login.save', array('result' => 'success'));
        echo '<div class="flash ok">已保存</div>';
    }
    $appid = qq_login_appid();
    $hasKey = qq_login_appkey() !== '';
    $domain = qq_login_domain();
    echo '<form method="post">';
    echo Csrf::field();
    echo '<div class="form-row"><label>网站域名（含协议，用于拼接 QQ 回调绝对地址）</label>'
        . '<input type="text" name="qq_domain" maxlength="128" value="' . e($domain) . '" '
        . 'placeholder="https://你的域名"></div>';
    echo '<div class="form-row"><label>APP ID</label>'
        . '<input type="text" name="qq_appid" maxlength="64" value="' . e($appid) . '"></div>';
    echo '<div class="form-row"><label>APP Key（留空保持不变）</label>'
        . '<input type="password" name="qq_appkey" maxlength="128" autocomplete="new-password" '
        . 'placeholder="' . ($hasKey ? '已配置，如需更换请输入新值' : '未配置') . '"></div>';
    echo '<div class="form-row"><label>回调地址（请原样登记到 QQ 开放平台）</label>'
        . '<input type="text" value="' . e($domain !== '' ? qq_login_callback_url() : '（请先填写网站域名）') . '" readonly></div>';
    echo '<button class="btn" type="submit">保存</button>';
    echo '</form>';
    echo '<p class="tip">在 QQ 开放平台创建「网站应用」并通过审核后，将网站域名、APP ID / APP Key 填入上方；'
        . '回调地址必须与开放平台登记完全一致（含协议与域名）。</p>';
}
