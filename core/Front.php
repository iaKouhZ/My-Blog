<?php
/**
 * 前台控制器：伪静态路由分发、认证表单、评论提交、验证码收发
 */
defined('APP_BOOT') or exit;

class Front
{
    /**
     * 前台请求分发入口
     *
     * @return void
     */
    public static function handle()
    {
        $route = Router::parse();
        $name = $route['route'];
        $params = $route['params'];

        switch ($name) {
            case 'home':
                self::listing('home', $params['page'], array(), '');
                break;
            case 'category':
                self::category($params);
                break;
            case 'author':
                self::author($params);
                break;
            case 'search':
                self::search();
                break;
            case 'post':
                self::post($params['key']);
                break;
            case 'page':
                self::singlePage($params['slug']);
                break;
            case 'login':
                self::login();
                break;
            case 'register':
                self::register();
                break;
            case 'forgot':
                self::forgot();
                break;
            case 'logout':
                if ($_SERVER['REQUEST_METHOD'] === 'POST') {
                    Csrf::verifyOrDie();
                    Auth::logout();
                }
                redirect(Router::url('home'));
                break;
            case 'comment_save':
                self::commentSave();
                break;
            case 'comment_update':
                self::commentUpdate();
                break;
            case 'comment_delete':
                self::commentDelete();
                break;
            case 'verify_send':
                self::verifySend();
                break;
            case 'lang_switch':
                self::langSwitch($params);
                break;
            default:
                // 插件自定义路由：经 route_parse 认领的路由名若注册了
                // front_route_{路由名} 动作则由插件接管，否则照常 404
                if (has_action('front_route_' . $name)) {
                    do_action('front_route_' . $name, $params);
                    break;
                }
                self::notFound();
        }
    }

    /**
     * 通用文章列表渲染
     */
    private static function listing($route, $page, array $extraWhere, $title)
    {
        $perPage = max(1, (int) Option::get('posts_per_page', 10));
        $query = DB::query('posts')
            ->where('status', '=', 'published')
            ->where('is_page', '=', 0);
        foreach ($extraWhere as $w) {
            $query->where($w[0], $w[1], $w[2]);
        }
        $total = $query->count();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $totalPages);

        $q = DB::query('posts')
            ->where('status', '=', 'published')
            ->where('is_page', '=', 0);
        foreach ($extraWhere as $w) {
            $q->where($w[0], $w[1], $w[2]);
        }
        $posts = $q->orderBy('is_top', 'DESC')->orderBy('created_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->select();
        self::attachAuthors($posts);
        // 插件可排序/修饰当前页文章（总数与分页在此之前已定，不宜移除条目）
        $posts = apply_filters('front_posts', $posts, $route);

        Theme::render('index', array(
            'page_type'  => 'index',
            'title'      => $title,
            'posts'      => $posts,
            'page'       => $page,
            'totalPages' => $totalPages,
            'route'      => $route,
            'routeParams' => $extraWhere ? array() : array(),
        ));
    }

    /**
     * 注销用户前台展示匿名化：用户名/昵称统一显示“用户已注销”，头像置空
     * （模板侧 avatar_url 遇空头像自动回退默认头像）；数据不删除，仅展示层匿名
     *
     * @param array $author 用户行（需含 is_deleted）
     * @return array
     */
    private static function maskDeletedAuthor(array $author)
    {
        if (!empty($author['is_deleted'])) {
            $author['nickname'] = theme_t('theme.front.deleted_user', array(), '用户已注销');
            if (isset($author['username'])) {
                $author['username'] = theme_t('theme.front.deleted_user', array(), '用户已注销');
            }
            $author['avatar'] = '';
            if (isset($author['signature'])) {
                $author['signature'] = '';
            }
        }
        return $author;
    }

    /** 为文章列表附加作者信息 */
    private static function attachAuthors(array &$posts)
    {
        if (empty($posts)) {
            return;
        }
        $ids = array();
        foreach ($posts as $post) {
            $ids[] = (int) $post['author_id'];
        }
        $users = DB::query('users')->whereIn('id', array_unique($ids))
            ->select(array('id', 'username', 'nickname', 'avatar', 'is_deleted'));
        $map = array();
        foreach ($users as $u) {
            $map[(int) $u['id']] = self::maskDeletedAuthor($u);
        }
        // 分类名映射
        $cats = DB::query('categories')->select(array('id', 'name', 'slug'));
        $catMap = array();
        foreach ($cats as $c) {
            $catMap[(int) $c['id']] = $c;
        }
        foreach ($posts as &$post) {
            $aid = (int) $post['author_id'];
            $post['author'] = isset($map[$aid]) ? $map[$aid] : array('nickname' => theme_t('theme.front.unknown_user', array(), '未知'), 'id' => 0, 'avatar' => '');
            $cid = (int) $post['category_id'];
            $post['category'] = isset($catMap[$cid]) ? $catMap[$cid] : null;
        }
        unset($post);
    }

    /** 分类归档 */
    private static function category(array $params)
    {
        $cat = DB::query('categories')->where('slug', '=', $params['slug'])->first();
        if (!$cat) {
            self::notFound();
            return;
        }
        self::renderArchive('category', $params['page'], array(
            array('category_id', '=', (int) $cat['id']),
        ), theme_t('theme.front.category_title', array($cat['name']), '分类：' . $cat['name']), array('slug' => $cat['slug']), $cat);
    }

