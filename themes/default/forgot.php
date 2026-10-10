<?php
/**
 * 默认主题：找回密码页
 */
defined('APP_BOOT') or exit;
Theme::part('header');
?>
<div id="content">
    <div class="auth-site-name"><a href="<?php echo e(Router::url('home')); ?>"><?php echo e(site_name()); ?></a></div>
    <div class="auth-card">
        <h1><?php echo e(theme_t('theme.auth.forgot_title')); ?></h1>
        <?php if ($info !== ''): ?>
        <div class="form-ok"><?php echo e($info); ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
        <div class="form-error"><?php echo e($error); ?></div>
        <?php endif; ?>
        <?php if ($emailEnabled || $smsEnabled): ?>
        <form class="auth-form" method="post" action="<?php echo e(Router::url('forgot')); ?>"
              data-pwd-check data-account="#forgotAccount">
            <?php echo Csrf::field(); ?>
            <p>
                <label><?php echo e(theme_t('theme.auth.account')); ?></label>
                <input type="text" name="account" id="forgotAccount"
                       value="<?php echo e($account); ?>" required>
            </p>
            <div class="form-hint"><?php echo e(theme_t('theme.auth.forgot_hint')); ?></div>
            <p>
                <label><?php echo e(theme_t('theme.auth.code')); ?></label>
                <span class="verify-row">
                    <input type="text" name="code" maxlength="6" required>
                    <button type="button" class="btn-small" id="sendResetCode"
                        data-scene="reset" data-channel="<?php echo $smsEnabled ? 'sms' : 'email'; ?>" data-target="#forgotAccount"><?php echo e(theme_t('theme.auth.send_code')); ?></button>
                </span>
            </p>
            <p>
                <label><?php echo e(theme_t('theme.auth.new_password_label')); ?></label>
                <input type="password" name="new_password" required minlength="8" maxlength="64">
            </p>
            <p>
                <label><?php echo e(theme_t('theme.auth.confirm_new_password')); ?></label>
                <input type="password" name="new_password2" required minlength="8" maxlength="64">
                <?php // 二次确认实时反馈：一致/不一致由 password_check.js 填充 ?>
                <span class="pwd-match" aria-live="polite"></span>
            </p>
            <p class="form-actions"><button class="submit" type="submit"><?php echo e(theme_t('theme.auth.reset_submit')); ?></button></p>
        </form>
        <?php else: ?>
        <p class="form-hint"><?php echo e(theme_t('theme.auth.no_provider')); ?></p>
        <?php endif; ?>
        <?php do_action('auth_form_footer', 'forgot'); /* 插件注入点（找回表单下方），与登录/注册页同钩子不同页面参数 */ ?>
        <div class="auth-links"><a href="<?php echo e(Router::url('login')); ?>"><?php echo e(theme_t('theme.auth.back_login')); ?></a></div>
    </div>
    <?php // JS 文案注入：内核 verify.js 读 CB_VERIFY.msg、password_check.js 读 CB_PWD_LANG（缺省均保持中文） ?>
    <script>
    window.CB_VERIFY = {
        url: <?php echo json_out_script(Router::url('verify_send')); ?>,
        csrf: <?php echo json_out_script(Csrf::token()); ?>,
        msg: <?php echo json_out_script(array(
            'fill_target'   => theme_t('theme.js.verify_fill_target'),
            'network_error' => theme_t('theme.js.verify_network_error'),
            'retry_in'      => theme_t('theme.js.verify_retry_in'),
            'send'          => theme_t('theme.js.verify_send'),
        )); ?>
    };
    window.CB_PWD_LANG = <?php echo json_out_script(array(
        'need'          => theme_t('theme.js.pwd_need'),
        'len'           => theme_t('theme.js.pwd_len'),
        'classes'       => theme_t('theme.js.pwd_classes'),
        'contain_name'  => theme_t('theme.js.pwd_contain_name'),
        'match_ok'      => theme_t('theme.js.pwd_match_ok'),
        'mismatch_live' => theme_t('theme.js.pwd_mismatch_live'),
        'repeat'        => theme_t('theme.js.pwd_repeat'),
        'mismatch'      => theme_t('theme.js.pwd_mismatch'),
    )); ?>;
    </script>
    <script src="<?php echo e(assets_url('front/verify.js')); ?>"></script>
    <script src="<?php echo e(assets_url('front/password_check.js')); ?>"></script>
<?php Theme::part('footer'); ?>
