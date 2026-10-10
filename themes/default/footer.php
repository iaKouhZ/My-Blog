<?php
/**
 * 默认主题公共页脚：闭合 #content/#main、版权行、front_footer 钩子
 * 约定：各页面模板只开启 <div id="content">，由本文件统一闭合
 */
defined('APP_BOOT') or exit;
?>
</div><!-- /#content -->

<footer class="site-footer">
    <p><?php echo e(copyright_line()); ?></p>
    <?php // 备案信息：主题设置维护，未填写的项不展示，两项均填时以 | 分隔 ?>
    <?php $footerIcp = icp_number(); ?>
    <?php $footerGongan = gongan_number(); ?>
    <?php if ($footerIcp !== '' || $footerGongan !== ''): ?>
    <div class="footer-beian">
        <?php if ($footerIcp !== ''): ?>
        <a href="https://beian.miit.gov.cn/" target="_blank" rel="nofollow noopener"><?php echo e($footerIcp); ?></a>
        <?php endif; ?>
        <?php if ($footerIcp !== '' && $footerGongan !== ''): ?>
        <span class="beian-divider">|</span>
        <?php endif; ?>
        <?php if ($footerGongan !== ''): ?>
        <?php // 提取到数字时带编号查备案；提取失败则回退到通用查询页，不降级为纯文本 ?>
        <?php $footerGonganCode = gongan_code($footerGongan); ?>
        <?php $footerGonganUrl = $footerGonganCode !== ''
            ? 'https://beian.mps.gov.cn/#/query/webSearch?code=' . $footerGonganCode
            : 'https://beian.mps.gov.cn/#/query/webSearch'; ?>
        <a href="<?php echo e($footerGonganUrl); ?>"
           target="_blank" rel="nofollow noopener" class="beian-gongan">
            <img src="<?php echo e(Theme::assetsUrl('gongan-icon.png')); ?>" alt="" class="gongan-icon"><?php echo e($footerGongan); ?>
        </a>
        <?php endif; ?>
    </div>
    <?php endif; ?>
    <div class="footer-account">
        <?php if (Auth::check()): ?>
        <a href="<?php echo e(site_base_admin()); ?>"><?php echo e(theme_t('theme.footer.admin')); ?></a> ·
        <form method="post" action="<?php echo e(Router::url('logout')); ?>" class="footer-logout-form">
            <?php echo Csrf::field(); ?>
            <button type="submit" class="footer-logout"><?php echo e(theme_t('theme.footer.logout')); ?></button>
        </form>
        <?php else: ?>
        <a href="<?php echo e(Router::url('login')); ?>"><?php echo e(theme_t('theme.footer.login')); ?></a>
        <?php if (Option::get('register_disabled', '0') !== '1'): ?>
        · <a href="<?php echo e(Router::url('register')); ?>"><?php echo e(theme_t('theme.footer.register')); ?></a>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</footer>

</div><!-- /#main -->
</div><!-- /.site-wrapper -->

<?php // 回到顶部：全站通用悬浮钮，滚动超过阈值后由 theme.js 显示 ?>
<button type="button" class="back-to-top" id="back-to-top" aria-label="<?php echo e(theme_t('theme.common.back_to_top')); ?>" title="<?php echo e(theme_t('theme.common.back_to_top')); ?>">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 19V5M5 12l7-7 7 7"/></svg>
</button>

<?php theme_footer(); ?>
</body>
</html>
