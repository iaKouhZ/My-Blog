<?php
/**
 * 伪静态路由：解析请求路径 + 全站 URL 统一生成（模板/代码禁止手写站内路径）
 */
defined('APP_BOOT') or exit;

class Router
{
    /** @var string|null 站点基础路径（子目录部署时非空） */
    private static $base = null;

    /**
     * 计算站点基础路径（由 index.php 的 SCRIPT_NAME 推导）
     *
     * @return string
     */
    public static function base()
    {
        if (self::$base !== null) {
            return self::$base;
        }
        $script = isset($_SERVER['SCRIPT_NAME']) ? $_SERVER['SCRIPT_NAME'] : '/index.php';
        $dir = str_replace('\\', '/', dirname($script));
        // 从 user/、install/ 子目录入口进入时回退到站点根，
        // 否则后台链接会拼出 /user/assets/…、/user/user/index.php 等错误路径
        $dir = preg_replace('#/(user|install)$#', '', $dir);
        self::$base = ($dir === '/' || $dir === '.') ? '' : rtrim($dir, '/');
        return self::$base;
    }

    /** 是否启用伪静态重写（安装自检/后台设置决定） */
    public static function rewriteEnabled()
    {
        return Option::get('rewrite_enabled', '1') === '1';
    }

    /**
     * 解析当前请求路由
     *
     * @return array array('route' => 路由名, 'params' => 参数)
     */
    public static function parse()
    {
        $path = self::requestPath();
        $path = trim($path, '/');

        if ($path === '') {
            return array('route' => 'home', 'params' => array('page' => 1));
        }

        // 首页分页 /page/{n}
        if (preg_match('#^page/(\d+)$#', $path, $m)) {
            return array('route' => 'home', 'params' => array('page' => max(1, (int) $m[1])));
        }

        // 独立页面 /page/{slug}.html
        if (preg_match('#^page/([a-z0-9][a-z0-9-]*)\.html$#', $path, $m)) {
            return array('route' => 'page', 'params' => array('slug' => $m[1]));
        }

        // 文章详情 /post/{id|slug}.html
        if (preg_match('#^post/([a-z0-9][a-z0-9-]*)\.html$#', $path, $m)) {
            return array('route' => 'post', 'params' => array('key' => $m[1]));
        }

        // 分类归档 /category/{slug}[/page/{n}]
        if (preg_match('#^category/([a-z0-9][a-z0-9-]*)(?:/page/(\d+))?$#', $path, $m)) {
            return array('route' => 'category', 'params' => array(
                'slug' => $m[1],
                'page' => isset($m[2]) && $m[2] !== '' ? max(1, (int) $m[2]) : 1,
            ));
        }

        // 作者归档 /author/{id}[/page/{n}]
        if (preg_match('#^author/(\d+)(?:/page/(\d+))?$#', $path, $m)) {
            return array('route' => 'author', 'params' => array(
                'id'   => (int) $m[1],
                'page' => isset($m[2]) && $m[2] !== '' ? max(1, (int) $m[2]) : 1,
            ));
        }

        // 搜索 /search?q=...
        if ($path === 'search') {
            return array('route' => 'search', 'params' => array());
        }

        // 认证表单页与动作
        if (in_array($path, array('login', 'register', 'forgot'), true)) {
            return array('route' => $path, 'params' => array());
        }
        if ($path === 'logout') {
            return array('route' => 'logout', 'params' => array());
        }

        // 前台 AJAX/POST 动作
        if ($path === 'comment/save') {
            return array('route' => 'comment_save', 'params' => array());
        }
        if ($path === 'comment/update') {
            return array('route' => 'comment_update', 'params' => array());
        }
        if ($path === 'comment/delete') {
            return array('route' => 'comment_delete', 'params' => array());
        }
        if ($path === 'verify/send') {
            return array('route' => 'verify_send', 'params' => array());
        }
        // 注意：不提供独立的验证码核验（预检）路由——验证码对错不向前端暴露，
        // 仅在表单提交时由服务端一次性裁决，防止有效性探测

        // 前台语言切换 /lang/{code}（GET，仅写 cookie 偏好，不改服务端状态）
        if (preg_match('#^lang/([a-z]{2}_[A-Z]{2})$#', $path, $m)) {
            return array('route' => 'lang_switch', 'params' => array('code' => $m[1]));
        }

        // 未知路径默认 404；先经 route_parse 过滤器，插件可认领自定义路由
        // （如 OAuth 回调、Webhook），回调签名：($route, $path)
        return apply_filters('route_parse', array('route' => '404', 'params' => array()), $path);
    }

