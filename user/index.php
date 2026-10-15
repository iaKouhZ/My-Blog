<?php
/**
 * 后台单一入口：/user/login、/user/admin、/user/center 均由此分发
 */

require dirname(__DIR__) . '/core/bootstrap.php';
require APP_ROOT . '/core/Admin.php';
require APP_ROOT . '/core/AdminPost.php';
require APP_ROOT . '/core/AdminComment.php';
require APP_ROOT . '/core/AdminCategory.php';
require APP_ROOT . '/core/AdminUser.php';
require APP_ROOT . '/core/AdminSetting.php';
require APP_ROOT . '/core/AdminTheme.php';
require APP_ROOT . '/core/ZipSafe.php';
require APP_ROOT . '/core/AdminPlugin.php';
require APP_ROOT . '/core/AdminLog.php';
require APP_ROOT . '/core/AdminProfile.php';
require APP_ROOT . '/core/AdminUpload.php';

Admin::handle();
