<?php
/** 验证码、限流与业务事务回归：使用可控替身模拟等待锁和存储失败。 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('APP_BOOT', true);
define('APP_ROOT', dirname(__DIR__, 2));
defined('APP_BOOT') or exit;
require __DIR__ . '/support.php';
require __DIR__ . '/MemoryDB.php';
foreach (array('Utils', 'Hook', 'Option', 'Auth', 'Logger', 'Plugin', 'VerificationService', 'RateLimiter', 'UserService', 'PostService', 'PostQuery', 'CommentQuery', 'Router', 'ThemeLocale', 'ThemeData', 'Theme', 'Config', 'Csrf', 'Markdown') as $class) {
    require dirname(__DIR__) . '/' . $class . '.php';
}

/** 每例清空业务状态，钩子用各自唯一名称或受控开关。 */
function reset_service_test()
{
    DB::$rows = array('options' => array(), 'plugin_data' => array(), 'verify_codes' => array(), 'users' => array(), 'posts' => array(), 'comments' => array(), 'logs' => array());
    DB::$locks = array();
    DB::$allowLock = true;
    DB::$onLock = null;
    DB::$failTable = null;
    Option::resetCache();
    PluginContext::set(null);
    $_SERVER['REMOTE_ADDR'] = '192.0.2.10';
    $_SERVER['HTTP_USER_AGENT'] = 'regression-test';
    foreach (array('user' => null, 'userLoaded' => false) as $name => $value) {
        $property = new ReflectionProperty('Auth', $name);
        $property->setAccessible(true);
        $property->setValue(null, $value);
    }
    $_SESSION = array();
}

/** 注入经过认证的角色快照，仍使用真实能力点映射。 */
function service_test_role($role)
{
    foreach (array('user' => array('id' => 1, 'role' => $role), 'userLoaded' => true) as $name => $value) {
        $property = new ReflectionProperty('Auth', $name);
        $property->setAccessible(true);
        $property->setValue(null, $value);
    }
}

/** 构造有效期和消费状态明确的验证码行，内容不进入测试输出。 */
function verification_row($id, $used = 0, $age = 0)
{
    return array('id' => $id, 'scene' => 'register', 'target' => 'test@example.invalid', 'channel' => 'email',
        'code' => '654321', 'used' => $used, 'attempts' => 0,
        'expires_at' => date('Y-m-d H:i:s', time() + 600 - $age), 'created_at' => now());
}

