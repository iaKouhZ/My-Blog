<?php
/** 文章写入服务：状态裁决、多表事务及提交后的插件通知。 */
defined('APP_BOOT') or exit;

class PostService
{
    /** 保存正文与导航为同一提交，钩子失败不再伪装为正文保存失败。 */
    public static function save($postId, array $data, $showInNav)
    {
        Auth::require_cap('edit_posts');
        if (!DB::lock('post_navigation', 5)) {
            blog_log('post', (int) $postId === 0 ? 'post.create' : 'post.update', 'fail', array('post_id' => (int) $postId, 'reason' => 'lock_timeout'));
            throw new PostWriteException('busy');
        }
        $isNew = (int) $postId === 0;
        try {
            $postId = DB::transaction(function () use ($postId, &$data, $showInNav, $isNew) {
                $old = $isNew ? null : DB::query('posts')->where('id', '=', (int) $postId)->forUpdate()->first();
                if (!$isNew && !$old) {
                    throw new PostWriteException('not_found');
                }
                if ($old && (int) $old['author_id'] !== Auth::id() && !Auth::check_cap('edit_others_posts')) {
                    throw new PostWriteException('forbidden');
                }
                if (Option::get('post_audit', '0') === '1' && !Auth::check_cap('moderate_posts')) {
                    // 已发布文章的文字修订沿用既有产品策略；新发布仍必须进入待审核。
                    if ($old && $old['status'] === 'published') {
                        $data['status'] = 'published';
                    } elseif ($data['status'] === 'published') {
                        $data['status'] = 'pending';
                    }
                }
                if ($isNew) {
                    $data['author_id'] = Auth::id();
                    $data['views'] = 0;
                    $data['created_at'] = now();
                    $postId = DB::insert('posts', $data);
                } else {
                    DB::update('posts', $data, array('id' => (int) $postId));
                }
                // 等锁前的 Option 缓存可能已过期，这里直接读取最新导航。
                $raw = DB::query('options')->where('option_key', '=', 'nav_page_ids')->value('option_value');
                $nav = json_decode((string) $raw, true);
                $nav = is_array($nav) ? array_map('intval', $nav) : array();
                $nav = array_values(array_diff($nav, array((int) $postId)));
                if (!empty($data['is_page']) && $showInNav) {
                    $nav[] = (int) $postId;
                }
                Option::set('nav_page_ids', array_values(array_unique($nav)));
                return (int) $postId;
            });
        } catch (Throwable $ex) {
            Option::resetCache();
            blog_log('post', $isNew ? 'post.create' : 'post.update', 'fail', array('post_id' => (int) $postId, 'reason' => 'write_failed'));
            throw $ex;
        } finally {
            DB::unlock('post_navigation');
        }
        blog_log('post', $isNew ? 'post.create' : 'post.update', 'success', array(
            'post_id' => $postId, 'title' => $data['title'], 'status' => $data['status'],
        ));
        self::notify('post_saved', array($postId, $data, $isNew), $postId);
        return $postId;
    }

    /** 仅彻底删除回收站文章；文章、评论、导航同时成功或同时回滚。 */
    public static function destroy($postId)
    {
        Auth::require_cap('delete_posts');
        if (!DB::lock('post_navigation', 5)) {
            blog_log('post', 'post.destroy', 'fail', array('post_id' => (int) $postId, 'reason' => 'lock_timeout'));
            throw new PostWriteException('busy');
        }
        try {
            $deleted = DB::transaction(function () use ($postId) {
                $post = DB::query('posts')->where('id', '=', (int) $postId)
                    ->where('status', '=', 'trash')->forUpdate()->first();
                if (!$post) {
                    return false;
                }
                DB::delete('comments', array('post_id' => (int) $postId));
                DB::delete('posts', array('id' => (int) $postId));
                $raw = DB::query('options')->where('option_key', '=', 'nav_page_ids')->value('option_value');
                $nav = json_decode((string) $raw, true);
                if (is_array($nav)) {
                    Option::set('nav_page_ids', array_values(array_diff(array_map('intval', $nav), array((int) $postId))));
                }
                return true;
            });
        } catch (Throwable $ex) {
            Option::resetCache();
            blog_log('post', 'post.destroy', 'fail', array('post_id' => (int) $postId, 'reason' => 'write_failed'));
            throw $ex;
        } finally {
            DB::unlock('post_navigation');
        }
        if ($deleted) {
            blog_log('post', 'post.destroy', 'success', array('post_id' => (int) $postId));
            self::notify('post_deleted', array((int) $postId), (int) $postId);
        } else {
            blog_log('post', 'post.destroy', 'fail', array('post_id' => (int) $postId, 'reason' => 'not_in_trash'));
        }
        return $deleted;
    }

    /** 外部副作用只能发生在提交后，失败另外记审计，不能回滚已提交业务。 */
    private static function notify($hook, array $args, $postId)
    {
        try {
            call_user_func_array('do_action', array_merge(array($hook), $args));
        } catch (Throwable $ex) {
            blog_log('post', 'post.hook_failed', 'fail', array('post_id' => $postId, 'hook' => $hook, 'reason' => 'callback_exception'));
        }
    }
}

/** 文章业务拒绝使用机器码，不暴露数据库诊断。 */
class PostWriteException extends RuntimeException
{
}
