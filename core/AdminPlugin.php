<?php
/**
 * 后台插件管理：列表/上传/启用/禁用/删除/插件设置页分发（仅管理员，能力点 manage_plugins）
 */
defined('APP_BOOT') or exit;

class AdminPlugin
{
    /** 插件列表 */
    public static function listAction()
    {
        Auth::require_cap('manage_plugins');
        Admin::render(admin_t('admin.menu.plugin'), 'plugin_list', array(
            'plugins' => Plugin::discover(),
            'actives' => Plugin::activeList(),
            'orphans' => self::orphanSlugs(),
            'uploadLimit' => ZipSafe::uploadLimit(),
        ));
    }

    /**
     * 上传插件 zip（需服务器启用 ZipArchive 扩展）
     * slug 由包内主文件 {slug}.php（头部须声明 Plugin Name）推导；同名插件执行覆盖更新：
     * 先解压到临时目录做身份校验，再备份旧目录、替换就位，失败自动回滚
     */
    public static function uploadAction()
    {
        Auth::require_cap('manage_plugins');
        if (empty($_FILES['plugin_zip'])) {
            flash_set('error', admin_t('admin.plugin.zip_required'));
            redirect(site_base_admin('plugin/list'));
        }
        $file = $_FILES['plugin_zip'];
        $err = ZipSafe::uploadError($file, 10 * 1024 * 1024);
        if ($err !== null) {
            flash_set('error', admin_t('admin.zipsafe.' . $err['code'], $err['args']));
            redirect(site_base_admin('plugin/list'));
        }

        $zip = null;
        // 必需条目仅粗筛"包内至少一个 slug 风格命名的 php"，主文件合法性在解压后判定
        $err = ZipSafe::openChecked($file['tmp_name'], '#(^|/)[a-z0-9-]{1,64}\.php$#', $zip);
        if ($err !== null) {
            blog_log('plugin', 'plugin.upload', 'fail', array('reason' => $err['code']));
            flash_set('error', $err['code'] === 'zip_missing_required'
                ? admin_t('admin.plugin.zip_no_main')
                : admin_t('admin.zipsafe.' . $err['code'], $err['args']));
            redirect(site_base_admin('plugin/list'));
        }
        $tmp = '';
        $err = ZipSafe::extractToTemp($zip, APP_ROOT . '/plugins', $tmp);
        if ($err !== null) {
            flash_set('error', admin_t('admin.zipsafe.' . $err['code'], $err['args']));
            redirect(site_base_admin('plugin/list'));
        }
        // slug 由包内主文件推导：顶层 {slug}.php 且头部含 Plugin Name（与 Plugin::discover 同一判定）
        $slug = '';
        $meta = null;
        foreach (scandir($tmp) as $item) {
            if (preg_match('/^([a-z0-9-]{1,64})\.php$/', $item, $m)) {
                $parsed = Plugin::parseMeta($tmp . '/' . $item);
                if ($parsed !== null) {
                    $slug = $m[1];
                    $meta = $parsed;
                    break;
                }
            }
        }
        if ($slug === '') {
            ZipSafe::removeDir($tmp);
            blog_log('plugin', 'plugin.upload', 'fail', array('reason' => 'main_file_missing'));
            flash_set('error', admin_t('admin.plugin.main_file_invalid'));
            redirect(site_base_admin('plugin/list'));
        }

        $dest = APP_ROOT . '/plugins/' . $slug;
        $isUpdate = is_dir($dest);
        // 覆盖更新需校验插件身份：Plugin Name 不一致视为不同插件，防止同名异插件被误覆盖
        if ($isUpdate) {
            $oldMeta = Plugin::parseMeta($dest . '/' . $slug . '.php');
            if ($oldMeta !== null && $meta['name'] !== $oldMeta['name']) {
                ZipSafe::removeDir($tmp);
                blog_log('plugin', 'plugin.update', 'fail', array('plugin' => $slug, 'reason' => 'name_mismatch'));
                flash_set('error', admin_t('admin.plugin.name_mismatch'));
                redirect(site_base_admin('plugin/list'));
            }
        }
        $err = ZipSafe::swapIn($tmp, $dest);
        if ($err !== null) {
            blog_log('plugin', $isUpdate ? 'plugin.update' : 'plugin.upload', 'fail', array(
                'plugin' => $slug, 'reason' => $err['code'],
            ));
            flash_set('error', admin_t('admin.zipsafe.' . $err['code'], $err['args']));
            redirect(site_base_admin('plugin/list'));
        }
        blog_log('plugin', $isUpdate ? 'plugin.update' : 'plugin.upload', 'success', array(
            'plugin' => $slug, 'version' => $meta['version'],
        ));
        flash_set('success', admin_t($isUpdate ? 'admin.plugin.updated' : 'admin.plugin.uploaded', array($slug)));
        redirect(site_base_admin('plugin/list'));
    }

