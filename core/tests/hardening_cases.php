<?php
/** 外部请求边界回归：复用服务层替身，不接触站点配置、真实数据库和外部 OAuth。 */
defined('APP_BOOT') or exit;
require dirname(__DIR__) . '/AdminUpload.php';
PluginContext::run('qq-login', function () {
    require APP_ROOT . '/plugins/qq-login/qq-login.php';
});

/** 构造测试身份，使用真实会话指纹校验和真实口令哈希。 */
function hardening_user()
{
    reset_service_test();
    $user = array('id' => 1, 'username' => 'account', 'role' => 'user', 'status' => 1,
        'is_banned' => 0, 'is_deleted' => 0, 'login_fail' => 0, 'locked_until' => null,
        'password' => password_hash('Valid8!Secret', PASSWORD_DEFAULT), 'password_changed_at' => now());
    DB::$rows['users'][] = $user;
    $_SESSION['uid'] = 1;
    $_SESSION['pwd_fp'] = Auth::passwordFingerprint($user['password']);
    return $user;
}

/** 模拟下一次请求重读身份，不绕过 Auth 的状态与指纹检查。 */
function hardening_reload_identity()
{
    foreach (array('user' => null, 'userLoaded' => false) as $name => $value) {
        $property = new ReflectionProperty('Auth', $name);
        $property->setAccessible(true);
        $property->setValue(null, $value);
    }
}

