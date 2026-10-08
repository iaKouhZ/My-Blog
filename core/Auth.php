<?php
/**
 * 认证与权限：登录态、角色能力点 check_cap()、口令复杂度策略、登录失败锁定
 * 权限唯一判定依据为 users.role 字段
 */
defined('APP_BOOT') or exit;

class Auth
{
    /** @var array|null 当前用户缓存 */
    private static $user = null;
    private static $userLoaded = false;

    /** 常见弱口令黑名单（可经 password_blacklist 过滤器由插件扩展） */
    private static $weakPasswords = array(
        '12345678', '123456789', '1234567890', 'password', 'password1', 'password123',
        'qwerty123', 'qwertyuiop', 'qwerty1', '1qaz2wsx', '1q2w3e4r', '1q2w3e4r5t',
        'iloveyou', 'admin123', 'admin888', 'admin1234', 'admin12345', 'root1234',
        'abc12345', 'abcd1234', 'aa123456', 'a1234567', 'a12345678', '123456a',
        '123456aa', '66666666', '88888888', '11111111', '00000000', '12121212',
        'woaini1314', 'qazwsxedc', 'zxcvbnm1', 'asdfghjkl', 'asdf1234', 'qwer1234',
        'welcome1', 'letmein1', 'football', 'baseball1', 'superman', 'batman12',
        'trustno1', 'sunshine1', 'princess', 'starwars', 'shadow12', 'monkey12',
        'dragon12', 'master12', 'hello123', 'charlie1', 'donald12', 'login123',
        'passw0rd', 'pass1234', 'test1234', 'user1234', 'changeme', 'default1',
        'p@ssw0rd', 'admin@123', 'root@123', 'p@ssword', 'passw0rd!', 'abc@1234',
        'a123456789', 'aa12345678', 'woaini520', '5201314520', 'qwerty1!', 'qwe12345',
        'zxc12345', 'asd12345', '147258369', '159357456', '741852963', '987654321',
        '1234qwer', 'qwer4321', 'abcd@1234', 'abc123456', 'password!', 'passwd123',
        'p4ssword', 'pa55word', 'passw0rd1', 'admin@888', 'test@123', 'root12345',
        'toor1234', 'administrator', 'nimda123', 'guest123', 'demo1234', 'temp1234',
        'aaaa1111', 'qqqq1111', '1111qqqq', '1qazxsw2', '2wsxcde3', '1q2w3e',
        'q1w2e3r4', 'r4e3w2q1', 'asdfjkl;', 'zxcv1234', 'abcd12345', '123abc',
        '1234abcd', 'qaz12345', 'wsx12345', 'edc12345', 'aaa11111', 'qq123456',
        '123321123', '11223344', '55667788', '13141314', '52052052', '770813',
        'password99', 'pass12345', 'p@ss1234', 'pwd@1234', 'secret12', 'secret123',
    );

    /**
     * 当前登录用户（null 表示游客）
     *
     * @return array|null
     */
    public static function user()
    {
        if (self::$userLoaded) {
            return self::$user;
        }
        self::$userLoaded = true;
        if (empty($_SESSION['uid'])) {
            return self::$user = null;
        }
        $user = DB::query('users')->where('id', '=', (int) $_SESSION['uid'])->first();
        // 会话中的账号若被禁用、封禁或注销，立即失效
        if (!$user
            || (int) $user['status'] !== 1
            || !empty($user['is_banned'])
            || !empty($user['is_deleted'])
        ) {
            unset($_SESSION['uid'], $_SESSION['pwd_fp'], $_SESSION['role'], $_SESSION['last_active'], $_SESSION['pwd_expired'], $_SESSION['_csrf']);
            self::$user = null;
            return null;
        }
        // 口令指纹校验：改密（自助/找回/管理员重置）后口令哈希变化，
        // 其余既有会话指纹失配即自动失效；旧版本建立的会话无指纹，一并强制重登
        if (!isset($_SESSION['pwd_fp'])
            || !hash_equals(self::passwordFingerprint($user['password']), (string) $_SESSION['pwd_fp'])
        ) {
            unset($_SESSION['uid'], $_SESSION['pwd_fp'], $_SESSION['role'], $_SESSION['last_active'], $_SESSION['pwd_expired'], $_SESSION['_csrf']);
            self::$user = null;
            return null;
        }
        self::$user = $user;
        self::syncPasswordExpiry($user);
        return self::$user;
    }