    /** 作者归档 */
    private static function author(array $params)
    {
        $user = DB::query('users')->where('id', '=', $params['id'])->first(array('id', 'username', 'nickname', 'avatar', 'signature', 'is_deleted'));
        if (!$user) {
            self::notFound();
            return;
        }
        $user = self::maskDeletedAuthor($user);
        // 标题不带“作者：”前缀：主题作者页头部自行展示头像/昵称/签名三行
        self::renderArchive('author', $params['page'], array(
            array('author_id', '=', (int) $user['id']),
        ), $user['nickname'], array('id' => (int) $user['id']), $user);
    }

    /** 归档列表渲染（分类/作者共用） */
    private static function renderArchive($route, $page, array $extraWhere, $title, array $routeParams, $subject)
    {
        $perPage = max(1, (int) Option::get('posts_per_page', 10));
        $query = DB::query('posts')
            ->where('status', '=', 'published')
            ->where('is_page', '=', 0);
        foreach ($extraWhere as $w) {
            $query->where($w[0], $w[1], $w[2]);
        }
        $total = $query->count();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $totalPages);

        $q = DB::query('posts')
            ->where('status', '=', 'published')
            ->where('is_page', '=', 0);
        foreach ($extraWhere as $w) {
            $q->where($w[0], $w[1], $w[2]);
        }
        $posts = $q->orderBy('is_top', 'DESC')->orderBy('created_at', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)
            ->select();
        self::attachAuthors($posts);
        // 插件可排序/修饰当前页文章（总数与分页在此之前已定，不宜移除条目）
        $posts = apply_filters('front_posts', $posts, $route);

