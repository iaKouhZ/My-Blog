<?php
/** IP 限流服务：独立临时记录与每桶互斥，升级时沿用旧计数窗口。 */
defined('APP_BOOT') or exit;

class RateLimiter
{
    // 非法插件 slug 留作内核命名空间，普通插件无法通过写入守卫认领。
    const OWNER = '__core_throttle';

    /** 按 IP 和业务桶裁决一分钟窗口；取锁失败一律拒绝。 */
    public static function allow($bucket, $maxPerMinute)
    {
        if (!is_string($bucket) || !preg_match('/^[a-z0-9_]{1,40}$/', $bucket)) {
            return false;
        }
        $key = $bucket . '_' . md5(throttle_ip_key(client_ip()));
        return self::take($key, $maxPerMinute, 60, true);
    }

    /** 按账号等可信身份限流；窗口独立于 IP，防止轮换地址耗尽存储。 */
    public static function allowFor($bucket, $identity, $max, $windowSeconds = 60)
    {
        if (!is_string($bucket) || !preg_match('/^[a-z0-9_]{1,40}$/', $bucket)
            || !is_string($identity) || $identity === '' || $windowSeconds < 1 || $windowSeconds > 86400) {
            return false;
        }
        $key = 'subject_' . md5($bucket . '|' . (int) $windowSeconds . '|' . $identity);
        return self::take($key, $max, (int) $windowSeconds, false);
    }

    /** 在锁内裁决；每日窗口的记录不得被一小时清理提前回收。 */
    private static function take($key, $max, $windowSeconds, $legacy)
    {
        $lock = 'throttle_' . md5($key);
        if (!DB::lock($lock, 3)) {
            return false;
        }
        try {
            $now = time();
            $where = array('plugin' => self::OWNER, 'scope' => 'global', 'user_id' => 0, 'data_key' => $key);
            $row = DB::query('plugin_data')->whereMap($where)->first();
            // 直接读库，不使用等锁前已加载的配置快照；旧计数只在首次迁入时读取。
            $stored = $row === null && $legacy
                ? DB::query('options')->where('option_key', '=', 'throttle_' . $key)->value('option_value')
                : ($row === null ? '' : $row['data_value']);
            $parts = explode('|', (string) $stored);
            $start = isset($parts[0]) ? (int) $parts[0] : 0;
            $count = isset($parts[1]) ? max(0, (int) $parts[1]) : 0;
            if ($start <= 0 || $start > $now || $now - $start >= $windowSeconds) {
                $start = $now;
                $count = 0;
            }
            // 已拒绝的窗口保持计数，避免高频请求持续产生写放大。
            if ($row !== null && $count > max(0, (int) $max)) {
                return false;
            }
            $count = min($count, PHP_INT_MAX - 1) + 1;
            DB::query('plugin_data')->upsert(array_merge($where, array(
                'data_value' => $start . '|' . $count,
                'expires_at' => date('Y-m-d H:i:s', $start + max(3600, $windowSeconds) + 60),
                'created_at' => now(),
                'updated_at' => now(),
            )), array('data_value', 'expires_at', 'updated_at'));
            return $count <= max(0, (int) $max);
        } finally {
            DB::unlock($lock);
        }
    }

    /** 回收旧版 options 临时记录；新计数由 plugin_data 的 expires_at 统一回收。 */
    public static function purgeLegacy()
    {
        if (time() - (int) Option::get('rate_limit_legacy_purge_at', 0) < 86400) {
            return;
        }
        try {
            $rows = DB::query('options')->where('option_key', 'LIKE', 'throttle\_%')
                ->select(array('option_key', 'option_value'));
            foreach ($rows as $row) {
                if ($row['option_key'] === 'throttle_last_purge') {
                    continue;
                }
                $parts = explode('|', $row['option_value']);
                if ((int) $parts[0] > 0 && (int) $parts[0] < time() - 3600) {
                    DB::delete('options', array('option_key' => $row['option_key']));
                }
            }
            Option::set('rate_limit_legacy_purge_at', time());
        } catch (Throwable $ex) {
            error_log('[blog] legacy throttle purge failed');
        }
    }
}
