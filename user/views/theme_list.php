<?php
/**
 * 后台视图：模板管理（layui 表格/徽章/按钮）
 */
defined('APP_BOOT') or exit;
?>
<div class="card">
    <h3><?php echo e(admin_t('admin.theme.upload_title')); ?></h3>
    <form method="post" action="<?php echo e(site_base_admin('theme/upload')); ?>" enctype="multipart/form-data" class="filter-bar">
        <?php echo Csrf::field(); ?>
        <input type="file" name="theme_zip" accept=".zip" required>
        <button class="layui-btn" type="submit"><i class="layui-icon layui-icon-upload"></i> <?php echo e(admin_t('admin.common.upload')); ?></button>
    </form>
    <p class="tip"><?php echo e(admin_t('admin.theme.upload_tip', array((int) floor($uploadLimit / 1048576)))); ?></p>
</div>

<div class="card">
    <div class="table-wrap">
    <table class="layui-table" lay-skin="line">
        <thead>
            <tr><th><?php echo e(admin_t('admin.theme.col_dir')); ?></th><th><?php echo e(admin_t('admin.category.name')); ?></th><th><?php echo e(admin_t('admin.post.col_author')); ?></th><th><?php echo e(admin_t('admin.theme.col_version')); ?></th><th><?php echo e(admin_t('admin.category.description')); ?></th><th><?php echo e(admin_t('admin.common.status')); ?></th><th><?php echo e(admin_t('admin.common.actions')); ?></th></tr>
        </thead>
        <tbody>
        <?php if (empty($themes)): ?>
        <tr class="empty-row"><td colspan="7"><?php echo e(admin_t('admin.theme.empty')); ?></td></tr>
        <?php endif; ?>
        <?php foreach ($themes as $themeDir => $meta): ?>
        <tr>
            <td><?php echo e($themeDir); ?></td>
            <td><?php echo e($meta['name']); ?></td>
            <td><?php echo e($meta['author']); ?></td>
            <td><?php echo e($meta['version']); ?></td>
            <td><?php echo e(mb_substr($meta['description'], 0, 40)); ?></td>
            <td>
                <?php if ($themeDir === $active): ?><span class="layui-badge layui-bg-green"><?php echo e(admin_t('admin.theme.active')); ?></span>
                <?php else: ?><span class="layui-badge layui-bg-gray"><?php echo e(admin_t('admin.theme.inactive')); ?></span><?php endif; ?>
            </td>
            <td>
                <div class="row-actions">
                <?php // 设置按钮仅对提供 settings.php 清单的主题展示 ?>
                <?php if (!empty(Theme::settingsSchema($themeDir))): ?>
                <a class="layui-btn layui-btn-xs layui-btn-primary" href="<?php echo e(site_base_admin('theme/setting&dir=' . rawurlencode($themeDir))); ?>"><?php echo e(admin_t('admin.theme.configure')); ?></a>
                <?php endif; ?>
                <?php if ($themeDir !== $active): ?>
                <form method="post" action="<?php echo e(site_base_admin('theme/activate')); ?>">
                    <?php echo Csrf::field(); ?>
                    <input type="hidden" name="dir" value="<?php echo e($themeDir); ?>">
                    <button class="layui-btn layui-btn-xs layui-btn-normal"><?php echo e(admin_t('admin.common.enabled')); ?></button>
                </form>
                <?php endif; ?>
                <?php if ($themeDir !== 'default' && $themeDir !== $active): ?>
                <form method="post" action="<?php echo e(site_base_admin('theme/delete')); ?>"
                      data-confirm="<?php echo e(admin_t('admin.theme.delete_confirm')); ?>">
                    <?php echo Csrf::field(); ?>
                    <input type="hidden" name="dir" value="<?php echo e($themeDir); ?>">
                    <button class="layui-btn layui-btn-xs layui-btn-danger"><?php echo e(admin_t('admin.common.delete')); ?></button>
                </form>
                <?php endif; ?>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