    /** 是否已登录 */
    public static function check()
    {
        return self::user() !== null;
    }

    /** 当前用户 ID（游客为 0） */
    public static function id()
    {
        $user = self::user();
        return $user ? (int) $user['id'] : 0;
    }

    /** 当前角色（游客为 guest） */
    public static function role()
    {
        $user = self::user();
        return $user ? $user['role'] : 'guest';
    }

    /** 管理员快捷判断 */
    public static function isAdmin()
    {
        return self::role() === 'admin';
    }

    /**
     * 能力点判定（权限唯一依据 users.role）
     *
     * @param string $cap 能力名
     * @return bool
     */
    public static function check_cap($cap)
    {
        $caps = self::capsOf(self::role());
        return isset($caps[$cap]) && $caps[$cap];
    }

    /**
     * 能力点强制校验：不满足直接 403 终止
     *
     * @param string $cap 能力名
     * @return void
     */
    public static function require_cap($cap)
    {
        if (!self::check_cap($cap)) {
            http_response_code(403);
            blog_log('security', 'cap.denied:' . $cap, 'fail', array());
            // 标注原因便于区分应用层 403（不泄露能力点等细节）
            exit('403 Forbidden: insufficient permission');
        }
    }

    /**
     * 角色→能力点映射
     *
     * @param string $role 角色
     * @return array
     */
    private static function capsOf($role)
    {
        $caps = array('read' => true);
        if ($role === 'admin') {
            return array_merge($caps, array(
                'comment' => true, 'edit_profile' => true,
                'edit_own_comments' => true, 'delete_own_comments' => true,
                'publish_posts' => true, 'edit_posts' => true, 'edit_others_posts' => true,
                'delete_posts' => true, 'moderate_posts' => true,
                'moderate_comments' => true, 'manage_comments' => true,
                'manage_categories' => true, 'manage_users' => true,
                'manage_options' => true, 'manage_security' => true,
                'manage_themes' => true, 'manage_plugins' => true,
                'view_logs' => true, 'upload' => true,
            ));
        }
        if ($role === 'editor') {
            return array_merge($caps, array(
                'comment' => true, 'edit_profile' => true,
                'edit_own_comments' => true, 'delete_own_comments' => true,
                'publish_posts' => true, 'edit_posts' => true, 'edit_others_posts' => true,
                'upload' => true,
            ));
        }
        if ($role === 'user') {
            return array_merge($caps, array(
                'comment' => true, 'edit_profile' => true,
                // 能力点矩阵（plan.md §5.2）：发表/修改/删除自己的评论，三种登录角色均有
                'edit_own_comments' => true, 'delete_own_comments' => true,
            ));
        }
        return $caps;
    }

    /**
     * 服务层消息机器码 → 中文缺省文案（唯一出处，支持 %s 占位）
     * 各显示域按机器码映射自有语言包（前台 theme_t / 后台 admin_t / 安装 ins_t）；
     * attempt/changePassword 返回结构中的 msg 字段由本方法生成，保持既有调用方兼容
     *
     * @param string $code 机器码
     * @param array  $args 动态参数（如锁定分钟数、密码历史次数）
     * @return string
     */
    public static function msgOf($code, array $args = array())
    {
        $map = array(
            'throttled'           => '操作过于频繁，请稍后再试',
            'invalid_credentials' => '账号或密码错误',
            'locked'              => '连续失败次数过多，账号已锁定 %s 分钟',
            'login_success'       => '登录成功',
            'pwd_len'             => '密码长度必须为 8-64 位',
            'pwd_classes'         => '密码必须包含大写字母、小写字母、数字、特殊字符中的至少三类',
            'pwd_contain_name'    => '密码中不得包含用户名',
            'pwd_weak'            => '密码过于简单，命中弱口令黑名单',
            'user_not_found'      => '用户不存在',
            'old_password_wrong'  => '原密码错误',
            'pwd_history_hit'     => '新密码不能与最近 %s 次密码相同',
            'pwd_changed'         => '密码修改成功',
        );
        // 未知机器码原样返回（含空串=校验通过），便于排查遗漏
        $text = isset($map[$code]) ? $map[$code] : $code;
        return msg_format($text, $args);
    }

