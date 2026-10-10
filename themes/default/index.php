<?php
/**
 * 默认主题：首页（hendrix 式 banner + post-card 文章列表）
 */
defined('APP_BOOT') or exit;
Theme::part('header');
?>
<div class="banner">
    <div class="banner-content">
        <h1 class="banner-title"><?php echo e(site_name()); ?></h1>
        <?php if (site_motto() !== ''): ?>
        <p class="banner-subtitle"><?php echo e(site_motto()); ?></p>
        <?php endif; ?>
    </div>
</div>

<div id="content">
    <?php foreach (flash_pull() as $fm): ?>
    <div class="<?php echo $fm['type'] === 'success' ? 'form-ok' : 'form-error'; ?>"><?php echo e($fm['text']); ?></div>
    <?php endforeach; ?>

    <?php if (empty($posts)): ?>
    <p class="empty-tip"><?php echo e(theme_t('theme.common.empty_posts')); ?></p>
    <?php endif; ?>

    <?php foreach ($posts as $postItem): ?>
    <?php $postUrl = Router::url('post', array('slug' => $postItem['slug'] !== '' ? $postItem['slug'] : (int) $postItem['id'], 'id' => (int) $postItem['id'])); ?>
    <?php do_action('post_card_before', $postItem, 'index'); /* 插件注入点：卡片前 */ ?>
    <article class="post-card">
        <h2 class="post-card-title"><?php if (!empty($postItem['is_top'])): ?><span class="badge badge-seal"><?php echo e(theme_t('theme.common.top_badge')); ?></span> <?php endif; ?><a href="<?php echo e($postUrl); ?>"><?php echo e($postItem['title']); ?></a></h2>
        <div class="post-card-meta">
            <?php // 作者名跳转作者页；author_id=0（“未知作者”回退数据）无页可跳仍用纯文本 ?>
            <?php if ((int) $postItem['author_id'] > 0): ?>
            <a href="<?php echo e(Router::url('author', array('id' => (int) $postItem['author_id']))); ?>"><?php echo e($postItem['author']['nickname']); ?></a>
            <?php else: ?>
            <span><?php echo e($postItem['author']['nickname']); ?></span>
            <?php endif; ?>
            <span class="meta-divider">·</span>
            <span><?php echo e(theme_date($postItem['created_at'])); ?></span>
            <?php if (!empty($postItem['category'])): ?>
            <span class="meta-divider">·</span>
            <a href="<?php echo e(Router::url('category', array('slug' => $postItem['category']['slug']))); ?>"><?php echo e($postItem['category']['name']); ?></a>
            <?php endif; ?>
            <span class="meta-divider">·</span>
            <span><?php echo e(theme_t('theme.common.read_count', array((int) $postItem['views']))); ?></span>
        </div>
        <p class="post-card-excerpt"><?php echo e(!empty($postItem['excerpt']) ? $postItem['excerpt'] : excerpt_of($postItem['content'])); ?></p>
        <a class="post-card-readmore" href="<?php echo e($postUrl); ?>"><?php echo e(theme_t('theme.common.read_more')); ?></a>
    </article>
    <?php do_action('post_card_after', $postItem, 'index'); /* 插件注入点：卡片后 */ ?>
    <?php endforeach; ?>

    <?php echo paginate($page, $totalPages, $route, $routeParams); ?>
<?php Theme::part('footer'); ?>
