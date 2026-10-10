<?php
/**
 * 默认主题：独立页面（如“关于我”）
 */
defined('APP_BOOT') or exit;
Theme::part('header');
$pagePost = the_post();
?>
<div id="content">
    <?php // 正文工具栏（hendrix 式）：返回 / 字号增减 / 打印，交互见 theme.js initArticleToolbar ?>
    <div class="article-toolbar">
        <button type="button" class="toolbar-btn" id="btn-back" data-home="<?php echo e(Router::url('home')); ?>" title="<?php echo e(theme_t('theme.common.back')); ?>" aria-label="<?php echo e(theme_t('theme.common.back')); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        </button>
        <div class="toolbar-spacer"></div>
        <button type="button" class="toolbar-btn" id="btn-font-decrease" title="<?php echo e(theme_t('theme.common.font_decrease')); ?>" aria-label="<?php echo e(theme_t('theme.common.font_decrease')); ?>">
            <span class="font-btn-inner">A</span><span class="font-btn-inner-small">A</span>
        </button>
        <button type="button" class="toolbar-btn" id="btn-font-increase" title="<?php echo e(theme_t('theme.common.font_increase')); ?>" aria-label="<?php echo e(theme_t('theme.common.font_increase')); ?>">
            <span class="font-btn-inner">A</span><span class="font-btn-inner-large">A</span>
        </button>
        <button type="button" class="toolbar-btn" id="btn-print" title="<?php echo e(theme_t('theme.common.print_page')); ?>" aria-label="<?php echo e(theme_t('theme.common.print_page')); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        </button>
    </div>

    <article>
        <header class="article-header">
            <h1 class="article-title"><?php echo e($pagePost['title']); ?></h1>
        </header>
        <div class="article-content" id="article-content">
            <?php echo render_content($pagePost['content']); ?>
        </div>
    </article>
    <?php do_action('page_content_after', $pagePost); /* 插件注入点：独立页面正文结束后 */ ?>
<?php Theme::part('footer'); ?>
