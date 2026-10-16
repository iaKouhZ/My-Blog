<?php
/** 评论读取服务：统一访客可见性、稳定分页与评论所在页定位。 */
defined('APP_BOOT') or exit;

class CommentQuery
{
    const PER_PAGE = 50;

    /** 游客只读已发布评论，登录用户额外可读自己的待审核评论。 */
    private static function visible($postId, $userId)
    {
        $query = DB::query('comments')->where('post_id', '=', (int) $postId);
        if ((int) $userId > 0) {
            $query->whereAnyGroups(array(
                array(array('status', '=', 'published')),
                array(array('status', '=', 'pending'), array('user_id', '=', (int) $userId)),
            ));
        } else {
            $query->where('status', '=', 'published');
        }
        return $query;
    }

    /** 同一筛选用于总数与当前页，避免待审核数据影响其它访客的分页。 */
    public static function page($postId, $userId, $page)
    {
        $query = self::visible($postId, $userId);
        $total = $query->count();
        $pages = max(1, (int) ceil($total / self::PER_PAGE));
        $page = min($pages, max(1, (int) $page));
        $rows = $query->orderBy('created_at', 'ASC')->orderBy('id', 'ASC')
            ->limit(self::PER_PAGE, ($page - 1) * self::PER_PAGE)->select();
        return array('comments' => $rows, 'total' => $total, 'page' => $page, 'pages' => $pages);
    }

    /** 发表或修改后返回评论实际所在页，排序与列表完全一致。 */
    public static function pageOf(array $comment, $userId)
    {
        $position = self::visible($comment['post_id'], $userId)->whereAnyGroups(array(
            array(array('created_at', '<', $comment['created_at'])),
            array(array('created_at', '=', $comment['created_at']), array('id', '<=', (int) $comment['id'])),
        ))->count();
        return max(1, (int) ceil($position / self::PER_PAGE));
    }
}