    /**
     * 检测不规范卸载（直接删文件夹）的残留 slug：目录不存在但仍有数据
     *
     * @return array slug 列表
     */
    private static function orphanSlugs()
    {
        $discovered = Plugin::discover();
        $candidates = array();
        // 残留来源一：plugin_data 表行
        $rows = DB::query('plugin_data')->select(array('plugin'));
        foreach ($rows as $r) {
            $candidates[$r['plugin']] = 1;
        }
        // 残留来源二：plugin_{slug}_* 选项（slug 仅小写字母数字连字符、不含下划线，
        // 故命名空间前缀后第一个下划线之前即完整 slug）；跳过内核自用键
        $rows = DB::query('options')
            ->where('option_key', 'LIKE', 'plugin\_%')
            ->select(array('option_key'));
        foreach ($rows as $r) {
            if ($r['option_key'] === 'plugin_data_purge_at') {
                continue;
            }
            $rest = substr($r['option_key'], 7);
            $pos = strpos($rest, '_');
            if ($pos !== false) {
                $candidates[substr($rest, 0, $pos)] = 1;
            }
        }
        $orphans = array();
        foreach (array_keys($candidates) as $slug) {
            if (!isset($discovered[$slug]) && preg_match('/^[a-z0-9-]{1,64}$/', $slug)) {
                $orphans[] = $slug;
            }
        }
        sort($orphans);
        return $orphans;
    }

    /** 清理不规范卸载的残留数据（目录必须确不存在，内核复用卸载回收逻辑） */
    public static function cleanupOrphanAction()
    {
        Auth::require_cap('manage_plugins');
        $slug = self::slugInput('post');
        if ($slug === '' || !Plugin::purgeOrphanData($slug)) {
            flash_set('error', admin_t('admin.plugin.orphan_none'));
        } else {
            flash_set('success', admin_t('admin.plugin.orphan_cleaned', array($slug)));
        }
        redirect(site_base_admin('plugin/list'));
    }

    /** 启用插件 */
    public static function activateAction()
    {
        Auth::require_cap('manage_plugins');
        $slug = self::slugInput('post');
        if ($slug === '' || !Plugin::activate($slug)) {
            flash_set('error', admin_t('admin.plugin.activate_failed'));
        } else {
            flash_set('success', admin_t('admin.plugin.activated'));
        }
        redirect(site_base_admin('plugin/list'));
    }

    /** 禁用插件 */
    public static function deactivateAction()
    {
        Auth::require_cap('manage_plugins');
        $slug = self::slugInput('post');
        if ($slug === '' || !Plugin::deactivate($slug)) {
            flash_set('error', admin_t('admin.plugin.deactivate_failed'));
        } else {
            flash_set('success', admin_t('admin.plugin.deactivated'));
        }
        redirect(site_base_admin('plugin/list'));
    }

    /** 删除插件（前端二次确认） */
    public static function uninstallAction()
    {
        Auth::require_cap('manage_plugins');
        $slug = self::slugInput('post');
        if ($slug === '' || !Plugin::uninstall($slug)) {
            flash_set('error', admin_t('admin.plugin.uninstall_failed'));
        } else {
            flash_set('success', admin_t('admin.plugin.uninstalled'));
        }
        redirect(site_base_admin('plugin/list'));
    }

    /** 插件设置页：渲染插件经 register_plugin_page 注册的回调 */
    public static function pageAction()
    {
        Auth::require_cap('manage_plugins');
        $slug = self::slugInput('get');
        // 确保插件的 admin_menu 钩子已执行（设置页注册发生在该钩子中）
        if (empty($GLOBALS['cb_admin_menu_done'])) {
            do_action('admin_menu');
            $GLOBALS['cb_admin_menu_done'] = 1;
        }
        $pages = get_plugin_pages();
        if ($slug === '' || !isset($pages[$slug]) || !Plugin::isActive($slug)) {
            Admin::forbidden();
        }
        ob_start();
        // 设置页回调执行期间置于该插件上下文：其内写入仅允许自身命名空间
        $prev = Plugin::setCurrentSlug($slug);
        call_user_func($pages[$slug]['callback']);
        Plugin::setCurrentSlug($prev);
        $contentHtml = ob_get_clean();
        Admin::render($pages[$slug]['title'], 'plugin_page', array('contentHtml' => $contentHtml));
    }

    /** 校验并读取插件 slug（防路径穿越） */
    private static function slugInput($source)
    {
        $slug = input_text('slug', '', 64, $source);
        return preg_match('/^[a-z0-9-]{1,64}$/', $slug) ? $slug : '';
    }
}
