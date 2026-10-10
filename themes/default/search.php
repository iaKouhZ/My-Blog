<?php
/**
 * 默认主题：搜索结果页（页头卡片 + post-card 列表）
 */
defined('APP_BOOT') or exit;
Theme::part('header');
?>
<div id="content">
    <div class="page-header">
        <h1 class="page-title"><?php echo e($kw !== '' ? theme_t('theme.search.title_with_kw', array($kw)) : theme_t('theme.search.title')); ?></h1>
        <p class="page-description"><?php echo e(theme_t('theme.search.result_count', array((int) $total))); ?></p>
    </div>

    <?php if (empty($posts)): ?>
    <p class="empty-tip"><?php echo e(theme_t('theme.search.empty')); ?></p>
    <?php endif; ?>

    <?php foreach ($posts as $postItem): ?>
    <?php $postUrl = Router::url('post', array('slug' => $postItem['slug'] !== '' ? $postItem['slug'] : (int) $postItem['id'], 'id' => (int) $postItem['id'])); ?>
    <?php do_action('post_card_before', $postItem, 'search'); /* 插件注入点：卡片前 */ ?>
    <article class="post-card">
        <h2 class="post-card-title"><?php if (!empty($postItem['is_top'])): ?><span class="badge badge-seal"><?php echo e(theme_t('theme.common.top_badge')); ?></span> <?php endif; ?><a href="<?php echo e($postUrl); ?>"><?php echo e($postItem['title']); ?></a></h2>
        <div class="post-card-meta">
            <?php // 作者名跳转作者页；id=0（“未知作者”回退数据）无页可跳仍用纯文本 ?>
            <?php if ((int) $postItem['author']['id'] > 0): ?>
            <a href="<?php echo e(Router::url('author', array('id' => (int) $postItem['author']['id']))); ?>"><?php echo e($postItem['author']['nickname']); ?></a>
            <?php else: ?>
            <span><?php echo e($postItem['author']['nickname']); ?></span>
            <?php endif; ?>
            <span class="meta-divider">·</span>
            <span><?php echo e(theme_date($postItem['created_at'])); ?></span>
        </div>
        <p class="post-card-excerpt"><?php echo e(!empty($postItem['excerpt']) ? $postItem['excerpt'] : excerpt_of($postItem['content'])); ?></p>
        <a class="post-card-readmore" href="<?php echo e($postUrl); ?>"><?php echo e(theme_t('theme.common.read_more')); ?></a>
    </article>
    <?php do_action('post_card_after', $postItem, 'search'); /* 插件注入点：卡片后 */ ?>
    <?php endforeach; ?>

    <?php if ($kw !== '' && ($page > 1 || $page < $totalPages)): ?>
    <nav class="pagination">
        <?php // 搜索分页需携带关键词 ?>
        <?php if ($page > 1): ?>
        <a class="page-link" href="<?php echo e(Router::url('search', array('q' => $kw)) . '&page=' . ($page - 1)); ?>">« <?php echo e(theme_t('theme.common.page_prev')); ?></a>
        <?php endif; ?>
        <?php if ($page < $totalPages): ?>
        <a class="page-link" href="<?php echo e(Router::url('search', array('q' => $kw)) . '&page=' . ($page + 1)); ?>"><?php echo e(theme_t('theme.common.page_next')); ?> »</a>
        <?php endif; ?>
    </nav>
    <?php endif; ?>
<?php Theme::part('footer'); ?>
