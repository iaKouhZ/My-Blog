<?php
/**
 * 默认主题：登录页
 */
defined('APP_BOOT') or exit;
Theme::part('header');
?>
<div id="content">
    <div class="auth-site-name"><a href="<?php echo e(Router::url('home')); ?>"><?php echo e(site_name()); ?></a></div>
    <div class="auth-card">
        <h1><?php echo e(theme_t('theme.auth.login_title')); ?></h1>
        <?php foreach (flash_pull() as $fm): ?>
        <div class="<?php echo $fm['type'] === 'success' ? 'form-ok' : 'form-error'; ?>"><?php echo e($fm['text']); ?></div>
        <?php endforeach; ?>
        <?php if ($error !== ''): ?>
        <div class="form-error"><?php echo e($error); ?></div>
        <?php endif; ?>
        <form class="auth-form" method="post" action="<?php echo e(Router::url('login')); ?>">
            <?php echo Csrf::field(); ?>
            <p>
                <label><?php echo e(theme_t('theme.auth.account')); ?></label>
                <input type="text" name="account" value="<?php echo e($account); ?>" required>
            </p>
            <p>
                <label><?php echo e(theme_t('theme.auth.password')); ?></label>
                <input type="password" name="password" required>
            </p>
            <p class="form-actions"><button class="submit" type="submit"><?php echo e(theme_t('theme.auth.login_submit')); ?></button></p>
        </form>
        <?php do_action('auth_form_footer', 'login'); /* 第三方登录入口等插件注入点（登录按钮下方） */ ?>
        <div class="auth-links">
            <a href="<?php echo e(Router::url('forgot')); ?>"><?php echo e(theme_t('theme.auth.forgot_link')); ?></a>
            <?php if (Option::get('register_disabled', '0') !== '1'): ?>
            · <a href="<?php echo e(Router::url('register')); ?>"><?php echo e(theme_t('theme.auth.register_link')); ?></a>
            <?php endif; ?>
        </div>
    </div>
<?php Theme::part('footer'); ?>
