<?php
/**
 * 后台模板管理：列表/启用/删除/zip 上传（仅管理员，能力点 manage_themes）
 */
defined('APP_BOOT') or exit;

class AdminTheme
{
    /** 主题列表 */
    public static function listAction()
    {
        Auth::require_cap('manage_themes');
        Admin::render(admin_t('admin.menu.theme'), 'theme_list', array(
            'themes' => Theme::discover(),
            'active' => Theme::current(),
            'uploadLimit' => ZipSafe::uploadLimit(),
        ));
    }

    /** 启用主题 */
    public static function activateAction()
    {
        Auth::require_cap('manage_themes');
        $dir = input_text('dir', '', 64, 'post');
        $themes = Theme::discover();
        if (!preg_match('/^[a-z0-9_-]{1,64}$/', $dir) || !isset($themes[$dir])) {
            flash_set('error', admin_t('admin.theme.not_found'));
        } else {
            Option::set('active_theme', $dir);
            blog_log('template', 'theme.activate', 'success', array('theme' => $dir));
            flash_set('success', admin_t('admin.theme.activated', array($themes[$dir]['name'])));
        }
        redirect(site_base_admin('theme/list'));
    }

    /** 删除主题（默认主题与当前启用主题不可删） */
    public static function deleteAction()
    {
        Auth::require_cap('manage_themes');
        $dir = input_text('dir', '', 64, 'post');
        if (!preg_match('/^[a-z0-9_-]{1,64}$/', $dir) || $dir === 'default') {
            flash_set('error', admin_t('admin.theme.not_deletable'));
            redirect(site_base_admin('theme/list'));
        }
        if ($dir === Theme::current()) {
            flash_set('error', admin_t('admin.theme.cannot_delete_active'));
            redirect(site_base_admin('theme/list'));
        }
        $path = Theme::dirOf($dir);
        if (is_dir($path)) {
            ZipSafe::removeDir($path);
            blog_log('template', 'theme.delete', 'success', array('theme' => $dir));
            flash_set('success', admin_t('admin.theme.deleted'));
        } else {
            flash_set('error', admin_t('admin.theme.dir_missing'));
        }
        redirect(site_base_admin('theme/list'));
    }

    /** 主题设置页（表单由主题 settings.php 清单驱动，存 options.theme_settings_{dir}） */
    public static function settingAction()
    {
        Auth::require_cap('manage_themes');
        $dir = input_text('dir', '', 64, 'get');
        $themes = Theme::discover();
        if (!preg_match('/^[a-z0-9_-]{1,64}$/', $dir) || !isset($themes[$dir])) {
            flash_set('error', admin_t('admin.theme.not_found'));
            redirect(site_base_admin('theme/list'));
        }
        Admin::render(admin_t('admin.theme.setting_page'), 'theme_setting', array(
            'dir'      => $dir,
            'name'     => $themes[$dir]['name'],
            'schema'   => Theme::settingsSchema($dir),
            'settings' => Theme::settingsOf($dir),
        ));
    }

    /** 保存主题设置：仅接收清单内字段，按声明类型走对应输入校验器 */
    public static function settingSaveAction()
    {
        Auth::require_cap('manage_themes');
        $dir = input_text('dir', '', 64, 'post');
        $themes = Theme::discover();
        if (!preg_match('/^[a-z0-9_-]{1,64}$/', $dir) || !isset($themes[$dir])) {
            flash_set('error', admin_t('admin.theme.not_found'));
            redirect(site_base_admin('theme/list'));
        }
        $schema = Theme::settingsSchema($dir);
        $settings = array();
        foreach ($schema as $key => $field) {
            if ($field['type'] === 'textarea') {
                $settings[$key] = input_longtext($key, $field['default']);
            } elseif ($field['type'] === 'checkbox') {
                $settings[$key] = input_int($key, 0, 'post') === 1 ? '1' : '0';
            } elseif ($field['type'] === 'select') {
                $allowed = array_keys($field['options']);
                $settings[$key] = input_enum($key, $allowed, $field['default'], 'post');
            } else {
                $settings[$key] = input_text($key, $field['default'], $field['maxlength'], 'post');
            }
        }
        Option::set('theme_settings_' . $dir, json_encode($settings, JSON_UNESCAPED_UNICODE));
        blog_log('template', 'theme.setting', 'success', array('theme' => $dir));
        flash_set('success', admin_t('admin.theme.setting_saved'));
        redirect(site_base_admin('theme/setting&dir=' . rawurlencode($dir)));
    }

