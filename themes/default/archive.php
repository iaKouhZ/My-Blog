<?php
/**
 * 默认主题：分类/作者归档（页头卡片 + post-card 列表）
 */
defined('APP_BOOT') or exit;
Theme::part('header');
?>
<div id="content">
    <?php if ($route === 'author' && isset($subject['id'])): ?>
    <?php // 作者页头部：头像 / 昵称 / 个性签名三行居中，不显示“作者：”前缀 ?>
    <div class="author-header">
        <img class="author-header-avatar" src="<?php echo e(avatar_url($subject['avatar'])); ?>" alt="<?php echo e($subject['nickname']); ?>">
        <div class="author-header-name"><?php echo e($subject['nickname']); ?></div>
        <?php if (!empty($subject['signature'])): ?>
        <p class="author-header-signature"><?php echo e($subject['signature']); ?></p>
        <?php endif; ?>
        <?php do_action('author_header_after', $subject); /* 插件注入点：作者头部区块内末尾（如认证标识/社交链接） */ ?>
    </div>
    <?php else: ?>
    <div class="page-header">
        <h1 class="page-title"><?php echo e($title); ?></h1>
        <?php if (isset($subject['description']) && $subject['description'] !== ''): ?>
        <p class="page-description"><?php echo e($subject['description']); ?></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (empty($posts)): ?>
    <p class="empty-tip"><?php echo e(theme_t('theme.common.empty_archive')); ?></p>
    <?php endif; ?>

    <?php foreach ($posts as $postItem): ?>
    <?php $postUrl = Router::url('post', array('slug' => $postItem['slug'] !== '' ? $postItem['slug'] : (int) $postItem['id'], 'id' => (int) $postItem['id'])); ?>
    <?php do_action('post_card_before', $postItem, 'archive'); /* 插件注入点：卡片前 */ ?>
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
            <span class="meta-divider">·</span>
            <span><?php echo e(theme_t('theme.common.read_count', array((int) $postItem['views']))); ?></span>
        </div>
        <p class="post-card-excerpt"><?php echo e(!empty($postItem['excerpt']) ? $postItem['excerpt'] : excerpt_of($postItem['content'])); ?></p>
        <a class="post-card-readmore" href="<?php echo e($postUrl); ?>"><?php echo e(theme_t('theme.common.read_more')); ?></a>
    </article>
    <?php do_action('post_card_after', $postItem, 'archive'); /* 插件注入点：卡片后 */ ?>
    <?php endforeach; ?>

    <?php echo paginate($page, $totalPages, $route, $routeParams); ?>
<?php Theme::part('footer'); ?>
