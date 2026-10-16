<?php
/** 安装表结构兼容入口，定义由 core/Schema.php 统一维护。 */
defined('APP_BOOT') or exit;
require_once dirname(__DIR__) . '/core/DB.php';
require_once dirname(__DIR__) . '/core/Schema.php';

/** 返回新装表结构，保留安装程序既有调用方式。 */
function install_schema($prefix)
{
    return Schema::definitions($prefix);
}

/** 密码历史表定义的兼容入口。 */
function install_password_history_schema($prefix)
{
    return Schema::passwordHistory($prefix);
}