    /**
     * 登录尝试：含账号锁定、IP 限流、审计
     *
     * @param string $account  用户名或邮箱
     * @param string $password 明文密码（仅用于 verify，不落日志）
     * @return array array('ok' => bool, 'msg' => string, 'code' => string, 'args' => array)
     *               code 为机器码，供调用方按显示域映射语言包；msg 为中文缺省（兼容旧调用）
     */
    public static function attempt($account, $password)
    {
        // IP 维度二级限流：同一 IP 每分钟 ≤20 次登录尝试
        // 独立 options 计数器实现（不依赖审计表聚合）；被限流的请求不逐条写日志，
        // 避免攻击者以高频请求向只增不改的审计表持续灌数据
        if (!ip_throttle_allow('login', 20)) {
            return array('ok' => false, 'code' => 'throttled', 'args' => array(), 'msg' => self::msgOf('throttled'));
        }

        $isEmail = strpos($account, '@') !== false;
        $query = DB::query('users');
        if ($isEmail) {
            $user = $query->where('email', '=', $account)->first();
        } else {
            $user = $query->where('username', '=', $account)->first();
        }

        if (!$user) {
            blog_log('auth', 'login.fail', 'fail', array('account' => $account, 'reason' => 'not_found'));
            return array('ok' => false, 'code' => 'invalid_credentials', 'args' => array(), 'msg' => self::msgOf('invalid_credentials'));
        }

        // 用户名与邮箱别名共用账号锁；等锁后重读，防并发成功登录清除刚生效的锁定。
        $lockName = 'login_' . (int) $user['id'];
        if (!DB::lock($lockName, 5)) {
            return array('ok' => false, 'code' => 'throttled', 'args' => array(), 'msg' => self::msgOf('throttled'));
        }
        try {
            $user = DB::query('users')->where('id', '=', (int) $user['id'])->first();
            if (!$user) {
                blog_log('auth', 'login.fail', 'fail', array('account' => $account, 'reason' => 'not_found'));
                return array('ok' => false, 'code' => 'invalid_credentials', 'args' => array(), 'msg' => self::msgOf('invalid_credentials'));
            }
            return self::attemptUser($user, $account, $password);
        } finally {
            DB::unlock($lockName);
        }
    }

    /** 账号锁内完成状态校验与口令裁决，锁定细节仅进入审计日志 */
    private static function attemptUser(array $user, $account, $password)
    {
        // 锁定期检查：与密码错误统一提示，防止探测账号是否存在及其状态（原因仅入审计日志）
        if (!empty($user['locked_until']) && strtotime($user['locked_until']) > time()) {
            blog_log('auth', 'login.fail', 'fail', array('account' => $account, 'reason' => 'locked'));
            return array('ok' => false, 'code' => 'invalid_credentials', 'args' => array(), 'msg' => self::msgOf('invalid_credentials'));
        }

        if ((int) $user['status'] !== 1) {
            blog_log('auth', 'login.fail', 'fail', array('account' => $account, 'reason' => 'disabled'));
            return array('ok' => false, 'code' => 'invalid_credentials', 'args' => array(), 'msg' => self::msgOf('invalid_credentials'));
        }

        // 封禁：内容保留但禁止登录（不泄露账号状态细节，统一按认证失败提示）
        if (!empty($user['is_banned'])) {
            blog_log('auth', 'login.fail', 'fail', array('account' => $account, 'reason' => 'banned'));
            return array('ok' => false, 'code' => 'invalid_credentials', 'args' => array(), 'msg' => self::msgOf('invalid_credentials'));
        }

        // 注销：禁止登录，前台历史内容匿名展示由 Front 层处理
        if (!empty($user['is_deleted'])) {
            blog_log('auth', 'login.fail', 'fail', array('account' => $account, 'reason' => 'deleted'));
            return array('ok' => false, 'code' => 'invalid_credentials', 'args' => array(), 'msg' => self::msgOf('invalid_credentials'));
        }

        if (!password_verify($password, $user['password'])) {
            $maxFail = max(1, (int) Option::get('login_max_fail', 5));
            // 原子自增计数（LAST_INSERT_ID 表达式）：并发失败请求不会互相覆盖计数，
            // 否则攻击者以并发爆破可令计数长期低于阈值、绕过锁定
            $fail = DB::query('users')->where('id', '=', (int) $user['id'])->increment('login_fail');
            if ($fail === false) {
                // 极端情况（行被并发删除）：按已达上限处理，宁锁勿放
                return array('ok' => false, 'code' => 'invalid_credentials', 'args' => array(), 'msg' => self::msgOf('invalid_credentials'));
            }
            if ($fail >= $maxFail) {
                $lockMinutes = max(1, (int) Option::get('login_lock_minutes', 10));
                // 条件更新置锁定态：仅当计数仍达阈值时写入，避免与并发成功登录清零竞争
                DB::query('users')
                    ->where('id', '=', (int) $user['id'])
                    ->where('login_fail', '>=', $maxFail)
                    ->update(array(
                        'locked_until' => date('Y-m-d H:i:s', time() + $lockMinutes * 60),
                        'login_fail'   => 0,
                    ));
                blog_log('auth', 'user.locked', 'success', array('user_id' => (int) $user['id']));
            }
            blog_log('auth', 'login.fail', 'fail', array('user_id' => (int) $user['id'], 'fail_count' => $fail));
            return array('ok' => false, 'code' => 'invalid_credentials', 'args' => array(), 'msg' => self::msgOf('invalid_credentials'));
        }

        // 登录成功：清零失败计数并解除锁定
        DB::update('users', array('login_fail' => 0, 'locked_until' => null), array('id' => (int) $user['id']));
        self::loginUser($user);
        blog_log('auth', 'login', 'success', array('user_id' => (int) $user['id']));

        return array('ok' => true, 'code' => 'login_success', 'args' => array(), 'msg' => self::msgOf('login_success'));
    }