Plugin::setVerifyProvider('email', 'test-mailer');
foreach (array('consumed', 'expired', 'attempts') as $kind) {
    TestSuite::run('latest verification cannot fall back after ' . $kind, function () use ($kind) {
        reset_service_test();
        $latest = verification_row(2, $kind === 'consumed' ? 1 : 0, $kind === 'expired' ? 700 : 0);
        $latest['attempts'] = $kind === 'attempts' ? 2 : 0;
        DB::$rows['verify_codes'] = array(verification_row(1), $latest);
        TestSuite::check(!VerificationService::consume('register', 'test@example.invalid', '654321', 'email'));
        TestSuite::check(DB::$rows['verify_codes'][0]['used'] === 0);
    });
}
TestSuite::run('verification is consumed only once', function () {
    reset_service_test();
    DB::$rows['verify_codes'][] = verification_row(1);
    TestSuite::check(VerificationService::consume('register', 'test@example.invalid', '654321', 'email'));
    TestSuite::check(!VerificationService::consume('register', 'test@example.invalid', '654321', 'email'));
    TestSuite::check(empty(DB::$locks));
});
TestSuite::run('default wrong-attempt budget is two', function () {
    reset_service_test();
    DB::$rows['verify_codes'][] = verification_row(1);
    TestSuite::check(!VerificationService::consume('register', 'test@example.invalid', '000000', 'email'));
    TestSuite::check(!VerificationService::consume('register', 'test@example.invalid', '000000', 'email'));
    TestSuite::check(DB::$rows['verify_codes'][0]['used'] === 1);
    TestSuite::check(!VerificationService::consume('register', 'test@example.invalid', '654321', 'email'));
});
TestSuite::run('verification refuses lock failure without writes', function () {
    reset_service_test();
    DB::$rows['verify_codes'][] = verification_row(1);
    DB::$allowLock = false;
    TestSuite::check(!VerificationService::consume('register', 'test@example.invalid', '654321', 'email'));
    TestSuite::check(DB::$rows['verify_codes'][0]['used'] === 0);
});
foreach (array(0 => 1, 99 => 5) as $configured => $expected) {
    TestSuite::run('provider attempt budget is clamped ' . $configured, function () use ($configured, $expected) {
        reset_service_test();
        Option::set('plugin_test-mailer_max_attempts', $configured);
        DB::$rows['verify_codes'][] = verification_row(1);
        for ($i = 0; $i < $expected; $i++) {
            VerificationService::consume('register', 'test@example.invalid', '000000', 'email');
        }
        TestSuite::check(DB::$rows['verify_codes'][0]['used'] === 1);
    });
}
$providerThrows = false;
$providerCalls = 0;
PluginContext::run('test-mailer', function () use (&$providerThrows, &$providerCalls) {
    add_filter('send_verify_code', function ($handled, $scene, $target, $channel, $code) use (&$providerThrows, &$providerCalls) {
        $providerCalls++;
        if ($providerThrows) {
            throw new RuntimeException('simulated provider failure');
        }
        VerificationService::recordSent($scene, $target, $code, $channel);
        return true;
    });
});
TestSuite::run('send enforces interval and stores centrally', function () use (&$providerCalls) {
    reset_service_test();
    $providerCalls = 0;
    TestSuite::check(VerificationService::send('register', 'test@example.invalid', 'email') === 'sent');
    TestSuite::check(VerificationService::send('register', 'test@example.invalid', 'email') === 'retry');
    TestSuite::check($providerCalls === 1 && count(DB::$rows['verify_codes']) === 1 && empty(DB::$locks));
});
TestSuite::run('send daily quota applies across scenes', function () use (&$providerCalls) {
    reset_service_test();
    $providerCalls = 0;
    for ($i = 1; $i <= 10; $i++) {
        $row = verification_row($i);
        $row['scene'] = 'reset';
        DB::$rows['verify_codes'][] = $row;
    }
    TestSuite::check(VerificationService::send('register', 'test@example.invalid', 'email') === 'daily_limit');
    TestSuite::check($providerCalls === 0);
});
TestSuite::run('provider exception restores owner and releases lock', function () use (&$providerThrows) {
    reset_service_test();
    $providerThrows = true;
    TestSuite::check(VerificationService::send('register', 'test@example.invalid', 'email') === 'unavailable');
    TestSuite::check(PluginContext::current() === null && empty(DB::$locks));
    $providerThrows = false;
});
TestSuite::run('recording cannot impersonate a channel provider', function () {
    reset_service_test();
    try {
        VerificationService::recordSent('register', 'test@example.invalid', '654321', 'email');
        TestSuite::check(false);
    } catch (InvalidArgumentException $ex) {
        TestSuite::check(empty(DB::$rows['verify_codes']));
    }
});
TestSuite::run('rate limiter preserves legacy window after waiting for lock', function () {
    reset_service_test();
    $key = 'throttle_login_' . md5('192.0.2.10');
    Option::all();
    DB::$onLock = function () use ($key) {
        DB::insert('options', array('option_key' => $key, 'option_value' => time() . '|20'));
    };
    TestSuite::check(!RateLimiter::allow('login', 20));
    TestSuite::check(substr(DB::$rows['plugin_data'][0]['data_value'], -3) === '|21');
    TestSuite::check(empty(DB::$locks));
});
TestSuite::run('rate limiter uses separate buckets and normalized IPv6', function () {
    reset_service_test();
    $_SERVER['REMOTE_ADDR'] = '2001:db8:1234:5678::1';
    TestSuite::check(RateLimiter::allow('login', 1));
    $_SERVER['REMOTE_ADDR'] = '2001:db8:1234:5678::2';
    TestSuite::check(!RateLimiter::allow('login', 1));
    TestSuite::check(RateLimiter::allow('register', 1));
    TestSuite::check(count(DB::$rows['plugin_data']) === 2);
});
TestSuite::run('failed rate lock does not reset or write counter', function () {
    reset_service_test();
    DB::$allowLock = false;
    TestSuite::check(!RateLimiter::allow('login', 20) && empty(DB::$rows['plugin_data']));
});
TestSuite::run('contact uniqueness is rechecked inside shared lock', function () {
    reset_service_test();
    DB::$onLock = function () {
        DB::insert('users', array('username' => 'first', 'email' => null, 'phone' => '13800000000'));
    };
    try {
        UserService::create(array('username' => 'second', 'email' => null, 'phone' => '13800000000', 'role' => 'user'));
        TestSuite::check(false);
    } catch (UserConflictException $ex) {
        TestSuite::check($ex->getMessage() === 'phone' && count(DB::$rows['users']) === 1 && empty(DB::$locks));
    }
});
TestSuite::run('public registration cannot grant admin', function () {
    reset_service_test();
    try {
        UserService::create(array('username' => 'second', 'role' => 'admin'));
        TestSuite::check(false);
    } catch (RuntimeException $ex) {
        TestSuite::check(empty(DB::$rows['users']));
    }
});
TestSuite::run('post and navigation roll back together', function () {
    reset_service_test();
    service_test_role('admin');
    DB::$failTable = 'options';
    try {
        PostService::save(0, array('title' => 'Example', 'status' => 'published', 'is_page' => 1), true);
        TestSuite::check(false);
    } catch (RuntimeException $ex) {
        TestSuite::check(empty(DB::$rows['posts']) && empty(DB::$locks));
    }
});
TestSuite::run('post deletion rolls back when deleting comments fails', function () {
    reset_service_test();
    service_test_role('admin');
    DB::$rows['posts'][] = array('id' => 1, 'status' => 'trash');
    DB::$rows['comments'][] = array('id' => 1, 'post_id' => 1);
    DB::$failTable = 'comments';
    try {
        PostService::destroy(1);
        TestSuite::check(false);
    } catch (RuntimeException $ex) {
        TestSuite::check(count(DB::$rows['posts']) === 1 && count(DB::$rows['comments']) === 1);
    }
});
TestSuite::run('post callbacks run after commit and errors do not undo save', function () {
    reset_service_test();
    service_test_role('editor');
    Option::set('post_audit', '1');
    add_action('post_saved', function () {
        TestSuite::check(!DB::$inTransaction);
        throw new RuntimeException('simulated hook failure');
    });
    $id = PostService::save(0, array('title' => 'Example', 'status' => 'published', 'is_page' => 1), true);
    TestSuite::check($id === 1 && DB::$rows['posts'][0]['status'] === 'pending');
    TestSuite::check(Option::getJson('nav_page_ids') === array(1) && empty(DB::$locks));
});
TestSuite::run('audit details mask contacts and secrets but retain content snapshot', function () {
    reset_service_test();
    Logger::write('user', 'test', 'success', array('password' => 'not-a-real-password', 'email' => 'user@example.invalid', 'phone' => '13800000000', 'content' => 'user@example.invalid'));
    $detail = json_decode(DB::$rows['logs'][0]['detail'], true);
    TestSuite::check($detail['password'] === '***' && $detail['email'] !== 'user@example.invalid');
    TestSuite::check($detail['phone'] !== '13800000000' && $detail['content'] === 'user@example.invalid');
});
TestSuite::run('comment pagination never reveals another users pending rows', function () {
    reset_service_test();
    for ($i = 1; $i <= 120; $i++) {
        DB::$rows['comments'][] = array('id' => $i, 'post_id' => 7, 'user_id' => 2, 'status' => 'published', 'created_at' => '2026-01-01 12:00:00');
    }
    DB::$rows['comments'][] = array('id' => 121, 'post_id' => 7, 'user_id' => 1, 'status' => 'pending', 'created_at' => '2026-01-01 12:00:00');
    DB::$rows['comments'][] = array('id' => 122, 'post_id' => 7, 'user_id' => 2, 'status' => 'pending', 'created_at' => '2026-01-01 12:00:00');
    $guest = CommentQuery::page(7, 0, 99);
    $mine = CommentQuery::page(7, 1, 3);
    TestSuite::check($guest['total'] === 120 && $guest['page'] === 3 && count($guest['comments']) === 20);
    TestSuite::check($mine['total'] === 121 && count($mine['comments']) === 21);
    TestSuite::check(CommentQuery::pageOf(DB::$rows['comments'][120], 1) === 3);
});
foreach (array('1', '0') as $rewrite) {
    TestSuite::run('router preserves search and comment pages with rewrite ' . $rewrite, function () use ($rewrite) {
        reset_service_test();
        $_SERVER['SCRIPT_NAME'] = '/blog/index.php';
        $base = new ReflectionProperty('Router', 'base');
        $base->setAccessible(true);
        $base->setValue(null, null);
        Option::set('rewrite_enabled', $rewrite);
        $search = Router::url('search', array('q' => 'a b&c', 'page' => 2));
        parse_str(parse_url($search, PHP_URL_QUERY), $query);
        TestSuite::check($query['q'] === 'a b&c' && $query['page'] === '2');
        $post = Router::url('post', array('id' => 7, 'comment_page' => 3, 'edit_comment' => 120));
        parse_str(parse_url($post, PHP_URL_QUERY), $query);
        TestSuite::check($query['comment_page'] === '3' && $query['edit_comment'] === '120');
        TestSuite::check(Router::url('admin', array('m' => 'profile')) === '/blog/user/index.php?m=profile');
        if ($rewrite === '0') {
            TestSuite::check($query['r'] === 'post/7.html');
        }
    });
}
TestSuite::run('theme language preference and fallback survive extraction', function () {
    reset_service_test();
    $_COOKIE[Theme::LANG_COOKIE] = 'en_US';
    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'zh-CN';
    TestSuite::check(Theme::langCode() === 'en_US' && Theme::locale() === 'en-US');
    TestSuite::check(Theme::t('theme.callout.note') === 'Note');
    TestSuite::check(Theme::t('theme.missing', array(), 'fallback') === 'fallback');
    unset($_COOKIE[Theme::LANG_COOKIE]);
});
TestSuite::run('CSRF accepts only the current session token', function () {
    reset_service_test();
    $token = Csrf::token();
    TestSuite::check(Csrf::check($token) && !Csrf::check('invalid') && !Csrf::check(array($token)));
    unset($_SESSION['_csrf']);
    TestSuite::check(Csrf::token() !== $token && !Csrf::check($token));
});
TestSuite::run('password policy enforces strength and username rejection', function () {
    reset_service_test();
    TestSuite::check(Auth::validate_password_strength_code('short', 'account') === 'pwd_len');
    TestSuite::check(Auth::validate_password_strength_code('Account1!Strong', 'account') === 'pwd_contain_name');
    TestSuite::check(Auth::validate_password_strength_code('Valid8!Secret', 'account') === '');
});
TestSuite::run('password expiry stays off by default and covers administrators when enabled', function () {
    reset_service_test();
    $method = new ReflectionMethod('Auth', 'syncPasswordExpiry');
    $method->setAccessible(true);
    $user = array('role' => 'admin', 'password_changed_at' => date('Y-m-d H:i:s', time() - 91 * 86400));
    $method->invoke(null, $user);
    TestSuite::check(empty($_SESSION['pwd_expired']));
    Option::set('pwd_expire_enabled', '1');
    $method->invoke(null, $user);
    TestSuite::check(!empty($_SESSION['pwd_expired']));
});
TestSuite::run('client IP ignores forged forwarding headers by default', function () {
    reset_service_test();
    $_SERVER['HTTP_X_FORWARDED_FOR'] = '203.0.113.20, 203.0.113.21';
    TestSuite::check(client_ip() === '192.0.2.10');
    Option::set('ip_header_enabled', '1');
    Option::set('ip_header_name', 'X-Forwarded-For');
    TestSuite::check(client_ip() === '203.0.113.20');
    $_SERVER['HTTP_X_FORWARDED_FOR'] = 'invalid';
    TestSuite::check(client_ip() === '192.0.2.10');
});
TestSuite::run('shared post query excludes drafts and pages and preserves stable order', function () {
    reset_service_test();
    Option::set('posts_per_page', 1);
    DB::$rows['users'][] = array('id' => 2, 'nickname' => 'Writer', 'username' => 'writer', 'avatar' => '', 'is_deleted' => 0);
    DB::$rows['categories'][] = array('id' => 3, 'name' => 'Category', 'slug' => 'category');
    foreach (array('published', 'published', 'draft', 'published') as $index => $status) {
        DB::$rows['posts'][] = array('id' => $index + 1, 'title' => 'Example', 'content' => 'Body', 'excerpt' => '', 'status' => $status,
            'is_page' => $index === 3 ? 1 : 0, 'is_top' => 0, 'created_at' => '2026-01-01 12:00:00', 'author_id' => 2, 'category_id' => 3);
    }
    $page = PostQuery::page(1);
    TestSuite::check($page['total'] === 2 && $page['totalPages'] === 2 && $page['posts'][0]['id'] === 2);
    TestSuite::check($page['posts'][0]['author']['nickname'] === 'Writer' && $page['posts'][0]['category']['id'] === 3);
    TestSuite::check(PostQuery::page(99)['page'] === 2);
    TestSuite::check(PostQuery::page(1, array(), 'Missing')['total'] === 0);
});
TestSuite::run('contact lock failure prevents account creation', function () {
    reset_service_test();
    DB::$allowLock = false;
    try {
        UserService::create(array('username' => 'example', 'role' => 'user'));
        TestSuite::check(false);
    } catch (UserConflictException $ex) {
        TestSuite::check($ex->getMessage() === 'busy' && empty(DB::$rows['users']));
    }
});
TestSuite::run('busy post lock is audited without a partial write', function () {
    reset_service_test();
    service_test_role('admin');
    DB::$allowLock = false;
    try {
        PostService::save(0, array('title' => 'Example', 'status' => 'draft', 'is_page' => 0), false);
        TestSuite::check(false);
    } catch (PostWriteException $ex) {
        TestSuite::check($ex->getMessage() === 'busy' && empty(DB::$rows['posts']));
        TestSuite::check(DB::$rows['logs'][0]['result'] === 'fail');
    }
});
TestSuite::run('default template retains cross-page replies and generates pagination', function () {
    reset_service_test();
    $author = array('id' => 2, 'nickname' => 'Writer', 'avatar' => '');
    $post = array('id' => 7, 'slug' => null, 'title' => 'Example', 'content' => '> [!note] Example',
        'status' => 'published', 'author' => $author, 'category' => null, 'created_at' => '2026-01-01 12:00:00', 'views' => 0);
    $comment = array('id' => 51, 'parent_id' => 1, 'post_id' => 7, 'user_id' => 2, 'status' => 'published',
        'content' => 'Visible reply', 'created_at' => '2026-01-01 12:00:00', 'author' => $author);
    ob_start();
    try {
        Theme::render('single', array('page_type' => 'single', 'title' => 'Example', 'post' => $post,
            'comments' => array($comment), 'commentTotal' => 51, 'commentPage' => 2, 'commentTotalPages' => 2, 'editCommentId' => 0));
        $html = ob_get_contents();
    } finally {
        ob_end_clean();
    }
    TestSuite::check(strpos($html, 'id="comment-51"') !== false && strpos($html, 'Visible reply') !== false);
    TestSuite::check(strpos($html, 'comment_page=1#comments') !== false);
});
require __DIR__ . '/hardening_cases.php';
exit(TestSuite::finish());
