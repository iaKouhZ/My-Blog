<?php
/**
 * 默认主题：404 页面
 */
defined('APP_BOOT') or exit;
Theme::part('header');
?>
<div id="content">
    <div class="error-content">
        <div class="error-code">404</div>
        <p class="error-message"><?php echo e(theme_t('theme.notfound.message')); ?></p>
        <p><a class="post-card-readmore" href="<?php echo e(Router::url('home')); ?>"><?php echo e(theme_t('theme.notfound.back_home')); ?></a></p>
    </div>
<?php Theme::part('footer'); ?>
