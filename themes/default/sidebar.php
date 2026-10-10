<?php
/**
 * 默认主题侧边栏：站名/座右铭 + 作者卡片 + 搜索框 + 导航 + 分类（带文章数）
 */
defined('APP_BOOT') or exit;
$authorInfo = site_author();
?>
<aside id="sidebar" role="complementary">
    <?php // sidebar-body 包裹主内容：flex 列布局下与底部明暗切换钮分离，切换钮 margin-top:auto 钉在左下角 ?>
    <div class="sidebar-body">
    <div class="sidebar-site-title">
        <a href="<?php echo e(Router::url('home')); ?>"><?php echo e(site_name()); ?></a>
    </div>
    <?php if (site_motto() !== ''): ?>
    <p class="sidebar-site-description"><?php echo e(site_motto()); ?></p>
    <?php endif; ?>

    <?php if ($authorInfo): ?>
    <img class="sidebar-avatar" src="<?php echo e(avatar_url($authorInfo['avatar'])); ?>" alt="<?php echo e($authorInfo['nickname']); ?>">
    <div class="sidebar-author-name"><?php echo e($authorInfo['nickname']); ?></div>
    <?php // 站长简介改为展示该用户自助设置的个性签名（留空则不展示），与作者页保持一致 ?>
    <?php if (!empty($authorInfo['signature'])): ?>
    <div class="sidebar-author-bio"><?php echo e($authorInfo['signature']); ?></div>
    <?php endif; ?>
    <?php endif; ?>

    <?php // 头像下方账号入口：未登录显示登录/注册，已登录显示仪表盘 ?>
    <div class="sidebar-auth">
        <?php if (Auth::check()): ?>
        <a class="sidebar-auth-btn primary" href="<?php echo e(site_base_admin()); ?>"><?php echo e(theme_t('theme.sidebar.dashboard')); ?></a>
        <?php else: ?>
        <a class="sidebar-auth-btn primary" href="<?php echo e(Router::url('login')); ?>"><?php echo e(theme_t('theme.sidebar.login')); ?></a>
        <?php if (Option::get('register_disabled', '0') !== '1'): ?>
        <a class="sidebar-auth-btn" href="<?php echo e(Router::url('register')); ?>"><?php echo e(theme_t('theme.sidebar.register')); ?></a>
        <?php endif; ?>
        <?php endif; ?>
    </div>

    <div class="sidebar-search">
        <form role="search" method="get" action="<?php echo e(Router::url('search')); ?>">
            <?php if (!Router::rewriteEnabled()): ?>
            <input type="hidden" name="r" value="search">
            <?php endif; ?>
            <label>
                <span class="sidebar-site-description"><?php echo e(theme_t('theme.sidebar.search_label')); ?></span>
                <input type="search" class="search-field" placeholder="<?php echo e(theme_t('theme.sidebar.search_placeholder')); ?>" name="q" value="">
            </label>
        </form>
    </div>

    <div class="sidebar-section">
        <h3 class="sidebar-section-title"><?php echo e(theme_t('theme.sidebar.nav')); ?></h3>
        <ul class="sidebar-menu">
            <li><a href="<?php echo e(Router::url('home')); ?>"><?php echo e(theme_t('theme.sidebar.home')); ?></a></li>
            <?php foreach (nav_pages() as $navPage): ?>
            <?php // 无别名的页面由 Router::url 回退数字 id 访问 ?>
            <li><a href="<?php echo e(Router::url('page', array('slug' => $navPage['slug'], 'id' => (int) $navPage['id']))); ?>"><?php echo e($navPage['title']); ?></a></li>
            <?php endforeach; ?>
            <?php // 自定义导航项：后台「导航管理」增删，URL 保存时已过白名单校验；支持一层子项 ?>
            <?php foreach (nav_items() as $navItem): ?>
            <?php if (!empty($navItem['children'])): ?>
            <li class="sidebar-menu-parent">
                <span><?php echo e($navItem['title']); ?></span>
                <ul class="sidebar-menu-children">
                    <?php foreach ($navItem['children'] as $child): ?>
                    <li><a href="<?php echo e($child['url']); ?>"><?php echo e($child['title']); ?></a></li>
                    <?php endforeach; ?>
                </ul>
            </li>
            <?php else: ?>
            <li><a href="<?php echo e($navItem['url']); ?>"><?php echo e($navItem['title']); ?></a></li>
            <?php endif; ?>
            <?php endforeach; ?>
        </ul>
    </div>

    <div class="sidebar-section">
        <h3 class="sidebar-section-title"><?php echo e(theme_t('theme.sidebar.category')); ?></h3>
        <ul class="sidebar-categories">
            <?php foreach (nav_categories() as $cat): ?>
            <li>
                <a href="<?php echo e(Router::url('category', array('slug' => $cat['slug']))); ?>">
                    <?php echo e($cat['name']); ?>
                    <span class="cat-count"><?php echo (int) $cat['count']; ?></span>
                </a>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>

    <?php // 语言切换：仅在主题提供多于一个语言包时渲染；当前语言纯文本高亮，其余为切换链接 ?>
    <?php $sidebarLangs = Theme::availableLangs(); ?>
    <?php if (count($sidebarLangs) > 1): ?>
    <div class="sidebar-section">
        <h3 class="sidebar-section-title"><?php echo e(theme_t('theme.sidebar.language')); ?></h3>
        <ul class="sidebar-menu sidebar-langs">
            <?php foreach ($sidebarLangs as $sidebarLangCode => $sidebarLangName): ?>
            <li>
                <?php if ($sidebarLangCode === Theme::langCode()): ?>
                <span class="lang-current"><?php echo e($sidebarLangName); ?></span>
                <?php else: ?>
                <a href="<?php echo e(Theme::langSwitchUrl($sidebarLangCode)); ?>"><?php echo e($sidebarLangName); ?></a>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <?php do_action('sidebar_widgets'); /* 插件注入点：侧边栏追加分组/小工具（输出需自带 .sidebar-section 结构并自行转义） */ ?>

    </div><!-- /.sidebar-body -->

    <?php // 明暗切换钉在侧边栏左下角（不再随内容流居中） ?>
    <div class="sidebar-darkmode">
        <button type="button" class="darkmode-toggle" id="darkmode-toggle" aria-label="<?php echo e(theme_t('theme.common.darkmode')); ?>" title="<?php echo e(theme_t('theme.common.darkmode')); ?>">
            <span id="darkmode-icon">☾</span>
        </button>
    </div>
</aside>
