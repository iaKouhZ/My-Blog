<?php
/**
 * 后台设置：站点设置（manage_options）与安全设置（manage_security）
 * 安全项变更全程审计；日志留存下限 180 天；密码过期/历史功能默认关闭
 */
defined('APP_BOOT') or exit;

class AdminSetting
{
    /** 站点设置页 */
    public static function siteAction()
    {
        Auth::require_cap('manage_options');
        Admin::render(admin_t('admin.menu.setting_site'), 'setting_site', array('langList' => Lang::available()));
    }

    /** 保存站点设置 */
    public static function siteSaveAction()
    {
        Auth::require_cap('manage_options');
        $changed = array();
        $items = array(
            'site_name'        => input_text('site_name', '个人博客', 64, 'post'),
            'site_motto'       => input_text('site_motto', '', 128, 'post'),
            'site_description' => input_text('site_description', '', 255, 'post'),
            'site_keywords'    => input_text('site_keywords', '', 255, 'post'),
        );
        foreach ($items as $key => $value) {
            if ((string) Option::get($key, '') !== $value) {
                $changed[] = $key;
            }
            Option::set($key, $value);
        }

        $perPage = input_int('posts_per_page', 10, 'post');
        $perPage = max(1, min(50, $perPage));
        if ((int) Option::get('posts_per_page', 10) !== $perPage) {
            $changed[] = 'posts_per_page';
        }
        Option::set('posts_per_page', (string) $perPage);

        // 开关项：checkbox 未勾选即视为关闭
        $switches = array('post_audit', 'comment_audit', 'rewrite_enabled', 'register_disabled');
        foreach ($switches as $key) {
            $value = input_int($key, 0, 'post') === 1 ? '1' : '0';
            if (Option::get($key, '0') !== $value) {
                $changed[] = $key;
            }
            Option::set($key, $value);
        }

        // 后台语言：白名单限定已发现的可用语言，非法/已删除包自动回退中文基线
        $locale = input_enum('admin_locale', array_keys(Lang::available()), 'zh_CN', 'post');
        if ((string) Option::get('admin_locale', 'zh_CN') !== $locale) {
            $changed[] = 'admin_locale';
        }
        Option::set('admin_locale', $locale);

        // 时区：仅接受 PHP 合法时区标识，默认 UTC+8（Asia/Shanghai）
        $timezone = input_text('timezone', 'Asia/Shanghai', 64, 'post');
        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            $timezone = 'Asia/Shanghai';
        }
        if ((string) Option::get('timezone', 'Asia/Shanghai') !== $timezone) {
            $changed[] = 'timezone';
        }
        Option::set('timezone', $timezone);

