<?php
/**
 * 默认主题：注册页（验证码按已启用插件动态出现）
 */
defined('APP_BOOT') or exit;
Theme::part('header');
?>
<div id="content">
    <div class="auth-site-name"><a href="<?php echo e(Router::url('home')); ?>"><?php echo e(site_name()); ?></a></div>
    <div class="auth-card">
        <h1><?php echo e(theme_t('theme.auth.register_title')); ?></h1>
        <?php if ($error !== ''): ?>
        <div class="form-error"><?php echo e($error); ?></div>
        <?php endif; ?>
        <form class="auth-form" method="post" action="<?php echo e(Router::url('register')); ?>" novalidate>
            <?php echo Csrf::field(); ?>
            <p>
                <label><?php echo e(theme_t('theme.auth.username_label')); ?></label>
                <input type="text" name="username" id="regUsername" value="<?php echo e($old['username']); ?>" required>
            </p>
            <p>
                <label><?php echo e(theme_t('theme.auth.nickname_label')); ?></label>
                <input type="text" name="nickname" value="<?php echo e($old['nickname']); ?>">
            </p>
            <p>
                <label><?php echo e(theme_t('theme.auth.email')); ?></label>
                <input type="email" name="email" id="regEmail" value="<?php echo e($old['email']); ?>">
            </p>
            <?php if ($emailEnabled): ?>
            <p>
                <label><?php echo e(theme_t('theme.auth.email_code')); ?></label>
                <span class="verify-row">
                    <input type="text" name="email_code" id="regEmailCode" maxlength="6">
                    <button type="button" class="btn-small" id="sendEmailCode"
                        data-scene="register" data-channel="email" data-target="#regEmail"><?php echo e(theme_t('theme.auth.send_code')); ?></button>
                </span>
            </p>
            <?php endif; ?>
            <p>
                <?php // 短信插件启用时手机号必填且需验证码，不再标注可选 ?>
                <label><?php echo e(theme_t('theme.auth.phone') . ($smsEnabled ? '' : theme_t('theme.auth.optional'))); ?></label>
                <input type="text" name="phone" id="regPhone" value="<?php echo e($old['phone']); ?>" <?php echo $smsEnabled ? 'required' : ''; ?>>
            </p>
            <?php if ($smsEnabled): ?>
            <p>
                <label><?php echo e(theme_t('theme.auth.sms_code')); ?></label>
                <span class="verify-row">
                    <input type="text" name="sms_code" id="regSmsCode" maxlength="6">
                    <button type="button" class="btn-small" id="sendSmsCode"
                        data-scene="register" data-channel="sms" data-target="#regPhone"><?php echo e(theme_t('theme.auth.send_code')); ?></button>
                </span>
            </p>
            <?php endif; ?>
            <p>
                <label><?php echo e(theme_t('theme.auth.password')); ?></label>
                <input type="password" name="password" id="regPassword" required>
            </p>
            <div class="pwd-rules" aria-live="polite">
                <span class="pwd-rule" id="rule-len"><?php echo e(theme_t('theme.auth.pwd_rule_len')); ?></span>
                <span class="pwd-rule" id="rule-upper"><?php echo e(theme_t('theme.auth.pwd_rule_upper')); ?></span>
                <span class="pwd-rule" id="rule-lower"><?php echo e(theme_t('theme.auth.pwd_rule_lower')); ?></span>
                <span class="pwd-rule" id="rule-digit"><?php echo e(theme_t('theme.auth.pwd_rule_digit')); ?></span>
                <span class="pwd-rule" id="rule-special"><?php echo e(theme_t('theme.auth.pwd_rule_special')); ?></span>
                <span class="pwd-rule" id="rule-classes"><?php echo e(theme_t('theme.auth.pwd_rule_classes')); ?></span>
                <span class="pwd-rule" id="rule-username"><?php echo e(theme_t('theme.auth.pwd_rule_username')); ?></span>
            </div>
            <p>
                <label><?php echo e(theme_t('theme.auth.confirm_password')); ?></label>
                <input type="password" name="password2" id="regPassword2" required>
                <?php // 二次确认实时反馈：一致/不一致由 register_check.js 填充 ?>
                <span class="pwd-match" id="pwdMatch" aria-live="polite"></span>
            </p>
            <p class="form-actions"><button class="submit" type="submit"><?php echo e(theme_t('theme.auth.register_submit')); ?></button></p>
        </form>
        <?php do_action('auth_form_footer', 'register'); /* 插件注入点（注册按钮下方），与登录页同钩子不同页面参数 */ ?>
        <div class="auth-links"><?php echo e(theme_t('theme.auth.has_account')); ?><a href="<?php echo e(Router::url('login')); ?>"><?php echo e(theme_t('theme.auth.login_now')); ?></a></div>
    </div>
    <?php // JS 文案注入：内核 verify.js 读 CB_VERIFY.msg；主题 register_check.js 读 CB_REG_LANG（缺省均保持中文） ?>
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
    window.CB_REG_LANG = <?php echo json_out_script(array(
        'need_password'    => theme_t('theme.js.reg_need_password'),
        'pwd_len'          => theme_t('theme.js.pwd_len'),
        'pwd_classes'      => theme_t('theme.js.pwd_classes'),
        'pwd_contain_name' => theme_t('theme.js.pwd_contain_name'),
        'need_username'    => theme_t('theme.js.reg_need_username'),
        'username_invalid' => theme_t('theme.js.reg_username_invalid'),
        'email_invalid'    => theme_t('theme.js.reg_email_invalid'),
        'need_email_code'  => theme_t('theme.js.reg_need_email_code'),
        'need_phone'       => theme_t('theme.js.reg_need_phone'),
        'phone_invalid'    => theme_t('theme.js.reg_phone_invalid'),
        'need_sms_code'    => theme_t('theme.js.reg_need_sms_code'),
        'need_password2'   => theme_t('theme.js.reg_need_password2'),
        'pwd_mismatch'     => theme_t('theme.js.pwd_mismatch'),
        'match_ok'         => theme_t('theme.js.pwd_match_ok'),
        'match_no'         => theme_t('theme.js.pwd_mismatch_live'),
    )); ?>;
    </script>
    <script src="<?php echo e(assets_url('front/verify.js')); ?>"></script>
    <script src="<?php echo e(Theme::assetsUrl('js/pwd_check.js')); ?>"></script>
    <script src="<?php echo e(Theme::assetsUrl('js/register_check.js')); ?>"></script>
<?php Theme::part('footer'); ?>