TestSuite::run('password confirmation shares persistent lock across changing IPs', function () {
    hardening_user();
    for ($i = 0; $i < 5; $i++) {
        $_SERVER['REMOTE_ADDR'] = '192.0.2.' . (20 + $i);
        TestSuite::check(!Auth::confirmCurrentPassword('incorrect'));
    }
    TestSuite::check(strtotime(DB::$rows['users'][0]['locked_until']) >= time() + 590);
    TestSuite::check(!Auth::confirmCurrentPassword('Valid8!Secret'));
    TestSuite::check(empty(DB::$locks));
});
TestSuite::run('login and password confirmation cannot split failure counters', function () {
    hardening_user();
    for ($i = 0; $i < 4; $i++) {
        TestSuite::check(!Auth::attempt('account', 'incorrect')['ok']);
    }
    TestSuite::check(!Auth::confirmCurrentPassword('incorrect'));
    TestSuite::check(!Auth::attempt('account', 'Valid8!Secret')['ok']);
});
TestSuite::run('successful confirmation clears failures without replacing CSRF or identity', function () {
    hardening_user();
    DB::$rows['users'][0]['login_fail'] = 3;
    $_SESSION['_csrf'] = 'unchanged';
    TestSuite::check(Auth::confirmCurrentPassword('Valid8!Secret'));
    TestSuite::check(DB::$rows['users'][0]['login_fail'] === 0 && $_SESSION['_csrf'] === 'unchanged');
    TestSuite::check(DB::$rows['logs'][0]['action'] === 'password.confirm');
});
foreach (array('status' => 0, 'is_banned' => 1, 'is_deleted' => 1) as $field => $value) {
    TestSuite::run('confirmation rechecks fresh account state ' . $field, function () use ($field, $value) {
        hardening_user();
        Auth::user();
        DB::$rows['users'][0][$field] = $value;
        TestSuite::check(!Auth::confirmCurrentPassword('Valid8!Secret'));
    });
}
TestSuite::run('confirmation refuses a password changed while waiting for the lock', function () {
    hardening_user();
    Auth::user();
    DB::$onLock = function () {
        DB::$rows['users'][0]['password'] = password_hash('Another8!Secret', PASSWORD_DEFAULT);
    };
    TestSuite::check(!Auth::confirmCurrentPassword('Another8!Secret'));
});
TestSuite::run('failed confirmation lock does not authorize sensitive actions', function () {
    hardening_user();
    DB::$allowLock = false;
    TestSuite::check(!Auth::confirmCurrentPassword('Valid8!Secret'));
    TestSuite::check(DB::$rows['users'][0]['login_fail'] === 0);
});
TestSuite::run('password change refreshes fingerprint and invalidates outstanding tokens', function () {
    $user = hardening_user();
    $_SESSION['_csrf'] = 'old-token';
    $_SESSION['qq_login_state'] = array('old');
    TestSuite::check(Auth::changePassword(1, 'Valid8!Secret', 'Another8!Secret')['ok']);
    TestSuite::check(password_verify('Another8!Secret', DB::$rows['users'][0]['password']));
    TestSuite::check(!hash_equals(Auth::passwordFingerprint($user['password']), $_SESSION['pwd_fp']));
    TestSuite::check(!isset($_SESSION['_csrf'], $_SESSION['qq_login_state']) && empty(DB::$locks));
});
TestSuite::run('password history failure rolls back new credentials', function () {
    $user = hardening_user();
    Option::set('pwd_history_count', '2');
    DB::$rows['password_history'] = array();
    DB::$failTable = 'password_history';
    TestSuite::check(!Auth::changePassword(1, 'Valid8!Secret', 'Another8!Secret')['ok']);
    TestSuite::check(DB::$rows['users'][0]['password'] === $user['password']);
    TestSuite::check(empty(DB::$locks) && !DB::$inTransaction);
});
TestSuite::run('enabled password history rejects the current password', function () {
    hardening_user();
    Option::set('pwd_history_count', '2');
    DB::$rows['password_history'] = array();
    TestSuite::check(Auth::changePassword(1, 'Valid8!Secret', 'Valid8!Secret')['code'] === 'pwd_history_hit');
});
TestSuite::run('account rate limits survive IP rotation and keep daily windows alive', function () {
    reset_service_test();
    TestSuite::check(RateLimiter::allowFor('upload_avatar', 'user:1', 1, 86400));
    $_SERVER['REMOTE_ADDR'] = '203.0.113.2';
    TestSuite::check(!RateLimiter::allowFor('upload_avatar', 'user:1', 1, 86400));
    TestSuite::check(strtotime(DB::$rows['plugin_data'][0]['expires_at']) >= time() + 86400);
    TestSuite::check(RateLimiter::allowFor('upload_avatar', 'user:2', 1, 86400));
    TestSuite::check(RateLimiter::allowFor('upload_avatar', 'user:1', 1, 60));
});
TestSuite::run('expired rate windows restart and denied counters stop growing', function () {
    reset_service_test();
    TestSuite::check(RateLimiter::allowFor('comment', 'user:1', 1));
    TestSuite::check(!RateLimiter::allowFor('comment', 'user:1', 1));
    $stored = DB::$rows['plugin_data'][0]['data_value'];
    TestSuite::check(!RateLimiter::allowFor('comment', 'user:1', 1));
    TestSuite::check(DB::$rows['plugin_data'][0]['data_value'] === $stored);
    DB::$rows['plugin_data'][0]['data_value'] = (time() - 61) . '|2';
    TestSuite::check(RateLimiter::allowFor('comment', 'user:1', 1));
});
TestSuite::run('malformed array parameters are rejected without PHP warnings', function () {
    $_POST = array('field' => array('unexpected'));
    TestSuite::check(input_email('field', '', 'post') === '' && input_phone('field', '', 'post') === '');
    TestSuite::check(input_slug('field', '', 'post') === '' && input_enum('field', array('ok'), '', 'post') === '');
    TestSuite::check(input_password('field') === '' && input_int('field', 0, 'post') === 0);
});
TestSuite::run('password and CSRF inputs reject suffix truncation and null bytes', function () {
    $_POST = array('field' => str_repeat('x', 129));
    TestSuite::check(input_password('field') === '');
    $_POST['field'] = "Valid8!Secret\0suffix";
    TestSuite::check(input_password('field') === '' && Auth::validate_password_strength_code($_POST['field']) !== '');
    $_SESSION['_csrf'] = str_repeat('x', 128);
    $_POST['_csrf'] = $_SESSION['_csrf'] . 'suffix';
    TestSuite::check(!Csrf::check());
});
TestSuite::run('verification input with a trailing suffix cannot consume a valid code', function () {
    reset_service_test();
    DB::$rows['verify_codes'][] = verification_row(1);
    $_POST = array('code' => '654321suffix');
    TestSuite::check(!VerificationService::consume('register', 'test@example.invalid', input_text('code', '', 7, 'post'), 'email'));
    TestSuite::check(DB::$rows['verify_codes'][0]['used'] === 0);
});

