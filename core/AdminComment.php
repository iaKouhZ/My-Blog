<?php
/**
 * 后台评论管理：列表/审核/删除（仅管理员，能力点 manage_comments）
 */
defined('APP_BOOT') or exit;

class AdminComment
{
    /** 评论列表（状态页签 + 作者/文章/发表时间筛选） */
    public static function listAction()
    {
        Auth::require_cap('manage_comments');
        $status = input_enum('status', array('all', 'published', 'pending', 'trash'), 'all', 'get');
        $page = max(1, input_int('page', 1, 'get'));
        $perPage = 20;

        $total = self::filtered($status)->count();
        $totalPages = max(1, (int) ceil($total / $perPage));
        // 越界页码钳制到末页，避免空表格
        $page = min($page, $totalPages);
        $comments = self::filtered($status)->orderBy('id', 'DESC')->limit($perPage, ($page - 1) * $perPage)->select();

        // 用户与文章映射
        $users = array();
        $rows = DB::query('users')->whereIn('id', array_unique(array_column($comments, 'user_id')))->select(array('id', 'nickname'));
        foreach ($rows as $r) {
            $users[(int) $r['id']] = $r['nickname'];
        }
        $posts = array();
        $rows = DB::query('posts')->whereIn('id', array_unique(array_column($comments, 'post_id')))->select(array('id', 'title'));
        foreach ($rows as $r) {
            $posts[(int) $r['id']] = $r['title'];
        }

        list($fFrom, $fTo) = filter_date_range();
        Admin::render(admin_t('admin.menu.comment'), 'comment_list', array(
            'comments' => $comments, 'status' => $status, 'page' => $page,
            'totalPages' => $totalPages, 'total' => $total,
            'users' => $users, 'posts' => $posts,
            'fAuthor' => trim(input_text('author', '', 32, 'get')),
            'fPostId' => input_int('post_id', 0, 'get'),
            'fFrom'   => $fFrom,
            'fTo'     => $fTo,
        ));
    }

    /** 按当前请求筛选条件构造查询（count 与 select 分离调用） */
    private static function filtered($status)
    {
        $query = DB::query('comments');
        if ($status !== 'all') {
            $query->where('status', '=', $status);
        }
        // 作者：纯数字按用户 ID 精确匹配；否则模糊匹配用户名/昵称反查用户 ID 集合
        //（个人博客用户量小，全表反查开销可忽，且天然兼容任意字符无需 LIKE 转义）
        $author = trim(input_text('author', '', 32, 'get'));
        if ($author !== '') {
            if (ctype_digit($author)) {
                $query->where('user_id', '=', (int) $author);
            } else {
                $rows = DB::query('users')->likeAny(array('username', 'nickname'), $author)->select(array('id'));
                $ids = array_column($rows, 'id');
                // 空集合时 whereIn 恒假，直接返回空列表
                $query->whereIn('user_id', $ids);
            }
        }
        $postId = input_int('post_id', 0, 'get');
        if ($postId > 0) {
            $query->where('post_id', '=', $postId);
        }
        // 发表时间窗口：首次进入默认"一个月前 ~ 当天"（与日志中心一致）
        list($from, $to) = filter_date_range();
        if ($from !== '') {
            $query->where('created_at', '>=', $from . ' 00:00:00');
        }
        if ($to !== '') {
            $query->where('created_at', '<=', $to . ' 23:59:59');
        }
        return $query;
    }

    /** 评论审核（通过/驳回） */
    public static function auditAction()
    {
        Auth::require_cap('moderate_comments');
        $id = input_int('id', 0, 'post');
        $decision = input_enum('decision', array('approve', 'reject'), '', 'post');
        if ($id > 0 && $decision !== '') {
            $newStatus = $decision === 'approve' ? 'published' : 'trash';
            DB::update('comments', array('status' => $newStatus), array('id' => $id));
            blog_log('comment', 'comment.audit', 'success', array('comment_id' => $id, 'decision' => $decision));
            flash_set('success', $decision === 'approve' ? admin_t('admin.comment.approved') : admin_t('admin.comment.rejected'));
        }
        redirect(site_base_admin('comment/list&status=pending'));
    }

    /** 评论移入回收站 */
    public static function deleteAction()
    {
        Auth::require_cap('manage_comments');
        $id = input_int('id', 0, 'post');
        if ($id > 0) {
            DB::update('comments', array('status' => 'trash'), array('id' => $id));
            blog_log('comment', 'comment.delete', 'success', array('comment_id' => $id));
            flash_set('success', admin_t('admin.comment.trashed'));
        }
        redirect(site_base_admin('comment/list'));
    }

    /** 回收站评论恢复（开启评论审核时恢复进 pending 复审队列，不得直接发布） */
    public static function restoreAction()
    {
        Auth::require_cap('manage_comments');
        $id = input_int('id', 0, 'post');
        if ($id > 0) {
            $status = Option::get('comment_audit', '0') === '1' ? 'pending' : 'published';
            DB::update('comments', array('status' => $status), array('id' => $id, 'status' => 'trash'));
            blog_log('comment', 'comment.restore', 'success', array('comment_id' => $id, 'status' => $status));
            flash_set('success', $status === 'pending' ? admin_t('admin.comment.restored_pending') : admin_t('admin.comment.restored'));
        }
        redirect(site_base_admin('comment/list&status=trash'));
    }
}
