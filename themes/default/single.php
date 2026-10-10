<?php
/**
 * 默认主题：文章详情 + 评论区 + 评论表单（登录可见）
 */
defined('APP_BOOT') or exit;
Theme::part('header');
$singlePost = the_post();
// 评论区渲染状态（插件可按文章关闭/只读）：list 列表、form 发表框、actions 编辑/删除操作
$commentAreaState = apply_filters('comment_area_state', array('list' => true, 'form' => true, 'actions' => true), $singlePost);
$commentActionsOn = !empty($commentAreaState['actions']);
?>
<div id="content">
    <?php foreach (flash_pull() as $fm): ?>
    <div class="<?php echo $fm['type'] === 'success' ? 'form-ok' : 'form-error'; ?>"><?php echo e($fm['text']); ?></div>
    <?php endforeach; ?>

    <?php // 正文工具栏（hendrix 式）：返回 / 字号增减 / 打印，交互见 theme.js initArticleToolbar ?>
    <div class="article-toolbar">
        <button type="button" class="toolbar-btn" id="btn-back" data-home="<?php echo e(Router::url('home')); ?>" title="<?php echo e(theme_t('theme.common.back')); ?>" aria-label="<?php echo e(theme_t('theme.common.back')); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M12 19l-7-7 7-7"/></svg>
        </button>
        <div class="toolbar-spacer"></div>
        <?php // 目录按钮：宽屏（≥1500px）目录常驻故 CSS 隐藏此钮；正文无 h2/h3 时 JS 也会隐藏；
        // data-toc-label/title 供 theme.js 动态创建目录面板时取文案（本页受限 CSP 禁内联脚本传参） ?>
        <button type="button" class="toolbar-btn" id="btn-toc" title="<?php echo e(theme_t('theme.common.toc')); ?>" aria-label="<?php echo e(theme_t('theme.common.toc')); ?>"
                data-toc-label="<?php echo e(theme_t('theme.common.toc')); ?>" data-toc-title="<?php echo e(theme_t('theme.common.toc_short')); ?>" aria-expanded="false">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>
        </button>
        <button type="button" class="toolbar-btn" id="btn-font-decrease" title="<?php echo e(theme_t('theme.common.font_decrease')); ?>" aria-label="<?php echo e(theme_t('theme.common.font_decrease')); ?>">
            <span class="font-btn-inner">A</span><span class="font-btn-inner-small">A</span>
        </button>
        <button type="button" class="toolbar-btn" id="btn-font-increase" title="<?php echo e(theme_t('theme.common.font_increase')); ?>" aria-label="<?php echo e(theme_t('theme.common.font_increase')); ?>">
            <span class="font-btn-inner">A</span><span class="font-btn-inner-large">A</span>
        </button>
        <button type="button" class="toolbar-btn" id="btn-print" title="<?php echo e(theme_t('theme.common.print_article')); ?>" aria-label="<?php echo e(theme_t('theme.common.print_article')); ?>">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><polyline points="6 9 6 2 18 2 18 9"/><path d="M6 18H4a2 2 0 0 1-2-2v-5a2 2 0 0 1 2-2h16a2 2 0 0 1 2 2v5a2 2 0 0 1-2 2h-2"/><rect x="6" y="14" width="12" height="8"/></svg>
        </button>
    </div>

    <?php // 阅读进度条与目录抽屉遮罩：均 position:fixed，DOM 位置不影响呈现，交互见 theme.js ?>
    <div class="reading-progress" id="reading-progress"><span class="reading-progress-bar" id="reading-progress-bar"></span></div>
    <div class="toc-overlay" id="toc-overlay"></div>

    <article>
        <header class="article-header">
            <h1 class="article-title"><?php echo e($singlePost['title']); ?></h1>
            <div class="article-meta">
                <?php // 作者名跳转作者页；id=0 为“未知作者”回退数据，无页可跳仍用纯文本 ?>
                <?php if ((int) $singlePost['author']['id'] > 0): ?>
                <a href="<?php echo e(Router::url('author', array('id' => (int) $singlePost['author']['id']))); ?>"><?php echo e($singlePost['author']['nickname']); ?></a>
                <?php else: ?>
                <span><?php echo e($singlePost['author']['nickname']); ?></span>
                <?php endif; ?>
                <span class="meta-divider">·</span>
                <span><?php echo e(theme_date($singlePost['created_at'])); ?></span>
                <?php if (!empty($singlePost['category'])): ?>
                <span class="meta-divider">·</span>
                <a href="<?php echo e(Router::url('category', array('slug' => $singlePost['category']['slug']))); ?>"><?php echo e($singlePost['category']['name']); ?></a>
                <?php endif; ?>
                <span class="meta-divider">·</span>
                <span><?php echo e(theme_t('theme.common.read_count', array((int) $singlePost['views']))); ?></span>
                <?php if ($singlePost['status'] !== 'published'): ?><span class="badge"><?php echo e(theme_t('theme.post.unpublished_preview')); ?></span><?php endif; ?>
            </div>
        </header>
        <?php // 首字下沉开关（主题设置 first_letter，默认开），样式见 style.css .drop-cap ?>
        <div class="article-content<?php echo theme_setting('first_letter', '1') === '1' ? ' drop-cap' : ''; ?>" id="article-content">
            <?php echo render_content($singlePost['content']); ?>
        </div>
    </article>
    <?php do_action('single_content_after', $singlePost); /* 插件注入点：正文结束后、评论区前（如打赏/转载/相关推荐） */ ?>

    <?php if (!empty($commentAreaState['list'])): /* list=false 时评论区整体不渲染 */ ?>
    <?php do_action('comments_before', $singlePost); /* 插件注入点：评论区整体之前 */ ?>
    <section class="comments-area">
        <h2 class="comments-title"><?php echo e(theme_t('theme.comment.title', array(count($comments)))); ?></h2>
        <?php if (empty($comments)): ?>
        <p class="empty-tip"><?php echo e(theme_t('theme.comment.empty')); ?></p>
        <?php endif; ?>

        <?php
        // 组织为一层回复结构
        $topComments = array();
        $childComments = array();
        foreach ($comments as $c) {
            if ((int) $c['parent_id'] === 0) {
                $topComments[] = $c;
            } else {
                $childComments[(int) $c['parent_id']][] = $c;
            }
        }

        // 自己评论的编辑/删除（plan.md §5.2：三种登录角色均可删改自己的评论）
        // actions=false（如评论只读态）时行内编辑与操作按钮一并失效
        $singleEditId = isset($editCommentId) && $commentActionsOn ? (int) $editCommentId : 0;
        $singlePostUrl = Router::url('post', array(
            'slug' => $singlePost['slug'] !== '' ? $singlePost['slug'] : (int) $singlePost['id'],
            'id'   => (int) $singlePost['id'],
        ));
        $isOwnComment = function ($commentRow) {
            return Auth::check() && (int) $commentRow['user_id'] === Auth::id();
        };
        // 评论正文：命中编辑态时输出行内编辑表单，否则输出原文 + 操作行
        $renderCommentBody = function ($commentRow) use ($isOwnComment, $singleEditId, $singlePostUrl, $commentActionsOn) {
            $cid = (int) $commentRow['id'];
            $own = $isOwnComment($commentRow);
            if ($own && $singleEditId === $cid) {
                echo '<div class="comment-content">';
                echo '<form class="comment-edit-form" method="post" action="' . e(Router::url('comment_update')) . '">';
                echo Csrf::field();
                echo '<input type="hidden" name="comment_id" value="' . $cid . '">';
                echo '<textarea name="content" maxlength="2000" required>' . e($commentRow['content']) . '</textarea>';
                echo '<div class="edit-actions">';
                echo '<button class="submit" type="submit">' . e(theme_t('theme.comment.save')) . '</button>';
                echo '<a class="comment-action-link" href="' . e($singlePostUrl) . '#comment-' . $cid . '">' . e(theme_t('theme.comment.cancel')) . '</a>';
                echo '</div></form></div>';
                return;
            }
            echo '<div class="comment-content">' . nl2br(e($commentRow['content'])) . '</div>';
            if ($own && $commentActionsOn) {
                // 伪静态回退模式下文章 URL 已含 ?r=，追加参数需改用 &
                $sep = strpos($singlePostUrl, '?') === false ? '?' : '&';
                echo '<div class="comment-actions">';
                echo '<a class="comment-action-link" href="' . e($singlePostUrl . $sep . 'edit_comment=' . $cid) . '#comment-' . $cid . '">' . e(theme_t('theme.comment.edit')) . '</a>';
                echo '<form class="comment-delete-form confirm-submit" data-confirm="' . e(theme_t('theme.comment.delete_confirm')) . '" method="post" action="' . e(Router::url('comment_delete')) . '">';
                echo Csrf::field();
                echo '<input type="hidden" name="comment_id" value="' . $cid . '">';
                echo '<button class="comment-action-btn danger" type="submit">' . e(theme_t('theme.comment.delete')) . '</button>';
                echo '</form></div>';
            }
        };
        ?>
        <ul class="comment-list">
            <?php foreach ($topComments as $c): ?>
            <li class="comment" id="comment-<?php echo (int) $c['id']; ?>">
                <div class="comment-author">
                    <img class="avatar" src="<?php echo e(avatar_url($c['author']['avatar'])); ?>" alt="">
                    <span><?php echo e($c['author']['nickname']); ?></span>
                    <?php if ($c['status'] === 'pending'): ?><span class="badge"><?php echo e(theme_t('theme.comment.pending_badge')); ?></span><?php endif; ?>
                </div>
                <div class="comment-meta"><?php echo e(theme_date($c['created_at'])); ?></div>
                <?php $renderCommentBody($c); ?>
                <?php if (isset($childComments[(int) $c['id']])): ?>
                <ul class="children">
                    <?php foreach ($childComments[(int) $c['id']] as $reply): ?>
                    <li class="comment" id="comment-<?php echo (int) $reply['id']; ?>">
                        <div class="comment-author">
                            <img class="avatar" src="<?php echo e(avatar_url($reply['author']['avatar'])); ?>" alt="">
                            <span><?php echo e($reply['author']['nickname']); ?></span>
                            <?php if ($reply['status'] === 'pending'): ?><span class="badge"><?php echo e(theme_t('theme.comment.pending_badge')); ?></span><?php endif; ?>
                        </div>
                        <div class="comment-meta"><?php echo e(theme_date($reply['created_at'])); ?></div>
                        <?php $renderCommentBody($reply); ?>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>

        <?php if (!empty($commentAreaState['form'])): /* form=false 时隐藏发表框（评论只读态） */ ?>
        <h3 class="comments-title"><?php echo e(theme_t('theme.comment.form_title')); ?></h3>
        <?php if (Auth::check()): ?>
        <form class="comment-form" method="post" action="<?php echo e(Router::url('comment_save')); ?>">
            <?php echo Csrf::field(); ?>
            <input type="hidden" name="post_id" value="<?php echo (int) $singlePost['id']; ?>">
            <input type="hidden" name="redirect" value="<?php echo e(Router::url('post', array('slug' => $singlePost['slug'] !== '' ? $singlePost['slug'] : (int) $singlePost['id'], 'id' => (int) $singlePost['id']))); ?>">
            <p><textarea name="content" required placeholder="<?php echo e(theme_t('theme.comment.placeholder')); ?>"></textarea></p>
            <p><button class="submit" type="submit"><?php echo e(theme_t('theme.comment.submit')); ?></button></p>
        </form>
        <?php else: ?>
        <?php // %s 占位为登录链接：链接 HTML 由模板拼接（安全），语言包文本经 e() 转义后再替换 ?>
        <?php $commentLoginLink = '<a href="' . e(Router::url('login')) . '">' . e(theme_t('theme.comment.login_link')) . '</a>'; ?>
        <p class="form-hint"><?php echo str_replace('%s', $commentLoginLink, e(theme_t('theme.comment.login_to_comment'))); ?></p>
        <?php endif; ?>
        <?php endif; ?>
    </section>
    <?php do_action('comments_after', $singlePost); /* 插件注入点：评论区整体之后 */ ?>
    <?php endif; ?>
<?php Theme::part('footer'); ?>