/** 通过实际插件生成 state；不发起任何网络请求。 */
function hardening_qq_state($action)
{
    Option::set('plugin_qq-login_secret', 'regression-only-signing-key');
    $url = qq_login_auth_url($action);
    parse_str(parse_url($url, PHP_URL_QUERY), $query);
    return $query['state'];
}

TestSuite::run('QQ binding state is one time and belongs to the initiating account', function () {
    $user = hardening_user();
    $state = hardening_qq_state('bind');
    $other = array_merge($user, array('id' => 2));
    DB::$rows['users'][] = $other;
    $_SESSION['uid'] = 2;
    hardening_reload_identity();
    TestSuite::check(qq_login_verify_state($state) === false);
    $_SESSION['uid'] = 1;
    hardening_reload_identity();
    TestSuite::check(qq_login_verify_state($state) === 'bind');
    TestSuite::check(qq_login_verify_state($state) === false);
});
TestSuite::run('QQ binding refuses a new credential version of the same account', function () {
    hardening_user();
    $state = hardening_qq_state('bind');
    DB::$rows['users'][0]['password'] = password_hash('Another8!Secret', PASSWORD_DEFAULT);
    $_SESSION['pwd_fp'] = Auth::passwordFingerprint(DB::$rows['users'][0]['password']);
    hardening_reload_identity();
    TestSuite::check(qq_login_verify_state($state) === false);
});
TestSuite::run('unrelated signed QQ state cannot cancel an existing authorization', function () {
    hardening_user();
    $foreign = hardening_qq_state('login');
    $current = hardening_qq_state('login');
    TestSuite::check(qq_login_verify_state($foreign) === false);
    TestSuite::check(qq_login_verify_state($current) === 'login');
});
TestSuite::run('QQ state rejects expiration and tampered signatures', function () {
    hardening_user();
    $state = hardening_qq_state('login');
    TestSuite::check(qq_login_verify_state($state . 'x') === false);
    $_SESSION['qq_login_state']['at'] = time() - 601;
    TestSuite::check(qq_login_verify_state($state) === false);
});
TestSuite::run('QQ bind route URLs obey rewrite mode and reject path traversal', function () {
    reset_service_test();
    Option::set('rewrite_enabled', '0');
    TestSuite::check(strpos(qq_login_front_url('qqlogin/bind'), 'index.php?r=qqlogin%2Fbind') !== false);
    try {
        Router::url('custom', array('path' => '../config.php'));
        TestSuite::check(false);
    } catch (InvalidArgumentException $ex) {
        TestSuite::check(true);
    }
});
TestSuite::run('image inspection preserves original byte hash and rejects oversized dimensions', function () {
    $file = tempnam(sys_get_temp_dir(), 'myblog-image-');
    try {
        $gif = base64_decode('R0lGODlhAQABAIAAAAAAAP///ywAAAAAAQABAAACAUwAOw==');
        file_put_contents($file, $gif);
        $valid = AdminUpload::inspectImage($file, 1024);
        TestSuite::check($valid !== null && $valid['hash'] === md5($gif) && $valid['extension'] === 'gif');
        // GIF 头的逻辑画布足以形成解码资源风险，不需要构造巨大的真实图像。
        file_put_contents($file, substr_replace($gif, pack('vv', 10001, 10001), 6, 4));
        TestSuite::check(AdminUpload::inspectImage($file, 1024) === null);
        file_put_contents($file, '<?php echo "invalid";');
        TestSuite::check(AdminUpload::inspectImage($file, 1024) === null);
        file_put_contents($file, $gif);
        TestSuite::check(AdminUpload::inspectImage($file, 1) === null);
    } finally {
        unlink($file);
    }
});
