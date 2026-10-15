<?php
/** 主题公共数据提供器：作者卡片与导航查询，独立于模板渲染和语言选择。 */
defined('APP_BOOT') or exit;

class ThemeData
{
    /** 主题公共数据按请求缓存，模板助手只读取服务结果。 */
    public static function author()
    {
        static $author = null;
        if ($author === false) {
            return null;
        }
        if ($author !== null) {
            return $author;
        }
        $author = DB::query('users')
            ->where('role', '=', 'admin')
            ->where('status', '=', 1)
            ->orderBy('id', 'ASC')
            ->first(array('id', 'username', 'nickname', 'avatar', 'signature'));
        if ($author === null) {
            $author = false;
            return null;
        }
        return $author;
    }

    /** 主题公共数据按请求缓存，模板助手只读取服务结果。 */
    public static function pages()
    {
        static $pages = null;
        if ($pages !== null) {
            return $pages;
        }
        $navIds = array();
        foreach (Option::getJson('nav_page_ids', array()) as $navId) {
            $navIds[] = (int) $navId;
        }
        $navIds = array_values(array_unique($navIds));
        if (empty($navIds)) {
            $pages = array();
            return $pages;
        }
        $pages = DB::query('posts')
            ->where('is_page', '=', 1)
            ->where('status', '=', 'published')
            ->whereIn('id', $navIds)
            ->orderBy('created_at', 'ASC')
            ->select(array('id', 'title', 'slug'));
        return $pages;
    }

    /** 主题公共数据按请求缓存，模板助手只读取服务结果。 */
    public static function categories()
    {
        static $list = null;
        if ($list !== null) {
            return $list;
        }
        $list = DB::query('categories')
            ->orderBy('sort', 'ASC')
            ->select(array('id', 'name', 'slug'));
        // 附带已发布文章数（一次聚合查询）
        $counts = DB::query('posts')->where('status', '=', 'published')->where('is_page', '=', 0)->countsBy('category_id');
        foreach ($list as &$cat) {
            $cat['count'] = isset($counts[(int) $cat['id']]) ? $counts[(int) $cat['id']] : 0;
        }
        unset($cat);
        return $list;
    }

}
