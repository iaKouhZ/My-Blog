<?php
/** 文章读取服务：统一公开列表筛选、分页及当前页作者与分类数据。 */
defined('APP_BOOT') or exit;

class PostQuery
{
    /** 公开文章查询基线，首页、分类、作者、搜索共用。 */
    private static function published(array $where, $keyword)
    {
        $query = DB::query('posts')->where('status', '=', 'published')->where('is_page', '=', 0);
        foreach ($where as $condition) {
            $query->where($condition[0], $condition[1], $condition[2]);
        }
        if ($keyword !== '') {
            $query->likeAny(array('title', 'content', 'excerpt'), $keyword);
        }
        return $query;
    }

    /** 返回当前页及分页信息；复制同一查询条件，避免 count/select 漂移。 */
    public static function page($page, array $where = array(), $keyword = '')
    {
        $perPage = max(1, (int) Option::get('posts_per_page', 10));
        $query = self::published($where, $keyword);
        $total = $query->count();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, (int) $page), $totalPages);
        $posts = $query->orderBy('is_top', 'DESC')->orderBy('created_at', 'DESC')->orderBy('id', 'DESC')
            ->limit($perPage, ($page - 1) * $perPage)->select();
        self::attachAuthors($posts);
        return array('posts' => $posts, 'total' => $total, 'page' => $page, 'totalPages' => $totalPages);
    }

    /**
     * 注销用户前台展示匿名化：用户名/昵称统一显示“用户已注销”，头像置空
     * （模板侧 avatar_url 遇空头像自动回退默认头像）；数据不删除，仅展示层匿名
     *
     * @param array $author 用户行（需含 is_deleted）
     * @return array
     */
    public static function maskDeletedAuthor(array $author)
    {
        if (!empty($author['is_deleted'])) {
            $author['nickname'] = theme_t('theme.front.deleted_user', array(), '用户已注销');
            if (isset($author['username'])) {
                $author['username'] = theme_t('theme.front.deleted_user', array(), '用户已注销');
            }
            $author['avatar'] = '';
            if (isset($author['signature'])) {
                $author['signature'] = '';
            }
        }
        return $author;
    }

    /** 为文章列表附加作者信息 */
    private static function attachAuthors(array &$posts)
    {
        if (empty($posts)) {
            return;
        }
        $ids = array();
        foreach ($posts as $post) {
            $ids[] = (int) $post['author_id'];
        }
        $users = DB::query('users')->whereIn('id', array_unique($ids))
            ->select(array('id', 'username', 'nickname', 'avatar', 'is_deleted'));
        $map = array();
        foreach ($users as $u) {
            $map[(int) $u['id']] = self::maskDeletedAuthor($u);
        }
        // 分类名映射
        $categoryIds = array_values(array_unique(array_column($posts, 'category_id')));
        $cats = DB::query('categories')->whereIn('id', $categoryIds)->select(array('id', 'name', 'slug'));
        $catMap = array();
        foreach ($cats as $c) {
            $catMap[(int) $c['id']] = $c;
        }
        foreach ($posts as &$post) {
            $aid = (int) $post['author_id'];
            $post['author'] = isset($map[$aid]) ? $map[$aid] : array('nickname' => theme_t('theme.front.unknown_user', array(), '未知'), 'id' => 0, 'avatar' => '');
            $cid = (int) $post['category_id'];
            $post['category'] = isset($catMap[$cid]) ? $catMap[$cid] : null;
        }
        unset($post);
    }

}