    /**
     * 取请求路径（兼容 PATH_INFO 与 ?r= 回退模式）
     *
     * @return string
     */
    private static function requestPath()
    {
        // 回退模式：index.php?r=post/1.html
        if (isset($_GET['r']) && is_string($_GET['r'])) {
            return $_GET['r'];
        }
        // 重写模式：PATH_INFO 或 REDIRECT_URL
        if (!empty($_SERVER['PATH_INFO'])) {
            return $_SERVER['PATH_INFO'];
        }
        if (!empty($_SERVER['REDIRECT_URL'])) {
            return $_SERVER['REDIRECT_URL'];
        }
        $uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/';
        $path = parse_url($uri, PHP_URL_PATH);
        $base = self::base();
        if ($base !== '' && strpos($path, $base) === 0) {
            $path = substr($path, strlen($base));
        }
        // 去掉入口文件名
        if (strpos($path, '/index.php') === 0) {
            $path = substr($path, strlen('/index.php'));
        }
        return $path === false ? '/' : $path;
    }

    /**
     * 站内 URL 统一生成入口
     *
     * @param string $name   路由名 home/post/category/author/page/search/login/register/forgot/logout/lang_switch
     * @param array  $params 参数
     * @return string
     */
    public static function url($name, array $params = array())
    {
        $path = self::buildPath($name, $params);
        $base = self::base();
        if (self::rewriteEnabled()) {
            $url = $base . $path;
            if ($name === 'search' && isset($params['q'])) {
                $url .= '?q=' . urlencode($params['q']);
            }
            if ($name === 'lang_switch' && isset($params['redirect'])) {
                $url .= '?redirect=' . urlencode($params['redirect']);
            }
            return $url;
        }
        // 回退模式：index.php?r=...
        $r = ltrim($path, '/');
        if ($r === '') {
            return $base . '/';
        }
        $extra = '';
        if ($name === 'search' && isset($params['q'])) {
            $extra = '&q=' . urlencode($params['q']);
        }
        if ($name === 'lang_switch' && isset($params['redirect'])) {
            $extra .= '&redirect=' . urlencode($params['redirect']);
        }
        return $base . '/index.php?r=' . rawurlencode($r) . $extra;
    }

    /**
     * 按路由名拼伪静态路径
     */
    private static function buildPath($name, array $params)
    {
        switch ($name) {
            case 'home':
                $page = isset($params['page']) ? (int) $params['page'] : 1;
                return $page > 1 ? '/page/' . $page : '/';
            case 'post':
                // slug 可能为 NULL（无别名），强转字符串后统一判空；无别名回退数字 id
                $slug = isset($params['slug']) ? (string) $params['slug'] : '';
                $key = $slug !== ''
                    ? $slug : (isset($params['id']) ? (int) $params['id'] : 0);
                return '/post/' . $key . '.html';
            case 'category':
                $page = isset($params['page']) ? (int) $params['page'] : 1;
                $suffix = $page > 1 ? '/page/' . $page : '';
                return '/category/' . $params['slug'] . $suffix;
            case 'author':
                $page = isset($params['page']) ? (int) $params['page'] : 1;
                $suffix = $page > 1 ? '/page/' . $page : '';
                return '/author/' . (int) $params['id'] . $suffix;
            case 'page':
                // 独立页面 slug 可能为 NULL（未填别名），与文章同策略回退数字 id；
                // /page/{id}.html 带 .html 后缀，与首页分页 /page/{n} 不冲突
                $slug = isset($params['slug']) ? (string) $params['slug'] : '';
                $key = $slug !== ''
                    ? $slug : (isset($params['id']) ? (int) $params['id'] : 0);
                return '/page/' . $key . '.html';
            case 'search':
                return '/search';
            case 'login':
                return '/login';
            case 'register':
                return '/register';
            case 'forgot':
                return '/forgot';
            case 'logout':
                return '/logout';
            case 'comment_save':
                return '/comment/save';
            case 'comment_update':
                return '/comment/update';
            case 'comment_delete':
                return '/comment/delete';
            case 'verify_send':
                return '/verify/send';
            case 'lang_switch':
                return '/lang/' . $params['code'];
            case 'admin':
                $m = isset($params['m']) ? $params['m'] : '';
                return site_base_admin($m);
        }
        return '/';
    }
}

/**
 * 后台 URL（user/index.php?m=...）
 *
 * @param string $m 模块/动作
 * @return string
 */
function site_base_admin($m = '')
{
    $url = Router::base() . '/user/index.php';
    if ($m !== '') {
        $url .= '?m=' . $m;
    }
    return $url;
}