        blog_log('setting', 'setting.site', 'success', array('changed' => $changed));
        flash_set('success', admin_t('admin.setting.site_saved'));
        redirect(site_base_admin('setting/site'));
    }

    /** 安全设置页（等保二级相关控制点） */
    public static function securityAction()
    {
        Auth::require_cap('manage_security');
        Admin::render(admin_t('admin.menu.setting_security'), 'setting_security', array());
    }

    /** 保存安全设置 */
    public static function securitySaveAction()
    {
        Auth::require_cap('manage_security');
        $changed = array();

        if (max(0, input_int('pwd_history_count', 0, 'post')) > 0) {
            try {
                self::ensurePasswordHistoryTable();
            } catch (Throwable $ex) {
                blog_log('security', 'setting.security', 'fail', array('reason' => 'password_history_schema_failed'));
                throw $ex;
            }
        }
        $intRules = array(
            // 键 => array(缺省, 下限, 上限)
            'login_max_fail'        => array(5, 1, 20),
            'login_lock_minutes'    => array(10, 1, 1440),
            'session_timeout_minutes' => array(30, 1, 1440),
            'pwd_expire_days'       => array(90, 30, 365),
            'pwd_history_count'     => array(0, 0, 24),
            // 日志留存下限 180 天（《网络安全法》第 21 条不少于六个月）
            'log_retention_days'    => array(180, 180, 3650),
        );
        foreach ($intRules as $key => $rule) {
            $value = max($rule[1], min($rule[2], input_int($key, $rule[0], 'post')));
            if ((int) Option::get($key, $rule[0]) !== $value) {
                $changed[] = $key;
            }
            Option::set($key, (string) $value);
        }

        $switches = array('pwd_expire_enabled', 'ip_header_enabled', 'debug');
        foreach ($switches as $key) {
            $value = input_int($key, 0, 'post') === 1 ? '1' : '0';
            if (Option::get($key, '0') !== $value) {
                $changed[] = $key;
            }
            Option::set($key, $value);
        }

        // 自定义 IP 标头名：支持英文逗号分隔多个（在前优先级高），每段仅允许字母数字连字符
        $headerRaw = input_text('ip_header_name', 'X-Forwarded-For', 255, 'post');
        $headerParts = array();
        foreach (explode(',', $headerRaw) as $part) {
            $part = trim($part);
            if ($part !== '' && preg_match('/^[A-Za-z0-9-]+$/', $part)) {
                $headerParts[] = $part;
            }
        }
        // 去重保序；全部非法时回退默认值，保证该选项始终可用
        $headerParts = array_values(array_unique($headerParts));
        $headerName = empty($headerParts) ? 'X-Forwarded-For' : implode(',', $headerParts);
        if ((string) Option::get('ip_header_name', 'X-Forwarded-For') !== $headerName) {
            $changed[] = 'ip_header_name';
        }
        Option::set('ip_header_name', $headerName);

        // 可信外链图片主机：每行一条（也接受逗号/空格分隔），逐条规范化，非法条目静默丢弃
        $hostLines = input_text('img_ext_hosts_text', '', 4000, 'post');
        $rawHosts = preg_split('/[\r\n,;]+/', $hostLines);
        $hosts = ExternalMedia::sanitizeList(is_array($rawHosts) ? $rawHosts : array());
        $hostsJson = json_encode($hosts);
        if ((string) Option::get(ExternalMedia::OPTION_KEY, '[]') !== $hostsJson) {
            $changed[] = ExternalMedia::OPTION_KEY;
        }
        Option::set(ExternalMedia::OPTION_KEY, $hostsJson);

        blog_log('security', 'setting.security', 'success', array('changed' => $changed));
        flash_set('success', admin_t('admin.setting.security_saved'));
        redirect(site_base_admin('setting/security'));
    }

    /** 导航管理页（前台侧边栏自定义导航项） */
    public static function navAction()
    {
        Auth::require_cap('manage_options');
        Admin::render(admin_t('admin.menu.setting_nav'), 'setting_nav', array('items' => nav_items()));
    }

    /**
     * 保存导航项：前端按拖拽后的 DOM 顺序重编号提交 title_i/url_i/parent_i，
     * row_total 告知总行数；移除的行直接不提交。无 JS 时回退旧结构行数
     * （表单 name 由服务端按旧顺序渲染，del_i 勾选删除仍可用）。
     * 仅支持一层父子：父级必须是更早的顶层行，非法父级自动提升为顶层。
     * 新增行（前端模板行带 fresh 标记）必须填写链接；既有顶层项链接可留空
     * 作纯文本分组标签（有子项才保留）；子项链接必填。
     */
    public static function navSaveAction()
    {
        Auth::require_cap('manage_options');
        // row_total 缺省（-1）即无 JS 提交，回退旧结构行数
        $total = input_int('row_total', -1, 'post');
        if ($total < 0) {
            $total = count(self::flattenNav(nav_items()));
        }
        $rows = array(); // 提交行号 => ['title','url','parent']，保留行号供父级引用
        for ($i = 0; $i < $total; $i++) {
            if (input_int('del_' . $i, 0, 'post') === 1) {
                continue;
            }
            $item = self::sanitizeNavItem(
                input_text('title_' . $i, '', 32, 'post'),
                input_text('url_' . $i, '', 255, 'post'),
                true
            );
            if ($item === null) {
                continue;
            }
            // 新增行必须带链接：新行本次保存内不可能有子项，空链接必被丢弃，
            // 静默丢失会误导用户，故直接拒绝保存（前端 required 已拦一道，此处兜底）
            if (input_int('fresh_' . $i, 0, 'post') === 1 && $item['url'] === '') {
                blog_log('setting', 'setting.nav', 'fail', array('reason' => 'fresh_empty_url'));
                flash_set('error', admin_t('admin.setting.nav_fresh_empty_url'));
                redirect(site_base_admin('setting/nav'));
            }
            $rows[$i] = array(
                'title'  => $item['title'],
                'url'    => $item['url'],
                'parent' => input_int('parent_' . $i, -1, 'post'),
            );
        }
        // 总数上限 30（含子项）；被截断行的父级引用由 rebuildNav 自动降级处理
        if (count($rows) > 30) {
            $rows = array_slice($rows, 0, 30, true);
        }
        // 无 JS 兜底新增通道（恒为顶层）；JS 下新增行已并入上方重编号提交
        $newItem = self::sanitizeNavItem(
            input_text('new_title', '', 32, 'post'),
            input_text('new_url', '', 255, 'post')
        );
        if ($newItem !== null && count($rows) < 30) {
            $rows[] = array('title' => $newItem['title'], 'url' => $newItem['url'], 'parent' => -1);
        }
        // 重建嵌套结构：先按序收集顶层，再挂子项
        $items = self::rebuildNav($rows);
        Option::set('nav_items', json_encode($items, JSON_UNESCAPED_UNICODE));
        blog_log('setting', 'setting.nav', 'success', array('count' => count($items)));
        flash_set('success', admin_t('admin.setting.nav_saved'));
        redirect(site_base_admin('setting/nav'));
    }

    /**
     * 由扁平行（旧行号 => title/url/parent）重建一层嵌套结构。
     * 单遍按行序构建：合法子项先收集、结束后挂到父顶层下；顶层与
     * 非法父级（失效/越界/指向子项）的行按出现顺序入列，禁止多层嵌套。
     * 子项恒渲染为链接，空链接子项丢弃；空链接顶层项仅作分组标签，
     * 无子项时无展示意义，同样丢弃。
     *
     * @param array $rows 旧行号 => array('title','url','parent')
     * @return array 嵌套导航结构
     */
    public static function rebuildNav(array $rows)
    {
        $items = array();
        $map = array();      // 顶层行的旧行号 => $items 下标
        $children = array(); // 顶层旧行号 => 子项列表
        foreach ($rows as $oldIdx => $row) {
            $p = $row['parent'];
            $valid = $p >= 0 && $p < $oldIdx && isset($map[$p]);
            if ($valid) {
                if ($row['url'] !== '') {
                    $children[$p][] = array('title' => $row['title'], 'url' => $row['url']);
                }
            } else {
                $map[$oldIdx] = count($items);
                $items[] = array('title' => $row['title'], 'url' => $row['url'], 'children' => array());
            }
        }
        foreach ($children as $p => $list) {
            $items[$map[$p]]['children'] = $list;
        }
        // 空链接顶层项仅作分组标签，无子项时无展示意义，丢弃
        $result = array();
        foreach ($items as $item) {
            if ($item['url'] === '' && empty($item['children'])) {
                continue;
            }
            $result[] = $item;
        }
        return $result;
    }

    /**
     * 将嵌套导航扁平化（供后台编辑表单与保存共用，保证行号一致）：
     * 按序输出顶层项，每个顶层项的子项紧随其后；返回 [idx => ['title','url','parent']]，
     * parent=-1 表示顶层，否则为该行父项的扁平行号。
     *
     * @param array $items nav_items() 返回的嵌套结构
     * @return array
     */
    public static function flattenNav(array $items)
    {
        $flat = array();
        $idx = 0;
        foreach ($items as $row) {
            $parentIdx = $idx;
            $flat[$idx++] = array(
                'title'  => isset($row['title']) ? (string) $row['title'] : '',
                'url'    => isset($row['url']) ? (string) $row['url'] : '',
                'parent' => -1,
            );
            if (!empty($row['children']) && is_array($row['children'])) {
                foreach ($row['children'] as $child) {
                    $flat[$idx++] = array(
                        'title'  => isset($child['title']) ? (string) $child['title'] : '',
                        'url'    => isset($child['url']) ? (string) $child['url'] : '',
                        'parent' => $parentIdx,
                    );
                }
            }
        }
        return $flat;
    }

    /**
     * 单条导航项校验：标题非空且 URL 命中白名单；非法返回 null
     * URL 只准站内相对路径（/ 开头、无协议头）或 http(s) 绝对地址，
     * 拒绝 javascript:/data: 等可执行协议与协议相对地址；
     * $allowEmptyUrl 为 true 时放行空链接（顶层分组标签场景，由重建逻辑决定去留）
     */
    private static function sanitizeNavItem($title, $url, $allowEmptyUrl = false)
    {
        $title = trim((string) $title);
        $url = trim((string) $url);
        if ($title === '') {
            return null;
        }
        if ($url === '') {
            return $allowEmptyUrl ? array('title' => $title, 'url' => '') : null;
        }
        $isRelative = strpos($url, '/') === 0 && strpos($url, '//') !== 0;
        $isAbsolute = (bool) preg_match('#^https?://#i', $url);
        // 普通串：不含协议冒号，且不得以 / 或 \ 开头——//evil.com 是协议相对地址，
        // 浏览器会按当前协议跳站外，必须走 http(s) 显式白名单而非由此放行
        $isPlain = strpos($url, ':') === false && strpos($url, '/') !== 0 && strpos($url, '\\') !== 0;
        if (!$isRelative && !$isAbsolute && !$isPlain) {
            return null;
        }
        return array('title' => $title, 'url' => $url);
    }

    /** 按需创建 password_history 表 */
    private static function ensurePasswordHistoryTable()
    {
        Schema::ensurePasswordHistory();
    }
}