    /**
     * 建立登录会话（登录成功后必须重建 Session ID）
     *
     * @param array $user 用户行
     * @return void
     */
    public static function loginUser(array $user)
    {
        session_regenerate_id(true);
        $_SESSION['uid'] = (int) $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['last_active'] = time();
        $_SESSION['pwd_fp'] = self::passwordFingerprint($user['password']);
        unset($_SESSION['_csrf']);
        self::$user = null;
        self::$userLoaded = false;
        self::syncPasswordExpiry($user);
    }

    /** 所有登录通道与既有会话共用到期判定，管理员同样受开启后的策略约束 */
    private static function syncPasswordExpiry(array $user)
    {
        unset($_SESSION['pwd_expired']);
        if (Option::get('pwd_expire_enabled', '0') !== '1') {
            return;
        }
        $days = max(30, min(365, (int) Option::get('pwd_expire_days', 90)));
        $changedAt = empty($user['password_changed_at']) ? false : strtotime($user['password_changed_at']);
        if ($changedAt === false || $changedAt <= time() - $days * 86400) {
            $_SESSION['pwd_expired'] = 1;
        }
    }

    /**
     * 口令指纹：口令哈希的二级散列，存入会话用于改密后失效既有会话
     *
     * @param string $passwordHash users.password 字段值
     * @return string
     */
    private static function passwordFingerprint($passwordHash)
    {
        return hash('sha256', (string) $passwordHash);
    }

    /**
     * 登出：销毁会话并使 cookie 失效（剩余信息保护）
     *
     * @return void
     */
    public static function logout()
    {
        $uid = self::id();
        if ($uid > 0) {
            blog_log('auth', 'logout', 'success', array('user_id' => $uid));
        }
        $_SESSION = array();
        if (ini_get('session.use_cookies')) {
            $params = session_get_cookie_params();
            setcookie(
                session_name(),
                '',
                time() - 42000,
                $params['path'],
                $params['domain'],
                $params['secure'],
                $params['httponly']
            );
        }
        session_destroy();
        self::$user = null;
        self::$userLoaded = false;
    }

    /**
     * 口令复杂度统一校验（安装/注册/后台建号/改密四个入口共用）
     * 规则：8-64 位、四类字符至少三类、不含用户名、不命中弱口令黑名单
     *
     * @param string $password 待校验密码
     * @param string $username 关联用户名
     * @return string 错误提示（中文缺省）；空串表示通过
     */
    public static function validate_password_strength($password, $username = '')
    {
        return self::msgOf(self::validate_password_strength_code($password, $username));
    }

