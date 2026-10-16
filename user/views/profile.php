<?php
/**
 * 后台视图：个人资料（layui 表单）
 */
defined('APP_BOOT') or exit;
?>
<div class="card">
    <h3><?php echo e(admin_t('admin.profile.basic')); ?></h3>
    <form method="post" action="<?php echo e(site_base_admin('profile/save')); ?>" class="layui-form v-form">
        <?php echo Csrf::field(); ?>
        <div class="layui-form-item">
            <label class="v-label"><?php echo e(admin_t('admin.user.col_username')); ?></label>
            <input type="text" value="<?php echo e($user['username']); ?>" disabled class="layui-input">
        </div>
        <div class="layui-form-item">
            <label class="v-label"><?php echo e(admin_t('admin.user.col_nickname')); ?></label>
            <input type="text" name="nickname" value="<?php echo e($user['nickname']); ?>" class="layui-input">
        </div>
        <div class="layui-form-item">
            <label class="v-label"><?php echo e(admin_t('admin.profile.signature')); ?></label>
            <input type="text" name="signature" maxlength="100" value="<?php echo e($user['signature']); ?>" class="layui-input">
        </div>
        <div class="layui-form-item">
            <label class="v-label"><?php echo e(admin_t('admin.user.col_email')); ?></label>
            <input type="email" name="email" id="profileEmail" value="<?php echo $user['email'] !== null ? e($user['email']) : ''; ?>" class="layui-input">
        </div>
        <?php if (get_verify_provider('email') !== null): ?>
        <div class="layui-form-item">
            <label class="v-label"><?php echo e(admin_t('admin.profile.email_code')); ?></label>
            <div class="filter-bar">
                <input type="text" name="email_code" maxlength="6" class="layui-input" style="width:120px">
                <button type="button" class="layui-btn layui-btn-sm layui-btn-primary" data-scene="profile" data-channel="email" data-target="#profileEmail"><?php echo e(admin_t('admin.profile.send_code')); ?></button>
            </div>
        </div>
        <?php endif; ?>
        <div class="layui-form-item">
            <label class="v-label"><?php echo e(admin_t('admin.user.col_phone')); ?></label>
            <input type="text" name="phone" id="profilePhone" pattern="1[3-9][0-9]{9}" value="<?php echo e($user['phone']); ?>" class="layui-input">
        </div>
        <?php if (get_verify_provider('sms') !== null): ?>
        <div class="layui-form-item">
            <label class="v-label"><?php echo e(admin_t('admin.profile.sms_code')); ?></label>
            <div class="filter-bar">
                <input type="text" name="sms_code" maxlength="6" class="layui-input" style="width:120px">
                <button type="button" class="layui-btn layui-btn-sm layui-btn-primary" data-scene="profile" data-channel="sms" data-target="#profilePhone"><?php echo e(admin_t('admin.profile.send_code')); ?></button>
            </div>
        </div>
        <?php endif; ?>
        <div class="layui-form-item">
            <label class="v-label"><?php echo e(admin_t('admin.profile.avatar')); ?></label>
            <div class="filter-bar" style="align-items:center">
                <img src="<?php echo $user['avatar'] !== '' ? e(Router::base() . '/' . $user['avatar']) : ''; ?>"
                     id="avatarPreview" alt="<?php echo e(admin_t('admin.profile.avatar_preview')); ?>"
                     style="display:<?php echo $user['avatar'] !== '' ? 'inline-block' : 'none'; ?>;width:48px;height:48px;border-radius:50%;object-fit:cover">
                <?php // 头像路径为隐藏域：只接受上传接口回写的值，不提供手动填写入口 ?>
                <input type="hidden" name="avatar" id="avatarPath" value="<?php echo e($user['avatar']); ?>">
                <?php if (Auth::check_cap('edit_profile')): ?>
                <input type="file" id="avatarFile" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none">
                <button type="button" class="layui-btn layui-btn-sm layui-btn-primary" id="avatarUploadBtn">
                    <i class="layui-icon layui-icon-upload"></i> <?php echo e(admin_t('admin.profile.avatar_upload')); ?>
                </button>
                <?php endif; ?>
            </div>
        </div>
        <div class="layui-form-item">
            <label class="v-label"><?php echo e(admin_t('admin.profile.current_pwd')); ?></label>
            <input type="password" name="password" autocomplete="current-password" class="layui-input">
        </div>
        <button class="layui-btn" type="submit"><i class="layui-icon layui-icon-ok"></i> <?php echo e(admin_t('admin.common.save')); ?></button>
    </form>
</div>

<div class="card">
    <h3><?php echo e(admin_t('admin.profile.change_pwd_card')); ?></h3>
    <a class="layui-btn" href="<?php echo e(site_base_admin('profile/password')); ?>">
        <i class="layui-icon layui-icon-password"></i> <?php echo e(admin_t('admin.profile.goto_change_pwd')); ?>
    </a>
</div>

<?php do_action('profile_cards', $user); /* 插件追加卡片（如第三方账号绑定/解绑） */ ?>

<?php if (Auth::check_cap('edit_profile')): ?>
<script>
// 验证码发送按钮配置（复用前台 verify.js 通用逻辑，必须在引入前定义）
window.CB_VERIFY = {
    url: <?php echo json_out_script(Router::url('verify_send')); ?>,
    csrf: <?php echo json_out_script(Csrf::token()); ?>,
    // 后台语言包对 verify.js 提示语的覆盖注入
    msg: <?php echo json_out_script(array(
        'fill_target'   => admin_t('admin.verify.fill_target'),
        'network_error' => admin_t('admin.verify.network_error'),
        'retry_in'      => admin_t('admin.verify.retry_in'),
        'send'          => admin_t('admin.profile.send_code'),
    )); ?>
};
</script>
<script src="<?php echo e(assets_url('front/verify.js')); ?>"></script>
<script>
(function () {
    var btn = document.getElementById('avatarUploadBtn');
    var fileInput = document.getElementById('avatarFile');
    if (!btn || !fileInput) { return; }
    // 反馈优先用 layui layer.msg（admin.js 暴露于 CB_ADMIN），缺失时回退 alert
    function feedback(msg, ok) {
        if (window.CB_ADMIN && CB_ADMIN.layer) {
            CB_ADMIN.layer.msg(msg, { icon: ok ? 1 : 2 });
        } else {
            window.alert(msg);
        }
    }
    btn.addEventListener('click', function () { fileInput.click(); });
    fileInput.addEventListener('change', function () {
        if (!fileInput.files.length) { return; }
        var fd = new FormData();
        fd.append('file', fileInput.files[0]);
        fd.append('_csrf', <?php echo json_out_script(Csrf::token()); ?>);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', <?php echo json_out_script(site_base_admin('upload/avatar')); ?>, true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4) {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.code === 0) {
                        // 表单项只存 uploads/ 相对路径，预览用完整 URL
                        document.getElementById('avatarPath').value = resp.data.path;
                        var preview = document.getElementById('avatarPreview');
                        preview.src = resp.data.url;
                        preview.style.display = 'inline-block';
                        feedback(<?php echo json_out_script(admin_t('admin.upload.avatar_ok')); ?>, true);
                    } else {
                        feedback(resp.msg || <?php echo json_out_script(admin_t('admin.upload.failed')); ?>, false);
                    }
                } catch (err) { feedback(<?php echo json_out_script(admin_t('admin.upload.failed')); ?>, false); }
            }
        };
        xhr.send(fd);
        fileInput.value = '';
    });
})();
</script>
<?php endif; ?>
