<?php
/**
 * 后台视图：安全设置（等保二级相关控制点落地配置；layui 折叠面板分组 + 开关）
 */
defined('APP_BOOT') or exit;
$switchText = e(admin_t('admin.common.on')) . '|' . e(admin_t('admin.common.off'));
?>
<div class="card">
    <p class="tip"><?php echo e(admin_t('admin.security.tip')); ?></p>
    <form method="post" action="<?php echo e(site_base_admin('setting/security_save')); ?>" class="layui-form v-form">
        <?php echo Csrf::field(); ?>

        <div class="layui-collapse" style="margin-bottom:16px">
            <div class="layui-colla-item">
                <h3 class="layui-colla-title"><?php echo e(admin_t('admin.security.group_login')); ?></h3>
                <div class="layui-colla-content layui-show">
                    <div class="layui-form-item">
                        <label class="v-label"><?php echo e(admin_t('admin.security.login_max_fail')); ?></label>
                        <input type="number" name="login_max_fail" min="1" max="20"
                               value="<?php echo (int) Option::get('login_max_fail', 5); ?>" class="layui-input">
                    </div>
                    <div class="layui-form-item">
                        <label class="v-label"><?php echo e(admin_t('admin.security.lock_minutes')); ?></label>
                        <input type="number" name="login_lock_minutes" min="1" max="1440"
                               value="<?php echo (int) Option::get('login_lock_minutes', 10); ?>" class="layui-input">
                    </div>
                    <div class="layui-form-item">
                        <label class="v-label"><?php echo e(admin_t('admin.security.session_timeout')); ?></label>
                        <input type="number" name="session_timeout_minutes" min="1" max="1440"
                               value="<?php echo (int) Option::get('session_timeout_minutes', 30); ?>" class="layui-input">
                    </div>
                </div>
            </div>
            <div class="layui-colla-item">
                <h3 class="layui-colla-title"><?php echo e(admin_t('admin.security.group_password')); ?></h3>
                <div class="layui-colla-content layui-show">
                    <div class="layui-form-item">
                        <label class="v-label"><?php echo e(admin_t('admin.security.pwd_expire')); ?></label>
                        <input type="checkbox" name="pwd_expire_enabled" lay-skin="switch" lay-text="<?php echo $switchText; ?>" value="1"
                               <?php echo Option::get('pwd_expire_enabled', '0') === '1' ? 'checked' : ''; ?>>
                        <div class="form-hint"><?php echo e(admin_t('admin.security.pwd_expire_hint')); ?></div>
                    </div>
                    <div class="layui-form-item">
                        <label class="v-label"><?php echo e(admin_t('admin.security.pwd_expire_days')); ?></label>
                        <input type="number" name="pwd_expire_days" min="30" max="365"
                               value="<?php echo (int) Option::get('pwd_expire_days', 90); ?>" class="layui-input">
                    </div>
                    <div class="layui-form-item">
                        <label class="v-label"><?php echo e(admin_t('admin.security.pwd_history')); ?></label>
                        <input type="number" name="pwd_history_count" min="0" max="24"
                               value="<?php echo (int) Option::get('pwd_history_count', 0); ?>" class="layui-input">
                    </div>
                </div>
            </div>
            <div class="layui-colla-item">
                <h3 class="layui-colla-title"><?php echo e(admin_t('admin.security.group_ip')); ?></h3>
                <div class="layui-colla-content layui-show">
                    <div class="layui-form-item">
                        <label class="v-label"><?php echo e(admin_t('admin.security.ip_header')); ?></label>
                        <input type="checkbox" name="ip_header_enabled" lay-skin="switch" lay-text="<?php echo $switchText; ?>" value="1"
                               <?php echo Option::get('ip_header_enabled', '0') === '1' ? 'checked' : ''; ?>>
                        <div class="form-hint"><?php echo e(admin_t('admin.security.ip_header_hint')); ?></div>
                    </div>
                    <div class="layui-form-item">
                        <label class="v-label"><?php echo e(admin_t('admin.security.ip_header_name')); ?></label>
                        <input type="text" name="ip_header_name" pattern="[A-Za-z0-9-]+(,[A-Za-z0-9-]+)*"
                               placeholder="ali-real-client-ip,X-Forwarded-For"
                               value="<?php echo e(Option::get('ip_header_name', 'X-Forwarded-For')); ?>" class="layui-input">
                        <div class="form-hint"><?php echo e(admin_t('admin.security.ip_header_name_hint')); ?></div>
                    </div>
                    <p class="tip"><?php echo e(admin_t('admin.security.ip_warn')); ?></p>
                </div>
            </div>
            <div class="layui-colla-item">
                <h3 class="layui-colla-title"><?php echo e(admin_t('admin.security.group_log')); ?></h3>
                <div class="layui-colla-content layui-show">
                    <div class="layui-form-item">
                        <label class="v-label"><?php echo e(admin_t('admin.security.log_retention')); ?></label>
                        <input type="number" name="log_retention_days" min="180" max="3650"
                               value="<?php echo (int) Option::get('log_retention_days', 180); ?>" class="layui-input">
                    </div>
                </div>
            </div>
            <div class="layui-colla-item">
                <h3 class="layui-colla-title"><?php echo e(admin_t('admin.security.group_media')); ?></h3>
                <div class="layui-colla-content layui-show">
                    <div class="layui-form-item">
                        <label class="v-label"><?php echo e(admin_t('admin.security.img_hosts')); ?></label>
                        <textarea name="img_ext_hosts_text" class="layui-textarea" rows="4"
                                  placeholder="i0.hdslb.com&#10;*.hdslb.com"><?php
                            echo e(implode("\n", ExternalMedia::configuredHosts()));
                        ?></textarea>
                        <div class="form-hint"><?php echo e(admin_t('admin.security.img_hosts_hint')); ?></div>
                    </div>
                    <p class="tip"><?php echo e(admin_t('admin.security.img_hosts_warn')); ?></p>
                </div>
            </div>
            <div class="layui-colla-item">
                <h3 class="layui-colla-title"><?php echo e(admin_t('admin.security.group_debug')); ?></h3>
                <div class="layui-colla-content layui-show">
                    <div class="layui-form-item">
                        <label class="v-label"><?php echo e(admin_t('admin.security.debug')); ?></label>
                        <input type="checkbox" name="debug" lay-skin="switch" lay-text="<?php echo $switchText; ?>" value="1"
                               <?php echo Option::get('debug', '0') === '1' ? 'checked' : ''; ?>>
                        <div class="form-hint"><?php echo e(admin_t('admin.security.debug_hint')); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <button class="layui-btn" type="submit"><i class="layui-icon layui-icon-ok"></i> <?php echo e(admin_t('admin.common.save')); ?></button>
    </form>
</div>