        Theme::render('archive', array(
            'page_type'   => 'archive',
            'title'       => $title,
            'posts'       => $posts,
            'page'        => $page,
            'totalPages'  => $totalPages,
            'route'       => $route,
            'routeParams' => $routeParams,
            'subject'     => $subject,
        ));
    }

    /** 搜索 */
    private static function search()
    {
        $kw = input_text('q', '', 50, 'get');
        $page = input_int('page', 1, 'get');
        $perPage = max(1, (int) Option::get('posts_per_page', 10));
        $posts = array();
        $total = 0;
        $totalPages = 1;
        if ($kw !== '') {
            $query = DB::query('posts')
                ->where('status', '=', 'published')
                ->where('is_page', '=', 0)
                ->likeAny(array('title', 'content', 'excerpt'), $kw);
            $total = $query->count();
            $totalPages = max(1, (int) ceil($total / $perPage));
            $page = min(max(1, $page), $totalPages);
            $q = DB::query('posts')
                ->where('status', '=', 'published')
                ->where('is_page', '=', 0)
                ->likeAny(array('title', 'content', 'excerpt'), $kw);
            $posts = $q->orderBy('is_top', 'DESC')->orderBy('created_at', 'DESC')
                ->limit($perPage, ($page - 1) * $perPage)
                ->select();
            self::attachAuthors($posts);
            // 插件可排序/修饰当前页文章（总数与分页在此之前已定，不宜移除条目）
            $posts = apply_filters('front_posts', $posts, 'search');
        }
        Theme::render('search', array(
            'page_type'   => 'search',
            'title'       => theme_t('theme.front.search_title', array(), '搜索'),
            'posts'       => $posts,
            'page'        => $page,
            'totalPages'  => $totalPages,
            'route'       => 'search',
            'routeParams' => array('q' => $kw),
            'kw'          => $kw,
            'total'       => $total,
        ));
    }

    /** 文章详情 */
    private static function post($key)
    {
        $query = DB::query('posts');
        if (ctype_digit($key)) {
            $post = $query->where('id', '=', (int) $key)->first();
        } else {
            $post = $query->where('slug', '=', $key)->first();
        }
        // 仅 published 对游客可见；作者与管理员可预览非删除状态文章
        if (!$post || $post['status'] === 'trash') {
            self::notFound();
            return;
        }
        if ($post['status'] !== 'published') {
            $allowed = Auth::isAdmin() || (Auth::check() && Auth::id() === (int) $post['author_id']);
            if (!$allowed) {
                self::notFound();
                return;
            }
        }

        // 浏览数 +1
        DB::query('posts')->where('id', '=', (int) $post['id'])
            ->update(array('views' => (int) $post['views'] + 1));

        $author = DB::query('users')->where('id', '=', (int) $post['author_id'])
            ->first(array('id', 'username', 'nickname', 'avatar', 'is_deleted'));
        if ($author) {
            $author = self::maskDeletedAuthor($author);
        }
        $category = null;
        if ((int) $post['category_id'] > 0) {
            $category = DB::query('categories')->where('id', '=', (int) $post['category_id'])->first();
        }
        $post['author'] = $author ? $author : array('nickname' => theme_t('theme.front.unknown_user', array(), '未知'), 'id' => 0, 'avatar' => '');
        $post['category'] = $category;

        // 评论：游客只见 published；作者本人可见自己的 pending
        // 插件可经 comment_area_state 按文章关闭评论区（list=false 时连查询一并跳过）
        $commentAreaState = apply_filters('comment_area_state', array('list' => true, 'form' => true, 'actions' => true), $post);
        $comments = array();
        if (!empty($commentAreaState['list'])) {
            $commentQuery = DB::query('comments')
                ->where('post_id', '=', (int) $post['id'])
                ->where('status', '=', 'published');
            $comments = $commentQuery->orderBy('created_at', 'ASC')->select();
            if (Auth::check()) {
                $mine = DB::query('comments')
                    ->where('post_id', '=', (int) $post['id'])
                    ->where('user_id', '=', Auth::id())
                    ->where('status', '=', 'pending')
                    ->orderBy('created_at', 'ASC')
                    ->select();
                $comments = array_merge($comments, $mine);
            }
        }
        $commentUsers = array();
        $uids = array();
        foreach ($comments as $c) {
            $uids[] = (int) $c['user_id'];
        }
        if (!empty($uids)) {
            $rows = DB::query('users')->whereIn('id', array_unique($uids))
                ->select(array('id', 'nickname', 'avatar', 'is_deleted'));
            foreach ($rows as $r) {
                $commentUsers[(int) $r['id']] = self::maskDeletedAuthor($r);
            }
        }
        foreach ($comments as &$c) {
            $uid = (int) $c['user_id'];
            $c['author'] = isset($commentUsers[$uid]) ? $commentUsers[$uid] : array('nickname' => theme_t('theme.front.default_nickname', array(), '用户'), 'avatar' => '');
        }
        unset($c);

        // 前台文章页输出受限 CSP（仅允许 self 本地化资源）
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'");

        // 评论行内编辑入口（?edit_comment={id}）：模板侧仅对本人评论渲染编辑表单，服务端提交时再次校验
        $editCommentId = Auth::check() ? input_int('edit_comment', 0, 'get') : 0;

        Theme::render('single', array(
            'page_type' => 'single',
            'title'     => $post['title'],
            'post'      => $post,
            'comments'  => $comments,
            'editCommentId' => $editCommentId,
        ));
    }

    /** 独立页面（key 为 slug；未填别名的页面回退数字 id 访问，与文章路由同策略） */
    private static function singlePage($key)
    {
        $query = DB::query('posts')
            ->where('is_page', '=', 1)
            ->where('status', '=', 'published');
        if (ctype_digit($key)) {
            $post = $query->where('id', '=', (int) $key)->first();
        } else {
            $post = $query->where('slug', '=', $key)->first();
        }
        if (!$post) {
            self::notFound();
            return;
        }
        header("Content-Security-Policy: default-src 'self'; img-src 'self' data:; style-src 'self'; script-src 'self'; font-src 'self'; object-src 'none'; base-uri 'self'; frame-ancestors 'self'");
        Theme::render('page', array(
            'page_type' => 'page',
            'title'     => $post['title'],
            'post'      => $post,
        ));
    }

    /** 登录页（GET 渲染 / POST 提交） */
    private static function login()
    {
        if (Auth::check()) {
            redirect(site_base_admin());
        }
        $error = '';
        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie();
            $account = input_text('account', '', 128, 'post');
            $password = input_password('password');
            if ($account === '' || $password === '') {
                $error = theme_t('theme.front.login_empty', array(), '请输入账号和密码');
            } else {
                $result = Auth::attempt($account, $password);
                if ($result['ok']) {
                    redirect(site_base_admin());
                }
                // 服务层只给机器码，按前台主题语言映射（中文缺省为 Auth::msgOf 原文）
                $error = theme_t('theme.front.auth.' . $result['code'], $result['args'], $result['msg']);
            }
        }
        Theme::render('login', array(
            'page_type' => 'login',
            'title'     => theme_t('theme.front.login_title', array(), '登录'),
            'error'     => $error,
            'account'   => isset($_POST['account']) ? input_text('account', '', 128, 'post') : '',
        ));
    }

    /** 注册页 */
    private static function register()
    {
        // 管理员可在后台关闭公开注册；GET/POST 均在此拦截
        if (Option::get('register_disabled', '0') === '1') {
            flash_set('error', theme_t('theme.front.register_closed', array(), '本站已关闭公开注册，如需账号请联系管理员'));
            redirect(Router::url('login'));
        }
        if (Auth::check()) {
            redirect(site_base_admin());
        }
        // 验证码渠道能力按插件声明探测（register_verify_provider），不硬编码插件名
        $emailEnabled = get_verify_provider('email') !== null;
        $smsEnabled = get_verify_provider('sms') !== null;
        $error = '';
        $old = array('username' => '', 'nickname' => '', 'email' => '', 'phone' => '');

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie();
            // 注册提交限流：注册接口的"用户名/邮箱/手机号已占用"提示可被用于批量枚举，
            // 以 IP 维度限速抬高枚举成本（60s 窗口内超过 10 次即拒绝）
            if (!ip_throttle_allow('register', 10)) {
                $error = theme_t('theme.front.throttled', array(), '操作过于频繁，请稍后再试');
                Theme::render('register', array(
                    'page_type'    => 'register',
                    'title'        => theme_t('theme.front.register_title', array(), '注册'),
                    'error'        => $error,
                    'old'          => $old,
                    'emailEnabled' => $emailEnabled,
                    'smsEnabled'   => $smsEnabled,
                ));
                return;
            }
            $username = input_text('username', '', 32, 'post');
            $nickname = input_text('nickname', '', 64, 'post');
            $email = input_email('email', '', 'post');
            $phone = input_phone('phone', '', 'post');
            $password = input_password('password');
            $password2 = input_password('password2');
            $old = array('username' => $username, 'nickname' => $nickname, 'email' => $email, 'phone' => $phone);

            do {
                if (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $username)) {
                    $error = theme_t('theme.front.username_invalid', array(), '用户名为 3-32 位字母、数字或下划线');
                    break;
                }
                if (DB::query('users')->where('username', '=', $username)->value('id')) {
                    $error = theme_t('theme.front.username_taken', array(), '用户名已被占用');
                    break;
                }
                if ($email !== '' && DB::query('users')->where('email', '=', $email)->value('id')) {
                    $error = theme_t('theme.front.email_taken', array(), '邮箱已被注册');
                    break;
                }
                // 手机号作为身份核验凭据，必须全局唯一，防止一号多绑
                if ($phone !== '' && DB::query('users')->where('phone', '=', $phone)->value('id')) {
                    $error = theme_t('theme.front.phone_taken', array(), '该手机号已被注册');
                    break;
                }
                if ($password !== $password2) {
                    $error = theme_t('theme.front.password_mismatch', array(), '两次输入的密码不一致');
                    break;
                }
                $pwdCode = Auth::validate_password_strength_code($password, $username);
                if ($pwdCode !== '') {
                    $error = theme_t('theme.front.auth.' . $pwdCode, array(), Auth::msgOf($pwdCode));
                    break;
                }
                // 验证码核验（启用对应插件时才要求）；原子消费，同一验证码并发下只能使用一次
                if ($emailEnabled) {
                    $code = input_text('email_code', '', 6, 'post');
                    if ($email === '' || !self::consumeVerifyCode('register', $email, $code, 'email')) {
                        $error = theme_t('theme.front.email_code_invalid', array(), '邮箱验证码错误或已过期');
                        break;
                    }
                }
                if ($smsEnabled) {
                    // 短信插件启用时手机号必填且必须通过验证码核验
                    if ($phone === '') {
                        $error = theme_t('theme.front.phone_required', array(), '请输入手机号');
                        break;
                    }
                    $code = input_text('sms_code', '', 6, 'post');
                    if (!self::consumeVerifyCode('register', $phone, $code, 'sms')) {
                        $error = theme_t('theme.front.sms_code_invalid', array(), '短信验证码错误或已过期');
                        break;
                    }
                }

                // 公开注册只能产生 role=user（功能红线）
                $userId = DB::insert('users', array(
                    'username'            => $username,
                    'nickname'            => $nickname !== '' ? $nickname : $username,
                    'password'            => password_hash($password, PASSWORD_DEFAULT),
                    'email'               => $email !== '' ? $email : null,
                    'phone'               => $phone,
                    'avatar'              => '',
                    'role'                => 'user',
                    'status'              => 1,
                    'password_changed_at' => now(),
                    'login_fail'          => 0,
                    'locked_until'        => null,
                    'created_at'          => now(),
                ));
                blog_log('user', 'user.register', 'success', array('user_id' => $userId, 'username' => $username));
                do_action('user_register', $userId, array(
                    'username' => $username, 'email' => $email, 'phone' => $phone,
                ));
                flash_set('success', theme_t('theme.front.register_success', array(), '注册成功，请登录'));
                redirect(Router::url('login'));
            } while (false);
        }

        Theme::render('register', array(
            'page_type'    => 'register',
            'title'        => theme_t('theme.front.register_title', array(), '注册'),
            'error'        => $error,
            'old'          => $old,
            'emailEnabled' => $emailEnabled,
            'smsEnabled'   => $smsEnabled,
        ));
    }

    /** 找回密码页（两步：发送验证码 → 核验并设新密码） */
    private static function forgot()
    {
        if (Auth::check()) {
            redirect(site_base_admin());
        }
        // 验证码渠道能力按插件声明探测（register_verify_provider），不硬编码插件名
        $emailEnabled = get_verify_provider('email') !== null;
        $smsEnabled = get_verify_provider('sms') !== null;
        $error = '';
        $info = '';
        $account = '';
        if (!$emailEnabled && !$smsEnabled) {
            Theme::render('forgot', array(
                'page_type'    => 'forgot',
                'title'        => theme_t('theme.front.forgot_title', array(), '找回密码'),
                'error'        => '',
                'info'         => theme_t('theme.front.forgot_no_provider', array(), '站点未启用邮箱或短信验证插件，请联系管理员重置密码。'),
                'emailEnabled' => false,
                'smsEnabled'   => false,
            ));
            return;
        }

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            Csrf::verifyOrDie();
            $account = input_text('account', '', 128, 'post');
            $user = null;
            if ($account !== '') {
                if (strpos($account, '@') !== false) {
                    $user = DB::query('users')->where('email', '=', $account)->first();
                } else {
                    $user = DB::query('users')->where('username', '=', $account)->first();
                }
            }
            $code = input_text('code', '', 6, 'post');
            $newPassword = input_password('new_password');
            $newPassword2 = input_password('new_password2');

            do {
                // 与账号存在性无关的格式校验先行：避免"存在账号才走到的分支"
                // 形成存在性预言机（枚举用户名）。
                // 强度校验的 username 参数以提交的 account 等效代入：
                // 该检查仅用于"密码不得包含用户名"，而提交者本身知晓账号名，
                // 用请求中的账号名判定不泄露任何额外信息
                if ($newPassword !== $newPassword2) {
                    $error = theme_t('theme.front.new_password_mismatch', array(), '两次输入的新密码不一致');
                    break;
                }
                if ($account === '' || $newPassword === '') {
                    // 统一话术：不区分"未填账号"与"账号不存在"
                    $error = theme_t('theme.front.reset_generic', array(), '若账号存在且信息正确，密码将被重置');
                    break;
                }
                $pwdCode = Auth::validate_password_strength_code($newPassword, $account);
                if ($pwdCode !== '') {
                    $error = theme_t('theme.front.auth.' . $pwdCode, array(), Auth::msgOf($pwdCode));
                    break;
                }
                if (!$user) {
                    // 不暴露账号是否存在，统一提示
                    $error = theme_t('theme.front.reset_generic', array(), '若账号存在且信息正确，密码将被重置');
                    break;
                }
                // target/channel 解析必须与 verifySend 的发送侧完全一致：
                // 用户名账号在"有手机号但短信插件未启用"时，发送侧走邮箱，
                // 核验侧若按手机号查询将永远无法匹配（找回密码功能性损坏）
                if (strpos($account, '@') !== false) {
                    $target = $user['email'];
                    $channel = 'email';
                } elseif (!empty($user['phone']) && $smsEnabled) {
                    $target = $user['phone'];
                    $channel = 'sms';
                } else {
                    $target = $user['email'];
                    $channel = 'email';
                }
                if (empty($target)) {
                    // 统一话术：未绑定联系方式也按"账号可能不存在"处理，防止枚举
                    $error = theme_t('theme.front.reset_generic', array(), '若账号存在且信息正确，密码将被重置');
                    break;
                }
                // 原子消费验证码：核验与标记已用在同一条件更新中完成，无并发复用窗口
                if (!self::consumeVerifyCode('reset', $target, $code, $channel)) {
                    // 统一话术：验证码错误不暴露"该账号确实存在"（验证码仅真实账号能收到）
                    $error = theme_t('theme.front.reset_generic', array(), '若账号存在且信息正确，密码将被重置');
                    break;
                }
                $result = Auth::changePassword((int) $user['id'], null, $newPassword);
                if (!$result['ok']) {
                    $error = theme_t('theme.front.auth.' . $result['code'], $result['args'], $result['msg']);
                    break;
                }
                blog_log('auth', 'password.reset', 'success', array('user_id' => (int) $user['id']));
                do_action('password_reset', (int) $user['id']);
                flash_set('success', theme_t('theme.front.reset_success', array(), '密码已重置，请使用新密码登录'));
                redirect(Router::url('login'));
            } while (false);
        }

        Theme::render('forgot', array(
            'page_type'    => 'forgot',
            'title'        => theme_t('theme.front.forgot_title', array(), '找回密码'),
            'error'        => $error,
            'info'         => $info,
            // 失败后回填账号（非敏感信息；密码与验证码不回显），避免用户重填
            'account'      => $account,
            'emailEnabled' => $emailEnabled,
            'smsEnabled'   => $smsEnabled,
        ));
    }

    /** 评论提交（POST + CSRF + 登录） */
    private static function commentSave()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(Router::url('home'));
        }
        Csrf::verifyOrDie();
        if (!Auth::check()) {
            flash_set('error', theme_t('theme.front.comment_login_required', array(), '请先登录后再发表评论'));
            redirect(Router::url('login'));
        }
        Auth::require_cap('comment');

        $postId = input_int('post_id', 0, 'post');
        $parentId = input_int('parent_id', 0, 'post');
        $content = input_text('content', '', 2000, 'post');

        $back = Router::url('home');
        $candidate = input_text('redirect', '', 255, 'post');
        if ($candidate !== '') {
            // 防开放重定向：仅允许站内安全字符组成的相对路径
            if (preg_match('#^[A-Za-z0-9/_.?=&%-]+$#', $candidate)
                && strpos($candidate, '//') === false
                && strpos($candidate, Router::base() . '/') === 0) {
                $back = $candidate;
            }
        }

        $post = DB::query('posts')->where('id', '=', $postId)->where('status', '=', 'published')->first();
        if (!$post) {
            flash_set('error', theme_t('theme.front.post_not_found', array(), '文章不存在'));
            redirect($back);
        }
        // 插件可按文章拦截评论写入（如评论区关闭）；后台评论管理不受影响
        if (apply_filters('comment_write_allowed', true, 'create', $postId, 0) === false) {
            blog_log('comment', 'comment.create', 'fail', array('post_id' => $postId, 'reason' => 'write_denied'));
            flash_set('error', theme_t('theme.front.comment_closed', array(), '该文章已关闭评论功能'));
            redirect($back);
        }
        if ($content === '') {
            flash_set('error', theme_t('theme.front.comment_empty', array(), '评论内容不能为空'));
            redirect($back);
        }
        if ($parentId > 0) {
            $parent = DB::query('comments')->where('id', '=', $parentId)->where('post_id', '=', $postId)->first();
            if (!$parent) {
                $parentId = 0;
            } elseif ((int) $parent['parent_id'] > 0) {
                // 仅支持一层回复
                $parentId = (int) $parent['parent_id'];
            }
        }

        $data = array(
            'post_id'   => $postId,
            'user_id'   => Auth::id(),
            'parent_id' => $parentId,
            'content'   => $content,
        );
        // 插件可拦截/改写评论内容
        $data = apply_filters('comment_before_save', $data);
        if (!is_array($data) || empty($data['content'])) {
            flash_set('error', theme_t('theme.front.comment_blocked', array(), '评论被拦截'));
            redirect($back);
        }
        $data['status'] = Option::get('comment_audit', '0') === '1' ? 'pending' : 'published';
        $data['created_at'] = now();
        $commentId = DB::insert('comments', $data);
        // detail 中 content 为落库最终内容（原文快照，Logger 对其不作手机号/邮箱打码）
        blog_log('comment', 'comment.create', 'success', array(
            'comment_id' => $commentId, 'post_id' => $postId, 'status' => $data['status'],
            'content' => $data['content'],
        ));
        flash_set('success', $data['status'] === 'pending'
            ? theme_t('theme.front.comment_pending', array(), '评论已提交，等待审核')
            : theme_t('theme.front.comment_success', array(), '评论发表成功'));
        redirect($back);
    }

    /** 评论所属文章的回跳 URL（可带锚点定位回原评论） */
    private static function commentBackUrl(array $comment, $anchor = '')
    {
        $post = DB::query('posts')->where('id', '=', (int) $comment['post_id'])
            ->first(array('id', 'slug'));
        if (!$post) {
            return Router::url('home');
        }
        return Router::url('post', array('slug' => (string) $post['slug'], 'id' => (int) $post['id'])) . $anchor;
    }

    /** 修改自己的评论（POST + CSRF + 登录 + edit_own_comments） */
    private static function commentUpdate()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(Router::url('home'));
        }
        Csrf::verifyOrDie();
        if (!Auth::check()) {
            flash_set('error', theme_t('theme.front.comment_login_operate', array(), '请先登录后再操作评论'));
            redirect(Router::url('login'));
        }
        Auth::require_cap('edit_own_comments');

        $commentId = input_int('comment_id', 0, 'post');
        $content = input_text('content', '', 2000, 'post');
        $comment = DB::query('comments')->where('id', '=', $commentId)->first();
        if (!$comment || $comment['status'] === 'trash') {
            flash_set('error', theme_t('theme.front.comment_not_found', array(), '评论不存在'));
            redirect(Router::url('home'));
        }
        $back = self::commentBackUrl($comment, '#comment-' . (int) $comment['id']);
        // 仅允许修改本人评论（管理员管理全部评论走后台 comment 模块）
        if ((int) $comment['user_id'] !== Auth::id()) {
            blog_log('comment', 'comment.update', 'fail', array('comment_id' => $commentId, 'reason' => 'not_owner'));
            flash_set('error', theme_t('theme.front.comment_edit_own', array(), '只能修改自己发表的评论'));
            redirect($back);
        }
        // 与创建同一拦截过滤器（动作为 update），插件可按文章禁止修改
        if (apply_filters('comment_write_allowed', true, 'update', (int) $comment['post_id'], $commentId) === false) {
            blog_log('comment', 'comment.update', 'fail', array('comment_id' => $commentId, 'reason' => 'write_denied'));
            flash_set('error', theme_t('theme.front.comment_closed', array(), '该文章已关闭评论功能'));
            redirect($back);
        }
        if ($content === '') {
            flash_set('error', theme_t('theme.front.comment_empty', array(), '评论内容不能为空'));
            redirect($back);
        }
        $data = array(
            'post_id'   => (int) $comment['post_id'],
            'user_id'   => (int) $comment['user_id'],
            'parent_id' => (int) $comment['parent_id'],
            'content'   => $content,
        );
        // 与新建评论同一过滤链，插件可拦截/改写
        $data = apply_filters('comment_before_save', $data);
        if (!is_array($data) || empty($data['content'])) {
            flash_set('error', theme_t('theme.front.comment_blocked', array(), '评论被拦截'));
            redirect($back);
        }
        // 评论审核开关开启时，修改后的内容需重新过审，防止绕过审核
        $status = Option::get('comment_audit', '0') === '1' ? 'pending' : $comment['status'];
        DB::update('comments', array(
            'content' => $data['content'],
            'status'  => $status,
        ), array('id' => $commentId));
        // content 记录修改后落库的最终内容（原文快照，同 comment.create）
        blog_log('comment', 'comment.update', 'success', array(
            'comment_id' => $commentId, 'status' => $status,
            'content' => $data['content'],
        ));
        flash_set('success', $status === 'pending'
            ? theme_t('theme.front.comment_updated_pending', array(), '评论已修改，待审核后显示')
            : theme_t('theme.front.comment_updated', array(), '评论已修改'));
        redirect($back);
    }

    /** 删除自己的评论（软删入回收站；存在回复时禁止删除） */
    private static function commentDelete()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            redirect(Router::url('home'));
        }
        Csrf::verifyOrDie();
        if (!Auth::check()) {
            flash_set('error', theme_t('theme.front.comment_login_operate', array(), '请先登录后再操作评论'));
            redirect(Router::url('login'));
        }
        Auth::require_cap('delete_own_comments');

        $commentId = input_int('comment_id', 0, 'post');
        $comment = DB::query('comments')->where('id', '=', $commentId)->first();
        if (!$comment || $comment['status'] === 'trash') {
            flash_set('error', theme_t('theme.front.comment_not_found', array(), '评论不存在'));
            redirect(Router::url('home'));
        }
        $back = self::commentBackUrl($comment);
        if ((int) $comment['user_id'] !== Auth::id()) {
            blog_log('comment', 'comment.delete', 'fail', array('comment_id' => $commentId, 'reason' => 'not_owner'));
            flash_set('error', theme_t('theme.front.comment_delete_own', array(), '只能删除自己发表的评论'));
            redirect($back);
        }
        // 与创建同一拦截过滤器（动作为 delete），插件可按文章禁止删除
        if (apply_filters('comment_write_allowed', true, 'delete', (int) $comment['post_id'], $commentId) === false) {
            blog_log('comment', 'comment.delete', 'fail', array('comment_id' => $commentId, 'reason' => 'write_denied'));
            flash_set('error', theme_t('theme.front.comment_closed', array(), '该文章已关闭评论功能'));
            redirect($back);
        }
        // 有未删回复时禁止删除，避免回复成为无法展示的孤儿数据
        $childCount = DB::query('comments')
            ->where('parent_id', '=', $commentId)
            ->where('status', '!=', 'trash')
            ->count();
        if ($childCount > 0) {
            flash_set('error', theme_t('theme.front.comment_has_replies', array(), '该评论存在回复，无法删除'));
            redirect($back);
        }
        DB::update('comments', array('status' => 'trash'), array('id' => $commentId));
        blog_log('comment', 'comment.delete', 'success', array('comment_id' => $commentId));
        flash_set('success', theme_t('theme.front.comment_deleted', array(), '评论已删除'));
        redirect($back);
    }

    /** 发送验证码（AJAX） */
    private static function verifySend()
    {
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            json_out(array('code' => 405, 'msg' => 'Method Not Allowed'));
        }
        Csrf::verifyOrDie();
        $scene = input_enum('scene', array('register', 'reset', 'profile'), '', 'post');
        $channel = input_enum('channel', array('email', 'sms'), '', 'post');

        if ($scene === '') {
            json_out(array('code' => 1, 'msg' => theme_t('theme.front.verify_param_incomplete', array(), '参数不完整')));
        }
        // IP 维度限流：防止攻击者枚举大量不同目标把本站当作邮件/短信轰炸器
        if (!ip_throttle_allow('verify_send', 10)) {
            json_out(array('code' => 1, 'msg' => theme_t('theme.front.throttled', array(), '操作过于频繁，请稍后再试')));
        }
        // 个人资料改绑场景仅限登录用户，防止被当作对外发码接口滥用
        if ($scene === 'profile' && !Auth::check()) {
            blog_log('verify', 'verify.send', 'fail', array('scene' => $scene, 'channel' => $channel, 'reason' => 'login_required'));
            json_out(array('code' => 1, 'msg' => theme_t('theme.front.login_required', array(), '请先登录')));
        }

        if ($scene === 'reset') {
            // 找回场景：target 为用户名或邮箱，由服务端解析为实际联系方式与渠道，
            // 不能先按邮箱/手机格式预校验（用户名必然不通过，会误报“参数不完整”）
            $account = input_text('target', '', 128, 'post');
            if ($account === '') {
                json_out(array('code' => 1, 'msg' => theme_t('theme.front.verify_param_incomplete', array(), '参数不完整')));
            }
            $target = '';
            if (strpos($account, '@') !== false) {
                $user = DB::query('users')->where('email', '=', $account)->first();
                $channel = 'email';
                $target = $user ? (string) $user['email'] : '';
            } else {
                $user = DB::query('users')->where('username', '=', $account)->first();
                if ($user) {
                    if (!empty($user['phone']) && get_verify_provider('sms') !== null) {
                        $channel = 'sms';
                        $target = $user['phone'];
                    } else {
                        $channel = 'email';
                        $target = (string) $user['email'];
                    }
                }
            }
            // 不暴露账号是否存在：统一提示已发送（若真实存在才会收到验证码）
            if ($target === '') {
                blog_log('verify', 'verify.send', 'fail', array('scene' => $scene, 'account' => $account, 'reason' => 'target_unavailable'));
                json_out(array('code' => 0, 'msg' => theme_t('theme.front.verify_sent', array(), '验证码已发送')));
            }
        } else {
            $target = $channel === 'email'
                ? input_email('target', '', 'post')
                : input_phone('target', '', 'post');
            if ($channel === '' || $target === '') {
                json_out(array('code' => 1, 'msg' => theme_t('theme.front.verify_param_incomplete', array(), '参数不完整')));
            }
        }

        // 渠道可用性按插件声明判断（第三方插件声明后即放行）
        if (get_verify_provider($channel) === null) {
            blog_log('verify', 'verify.send', 'fail', array('scene' => $scene, 'channel' => $channel, 'target' => $target, 'reason' => 'channel_unavailable'));
            json_out(array('code' => 1, 'msg' => theme_t('theme.front.verify_channel_off', array(), '该渠道未启用验证码插件')));
        }

        // 按目标加命名锁：60s 间隔/每日上限/实际发送必须互斥完成，
        // 否则并发请求同时通过 count()==0 检查会超限群发（TOCTOU）
        // 邮箱查询使用不区分大小写的排序规则，锁键也须归一，避免大小写别名分到不同锁。
        $lockName = 'vsend_' . md5($channel . '|' . strtolower($target));
        if (!DB::lock($lockName, 5)) {
            blog_log('verify', 'verify.send', 'fail', array('scene' => $scene, 'channel' => $channel, 'target' => $target, 'reason' => 'lock_timeout'));
            json_out(array('code' => 1, 'msg' => theme_t('theme.front.throttled', array(), '操作过于频繁，请稍后再试')));
        }

        // 频率限制：60s 重发间隔
        $recent = DB::query('verify_codes')
            ->where('scene', '=', $scene)
            ->where('target', '=', $target)
            ->where('created_at', '>', date('Y-m-d H:i:s', time() - 60))
            ->count();
        if ($recent > 0) {
            DB::unlock($lockName);
            blog_log('verify', 'verify.send', 'fail', array('scene' => $scene, 'channel' => $channel, 'target' => $target, 'reason' => 'resend_interval'));
            json_out(array('code' => 1, 'msg' => theme_t('theme.front.verify_retry', array(), '发送过于频繁，请 60 秒后重试')));
        }
        // 每日上限：同目标每日 ≤10 条
        $today = DB::query('verify_codes')
            ->where('target', '=', $target)
            ->where('created_at', '>', date('Y-m-d 00:00:00'))
            ->count();
        if ($today >= 10) {
            DB::unlock($lockName);
            blog_log('verify', 'verify.send', 'fail', array('scene' => $scene, 'channel' => $channel, 'target' => $target, 'reason' => 'daily_limit'));
            json_out(array('code' => 1, 'msg' => theme_t('theme.front.verify_daily_limit', array(), '今日发送次数已达上限')));
        }

        try {
            $code = (string) random_int(100000, 999999);
            $handled = apply_filters('send_verify_code', false, $scene, $target, $channel, $code);
        } catch (Throwable $t) {
            // 捕 Throwable 而非 Exception：插件抛 Error/TypeError 同样必须走到解锁
            $handled = false;
        }
        // 发送裁决完成即释放锁：插件落库验证码在过滤器内完成，锁保护的是
        // "检查配额→插件写入"这一临界区，发送结果不再依赖持锁
        DB::unlock($lockName);
        if ($handled === true) {
            // 验证码发送必须留痕（等保审计；target 由 Logger 自动脱敏）
            blog_log('verify', 'verify.send', 'success', array(
                'scene' => $scene, 'channel' => $channel, 'target' => $target,
            ));
            json_out(array('code' => 0, 'msg' => theme_t('theme.front.verify_sent', array(), '验证码已发送')));
        }
        blog_log('verify', 'verify.send', 'fail', array('scene' => $scene, 'channel' => $channel, 'target' => $target, 'reason' => 'provider_failed'));
        json_out(array('code' => 1, 'msg' => theme_t('theme.front.verify_unavailable', array(), '发送渠道不可用')));
    }

    /**
     * 验证码核验并原子消费（唯一核验入口，仅表单提交时调用）：
     * 核验通过后以 used=0 条件更新标记已用，影响行数为 0 即被并发请求抢先消费，
     * 彻底消除“先核验后标记”之间的重放/并发复用窗口。
     * 系统不提供独立的预检/即时反馈接口：验证码对错不向前端暴露，
     * 仅在此处一次性裁决，失败统一提示“验证码错误或已过期”，防止有效性探测
     */
    public static function consumeVerifyCode($scene, $target, $code, $channel)
    {
        $pluginResult = apply_filters('verify_code_check', null, $scene, $target, $code, $channel);
        if ($pluginResult !== null) {
            $ok = (bool) $pluginResult;
        } else {
            $ok = self::localConsumeCode($scene, $target, $code, $channel);
        }
        // 核验行为留痕（等保审计；target 由 Logger 自动脱敏）
        blog_log('verify', 'verify.check', $ok ? 'success' : 'fail', array(
            'scene' => $scene, 'channel' => $channel, 'target' => $target,
        ));
        return $ok;
    }

    /** 本地 verify_codes 表核验 + 原子标记已用 */
    private static function localConsumeCode($scene, $target, $code, $channel)
    {
        // 错误容忍次数归声明者插件管理（按渠道声明取 slug，不硬编码插件名）；
        // 内核仅负责范围钳制（1-5），无声明者取缺省 2
        $provider = get_verify_provider($channel);
        $maxAttempts = $provider !== null
            ? max(1, min(5, (int) plugin_option($provider, 'max_attempts', 2)))
            : 2;
        // 与发送共用目标锁：错误计数、消费与重发互斥，不能靠读取后写回抵抗并发猜码。
        $lockName = 'vsend_' . md5($channel . '|' . strtolower($target));
        if (!DB::lock($lockName, 5)) {
            return false;
        }
        try {
            // 必须裁决最新一条；过滤 used=0 会在新码消费后重新暴露仍未过期的旧码。
            $row = DB::query('verify_codes')
                ->where('scene', '=', $scene)
                ->where('target', '=', $target)
                ->where('channel', '=', $channel)
                ->orderBy('id', 'DESC')
                ->first();
            if (!$row || (int) $row['used'] !== 0
                || strtotime($row['expires_at']) <= time()
                || (int) $row['attempts'] >= $maxAttempts
            ) {
                return false;
            }
            if (!hash_equals((string) $row['code'], (string) $code)) {
                $fail = DB::query('verify_codes')
                    ->where('id', '=', (int) $row['id'])
                    ->where('used', '=', 0)
                    ->increment('attempts');
                if ($fail !== false && $fail >= $maxAttempts) {
                    DB::query('verify_codes')->where('id', '=', (int) $row['id'])
                        ->where('used', '=', 0)->update(array('used' => 1));
                }
                return false;
            }
            // 除一次性标记外，提交时复检有效期与尝试次数，避免等待期间越过边界。
            $affected = DB::query('verify_codes')
                ->where('id', '=', (int) $row['id'])
                ->where('used', '=', 0)
                ->where('attempts', '<', $maxAttempts)
                ->where('expires_at', '>', now())
                ->update(array('used' => 1));
            return $affected > 0;
        } finally {
            DB::unlock($lockName);
        }
    }

    /** 统一 404 页 */
    public static function notFound()
    {
        http_response_code(404);
        Theme::render('404', array(
            'page_type' => '404',
            'title'     => theme_t('theme.front.notfound_title', array(), '页面不存在'),
        ));
    }

    /**
     * 前台语言切换（GET）：语言码白名单校验后写 cookie 偏好并安全回跳。
     * 游客操作、不改服务端状态，无需 CSRF 与审计；偏好优先级高于浏览器自动检测
     *
     * @param array $params 路由参数（code）
     * @return void
     */
    private static function langSwitch(array $params)
    {
        $code = isset($params['code']) ? $params['code'] : '';
        $available = Theme::availableLangs();
        if (isset($available[$code])) {
            // 偏好 cookie 与会话 cookie 同口径：HttpOnly + SameSite=Lax，HTTPS 下加 Secure
            setcookie(Theme::LANG_COOKIE, $code, array(
                'expires'  => time() + 365 * 86400,
                'path'     => '/',
                'secure'   => is_https(),
                'httponly' => true,
                'samesite' => 'Lax',
            ));
        }
        // 防开放重定向：仅允许站内安全字符组成的相对路径（与评论提交回跳同口径）
        $back = Router::url('home');
        $candidate = input_text('redirect', '', 255, 'get');
        if ($candidate !== ''
            && preg_match('#^[A-Za-z0-9/_.?=&%-]+$#', $candidate)
            && strpos($candidate, '//') === false
            && strpos($candidate, Router::base() . '/') === 0) {
            $back = $candidate;
        }
        redirect($back);
    }
}
