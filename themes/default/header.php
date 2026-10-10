<?php
/**
 * 默认主题公共页头：hendrix 式骨架（☰ 按钮 + 遮罩 + site-wrapper + 侧栏 + #main 开启）
 */
defined('APP_BOOT') or exit;
?>
<!DOCTYPE html>
<html lang="<?php echo e(Theme::locale()); ?>">
<head>
<?php theme_head(); ?>
</head>
<?php // data-confirm-default：theme.js 危险操作确认兜底文案（文章页受限 CSP 禁内联脚本，经 data 属性传递） ?>
<body data-confirm-default="<?php echo e(theme_t('theme.common.confirm_default')); ?>">
<button type="button" class="sidebar-toggle" id="sidebar-toggle" aria-label="<?php echo e(theme_t('theme.common.toggle_menu')); ?>" aria-expanded="false">
    <span>☰</span>
</button>
<div class="sidebar-overlay" id="sidebar-overlay"></div>

<div class="site-wrapper">
<?php Theme::part('sidebar'); ?>
<div id="main">
