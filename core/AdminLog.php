<?php
/**
 * 后台日志中心：筛选/分页/CSV 导出（仅管理员，能力点 view_logs）
 * 日志只增不改：此处不提供任何编辑/删除入口；查询与导出本身写入自审计
 */
defined('APP_BOOT') or exit;

class AdminLog
{
    /** 可筛选的事件类别 */
    private static function categories()
    {
        return array('all', 'auth', 'post', 'comment', 'user', 'setting', 'template', 'plugin', 'verify', 'security');
    }

    /** 按当前请求筛选条件构造查询（两次调用：count 与 select 分离） */
    private static function filtered()
    {
        $query = DB::query('logs');
        $category = input_enum('category', self::categories(), 'all', 'get');
        if ($category !== 'all') {
            $query->where('category', '=', $category);
        }
        $result = input_enum('result', array('all', 'success', 'fail'), 'all', 'get');
        if ($result !== 'all') {
            $query->where('result', '=', $result);
        }
        $keyword = input_text('keyword', '', 64, 'get');
        if ($keyword !== '') {
            $query->where('action', 'LIKE', '%' . str_replace(array('%', '_'), '', $keyword) . '%');
        }
        // 日期窗口：首次进入默认"一个月前 ~ 当天"（filter_date_range 统一处理）
        list($from, $to) = filter_date_range();
        if ($from !== '') {
            $query->where('created_at', '>=', $from . ' 00:00:00');
        }
        if ($to !== '') {
            $query->where('created_at', '<=', $to . ' 23:59:59');
        }
        return $query;
    }

    /** 日志列表 */
    public static function listAction()
    {
        Auth::require_cap('view_logs');
        $page = max(1, input_int('page', 1, 'get'));
        $perPage = 20;
        $total = self::filtered()->count();
        $totalPages = max(1, (int) ceil($total / $perPage));
        $logs = self::filtered()->orderBy('id', 'DESC')->limit($perPage, ($page - 1) * $perPage)->select();

        // 日志查询本身写一条自审计（日志访问保护）
        blog_log('security', 'log.view', 'success', array(
            'category' => input_enum('category', self::categories(), 'all', 'get'),
            'page'     => $page,
        ));

        list($fFrom, $fTo) = filter_date_range();
        Admin::render(admin_t('admin.menu.log'), 'log_list', array(
            'logs' => $logs, 'page' => $page, 'totalPages' => $totalPages, 'total' => $total,
            'categories' => self::categories(),
            'fCategory' => input_enum('category', self::categories(), 'all', 'get'),
            'fResult'   => input_enum('result', array('all', 'success', 'fail'), 'all', 'get'),
            'fKeyword'  => input_text('keyword', '', 64, 'get'),
            'fFrom'     => $fFrom,
            'fTo'       => $fTo,
        ));
    }

    /** CSV 导出（上限 5000 条；敏感操作须重验当前密码；导出行为本身写自审计） */
    public static function exportAction()
    {
        Auth::require_cap('view_logs');
        // 仅接受 POST：导出属敏感操作，需携带 CSRF token 与当前密码（内核已统一校验 CSRF）
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
            Admin::forbidden('export requires POST');
        }
        $password = input_password('current_password');
        if (!Auth::confirmCurrentPassword($password)) {
            blog_log('security', 'log.export', 'fail', array('reason' => 'password_wrong'));
            flash_set('error', admin_t('admin.log.export_pwd_wrong'));
            redirect(site_base_admin('log/list'));
        }
        list($expFrom, $expTo) = filter_date_range();
        blog_log('security', 'log.export', 'success', array(
            'category' => input_enum('category', self::categories(), 'all', 'get'),
            'from'     => $expFrom,
            'to'       => $expTo,
        ));

        $logs = self::filtered()->orderBy('id', 'DESC')->limit(5000)->select();
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="audit-logs-' . date('Ymd-His') . '.csv"');
        // UTF-8 BOM 便于 Excel 直接打开
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        fputcsv($out, array(
            'ID', admin_t('admin.log.col_time'), admin_t('admin.log.csv_user_id'),
            admin_t('admin.user.col_role'), admin_t('admin.log.col_category'), admin_t('admin.log.col_action'),
            admin_t('admin.log.col_result'), 'IP', 'UA', admin_t('admin.log.col_detail'),
        ));
        foreach ($logs as $log) {
            fputcsv($out, array(
                csv_safe($log['id']), csv_safe($log['created_at']), csv_safe($log['user_id']),
                csv_safe($log['role']), csv_safe($log['category']), csv_safe($log['action']),
                csv_safe($log['result']), csv_safe($log['ip']), csv_safe($log['ua']),
                csv_safe($log['detail']),
            ));
        }
        fclose($out);
        exit;
    }
}