    /**
     * 口令复杂度校验（机器码版）：返回错误机器码（空串表示通过），
     * 文案由调用方按显示域映射（前台 theme_t / 后台 admin_t / 安装 ins_t），
     * 中文缺省统一由 msgOf() 提供
     *
     * @param string $password 待校验密码
     * @param string $username 关联用户名
     * @return string 机器码；空串表示通过
     */
    public static function validate_password_strength_code($password, $username = '')
    {
        $len = mb_strlen($password);
        if ($len < 8 || $len > 64) {
            return 'pwd_len';
        }
        $classes = 0;
        if (preg_match('/[A-Z]/', $password)) {
            $classes++;
        }
        if (preg_match('/[a-z]/', $password)) {
            $classes++;
        }
        if (preg_match('/[0-9]/', $password)) {
            $classes++;
        }
        if (preg_match('/[^A-Za-z0-9]/', $password)) {
            $classes++;
        }
        if ($classes < 3) {
            return 'pwd_classes';
        }
        if ($username !== '' && stripos($password, $username) !== false) {
            return 'pwd_contain_name';
        }
        $blacklist = apply_filters('password_blacklist', self::$weakPasswords);
        if (in_array(strtolower($password), array_map('strtolower', $blacklist), true)) {
            return 'pwd_weak';
        }
        return '';
    }

    /**
     * 修改密码统一入口：验证原密码 + 更新 password_changed_at + 审计
     *
     * @param int    $userId      用户 ID
     * @param string $oldPassword 原密码（管理员重置时传 null 跳过验证）
     * @param string $newPassword 新密码
     * @return array array('ok' => bool, 'msg' => string, 'code' => string, 'args' => array)
     *               code 为机器码，供调用方按显示域映射语言包；msg 为中文缺省（兼容旧调用）
     */
    public static function changePassword($userId, $oldPassword, $newPassword)
    {
        $user = DB::query('users')->where('id', '=', (int) $userId)->first();
        if (!$user) {
            return array('ok' => false, 'code' => 'user_not_found', 'args' => array(), 'msg' => self::msgOf('user_not_found'));
        }
        if ($oldPassword !== null && !password_verify($oldPassword, $user['password'])) {
            blog_log('auth', 'password.change', 'fail', array('user_id' => (int) $userId, 'reason' => 'old_wrong'));
            return array('ok' => false, 'code' => 'old_password_wrong', 'args' => array(), 'msg' => self::msgOf('old_password_wrong'));
        }
        $code = self::validate_password_strength_code($newPassword, $user['username']);
        if ($code !== '') {
            blog_log('auth', 'password.change', 'fail', array('user_id' => (int) $userId, 'reason' => $code));
            return array('ok' => false, 'code' => $code, 'args' => array(), 'msg' => self::msgOf($code));
        }
        // 密码历史校验（预留功能，默认关闭）
        $historyCount = (int) Option::get('pwd_history_count', 0);
        if ($historyCount > 0) {
            $history = DB::query('password_history')
                ->where('user_id', '=', (int) $userId)
                ->orderBy('id', 'DESC')
                ->limit($historyCount)
                ->select();
            foreach ($history as $row) {
                if (password_verify($newPassword, $row['password_hash'])) {
                    return array('ok' => false, 'code' => 'pwd_history_hit', 'args' => array($historyCount), 'msg' => self::msgOf('pwd_history_hit', array($historyCount)));
                }
            }
            DB::insert('password_history', array(
                'user_id'       => (int) $userId,
                'password_hash' => $user['password'],
                'created_at'    => now(),
            ));
        }
        $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
        DB::update('users', array(
            'password'            => $newHash,
            'password_changed_at' => now(),
        ), array('id' => (int) $userId));
        // 当前会话若是本人，同步刷新口令指纹保持登录态；其余会话指纹失配自动失效
        if (isset($_SESSION['uid']) && (int) $_SESSION['uid'] === (int) $userId) {
            $_SESSION['pwd_fp'] = self::passwordFingerprint($newHash);
            unset($_SESSION['pwd_expired']);
            self::$user = null;
            self::$userLoaded = false;
        }
        blog_log('auth', 'password.change', 'success', array('user_id' => (int) $userId));
        return array('ok' => true, 'code' => 'pwd_changed', 'args' => array(), 'msg' => self::msgOf('pwd_changed'));
    }

    /**
     * 会话空闲超时检查（默认 30 分钟）
     *
     * @return void
     */
    public static function checkSessionTimeout()
    {
        if (empty($_SESSION['uid'])) {
            return;
        }
        $timeout = max(1, (int) Option::get('session_timeout_minutes', 30)) * 60;
        $last = isset($_SESSION['last_active']) ? (int) $_SESSION['last_active'] : 0;
        if ($last > 0 && time() - $last > $timeout) {
            self::logout();
            return;
        }
        $_SESSION['last_active'] = time();
    }
}