    /**
     * 上传主题 zip（需服务器启用 ZipArchive 扩展）
     * 目录已存在且非启用中主题时执行覆盖更新：先解压到临时目录做身份校验，
     * 再备份旧目录、替换就位，失败自动回滚
     */
    public static function uploadAction()
    {
        Auth::require_cap('manage_themes');
        if (empty($_FILES['theme_zip'])) {
            flash_set('error', admin_t('admin.theme.zip_required'));
            redirect(site_base_admin('theme/list'));
        }
        $file = $_FILES['theme_zip'];
        $err = ZipSafe::uploadError($file, 10 * 1024 * 1024);
        if ($err !== null) {
            flash_set('error', admin_t('admin.zipsafe.' . $err['code'], $err['args']));
            redirect(site_base_admin('theme/list'));
        }

        $name = strtolower($file['name']);
        $target = preg_replace('/[^a-z0-9_-]/', '', substr($name, 0, -4));
        if ($target === '' || $target === 'default') {
            flash_set('error', admin_t('admin.theme.dir_invalid'));
            redirect(site_base_admin('theme/list'));
        }
        $dest = Theme::dirOf($target);
        $isUpdate = is_dir($dest);
        // 启用中的主题禁止覆盖更新：替换正在执行的 PHP 模板风险不可控，须先切换到其他主题
        if ($isUpdate && $target === Theme::current()) {
            blog_log('template', 'theme.update', 'fail', array('theme' => $target, 'reason' => 'active_theme'));
            flash_set('error', admin_t('admin.theme.no_overwrite_active'));
            redirect(site_base_admin('theme/list'));
        }

        $zip = null;
        $err = ZipSafe::openChecked($file['tmp_name'], '#(^|/)style\.css$#', $zip);
        if ($err !== null) {
            blog_log('template', $isUpdate ? 'theme.update' : 'theme.upload', 'fail', array(
                'theme' => $target, 'reason' => $err['code'],
            ));
            flash_set('error', $err['code'] === 'zip_missing_required'
                ? admin_t('admin.theme.zip_no_style')
                : admin_t('admin.zipsafe.' . $err['code'], $err['args']));
            redirect(site_base_admin('theme/list'));
        }

        $tmp = '';
        $err = ZipSafe::extractToTemp($zip, APP_ROOT . '/themes', $tmp);
        if ($err !== null) {
            flash_set('error', admin_t('admin.zipsafe.' . $err['code'], $err['args']));
            redirect(site_base_admin('theme/list'));
        }
        // 扁平化后 style.css 必须落在根目录，否则主题无法被发现
        if (!is_file($tmp . '/style.css')) {
            ZipSafe::removeDir($tmp);
            flash_set('error', admin_t('admin.theme.zip_no_style'));
            redirect(site_base_admin('theme/list'));
        }
        // 覆盖更新需校验主题身份：新旧包均显式声明 Theme Name 时必须一致，防止同名异主题被误覆盖
        $newMeta = Theme::headerOf($tmp . '/style.css');
        if ($isUpdate) {
            $oldMeta = Theme::headerOf($dest . '/style.css');
            if ($newMeta['name'] !== '' && $oldMeta['name'] !== '' && $newMeta['name'] !== $oldMeta['name']) {
                ZipSafe::removeDir($tmp);
                blog_log('template', 'theme.update', 'fail', array('theme' => $target, 'reason' => 'name_mismatch'));
                flash_set('error', admin_t('admin.theme.name_mismatch'));
                redirect(site_base_admin('theme/list'));
            }
        }

        $err = ZipSafe::swapIn($tmp, $dest);
        if ($err !== null) {
            blog_log('template', $isUpdate ? 'theme.update' : 'theme.upload', 'fail', array(
                'theme' => $target, 'reason' => $err['code'],
            ));
            flash_set('error', admin_t('admin.zipsafe.' . $err['code'], $err['args']));
            redirect(site_base_admin('theme/list'));
        }
        blog_log('template', $isUpdate ? 'theme.update' : 'theme.upload', 'success', array(
            'theme' => $target, 'version' => $newMeta['version'],
        ));
        flash_set('success', $isUpdate ? admin_t('admin.theme.updated', array($target)) : admin_t('admin.theme.uploaded', array($target)));
        redirect(site_base_admin('theme/list'));
    }
}
