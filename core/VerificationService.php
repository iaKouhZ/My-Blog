<?php
/** 验证码业务服务：前后台共用配额裁决、渠道发送、记录与一次性核验。 */
defined('APP_BOOT') or exit;

class VerificationService
{
    /** 已校验的业务参数经此发送；只返回机器码，界面文案由控制器决定。 */
    public static function send($scene, $target, $channel)
    {
        $result = 'unavailable';
        $reason = 'provider_failed';
        if (!in_array($scene, array('register', 'reset', 'profile'), true)
            || !in_array($channel, array('email', 'sms'), true) || $target === '') {
            $reason = 'invalid_parameters';
        } elseif (get_verify_provider($channel) === null) {
            $result = 'channel_off';
            $reason = 'channel_unavailable';
        } else {
            $lockName = self::targetLock($channel, $target);
            if (!DB::lock($lockName, 5)) {
                $result = 'throttled';
                $reason = 'lock_timeout';
            } else {
                try {
                    list($result, $reason) = self::sendLocked($scene, $target, $channel);
                } catch (Throwable $ex) {
                    // 插件异常可能含敏感参数，只记录受控的原因码。
                    $reason = 'provider_exception';
                } finally {
                    DB::unlock($lockName);
                }
            }
        }
        blog_log('verify', 'verify.send', $result === 'sent' ? 'success' : 'fail', array(
            'scene' => $scene, 'channel' => $channel, 'target' => $target, 'reason' => $reason,
        ));
        return $result;
    }

    /** 找回发送和提交共用渠道选择，避免两条路径各自演进后失配。 */
    public static function resetDestination(array $user, $account)
    {
        if (strpos($account, '@') === false && !empty($user['phone']) && get_verify_provider('sms') !== null) {
            return array('channel' => 'sms', 'target' => $user['phone']);
        }
        return array('channel' => 'email', 'target' => (string) $user['email']);
    }

    /** 渠道插件发送成功后登记本地验证码；只有声明该渠道的插件可以调用。 */
    public static function recordSent($scene, $target, $code, $channel, $ttl = 600)
    {
        if (get_verify_provider($channel) === null || PluginContext::current() !== get_verify_provider($channel)
            || !in_array($scene, array('register', 'reset', 'profile'), true)
            || !is_string($code) || !preg_match('/^[0-9]{6}$/', $code)) {
            throw new InvalidArgumentException('invalid verification record owner or parameters');
        }
        DB::insert('verify_codes', array(
            'scene' => $scene, 'target' => $target, 'code' => $code, 'channel' => $channel,
            'expires_at' => date('Y-m-d H:i:s', time() + max(1, min(3600, (int) $ttl))),
            'used' => 0, 'attempts' => 0, 'created_at' => now(),
        ));
    }

    /** 同目标发送与消费共用锁，邮箱大小写必须归一。 */
    private static function targetLock($channel, $target)
    {
        return 'vsend_' . md5($channel . '|' . strtolower($target));
    }

    /** 已持目标锁，配额检查与插件发送、登记在同一临界区完成。 */
    private static function sendLocked($scene, $target, $channel)
    {
        if (DB::query('verify_codes')->where('scene', '=', $scene)->where('target', '=', $target)
            ->where('created_at', '>', date('Y-m-d H:i:s', time() - 60))->count() > 0) {
            return array('retry', 'resend_interval');
        }
        if (DB::query('verify_codes')->where('target', '=', $target)
            ->where('created_at', '>=', date('Y-m-d 00:00:00'))->count() >= 10) {
            return array('daily_limit', 'daily_limit');
        }
        $code = (string) random_int(100000, 999999);
        $sent = apply_filters('send_verify_code', false, $scene, $target, $channel, $code);
        return $sent === true ? array('sent', '') : array('unavailable', 'provider_failed');
    }

    /** 核验并一次性消费，保留原有插件过滤器及最新码裁决契约。 */
    public static function consume($scene, $target, $code, $channel)
    {
        try {
            $pluginResult = apply_filters('verify_code_check', null, $scene, $target, $code, $channel);
            $ok = $pluginResult !== null ? (bool) $pluginResult : self::localConsumeCode($scene, $target, $code, $channel);
        } catch (Throwable $ex) {
            $ok = false;
        }
        // 核验行为留痕（等保审计；target 由 Logger 自动脱敏）
        blog_log('verify', 'verify.check', $ok ? 'success' : 'fail', array(
            'scene' => $scene, 'channel' => $channel, 'target' => $target,
        ));
        return $ok;
    }

    /** 本地 verify_codes 表核验 + 原子标记已用 */
    private static function localConsumeCode($scene, $target, $code, $channel)
    {
        // 错误容忍次数归声明者插件管理（按渠道声明取 slug，不硬编码插件名）；
        // 内核仅负责范围钳制（1-5），无声明者取缺省 2
        $provider = get_verify_provider($channel);
        $maxAttempts = $provider !== null
            ? max(1, min(5, (int) plugin_option($provider, 'max_attempts', 2)))
            : 2;
        // 与发送共用目标锁：错误计数、消费与重发互斥，不能靠读取后写回抵抗并发猜码。
        $lockName = self::targetLock($channel, $target);
        if (!DB::lock($lockName, 5)) {
            return false;
        }
        try {
            // 必须裁决最新一条；过滤 used=0 会在新码消费后重新暴露仍未过期的旧码。
            $row = DB::query('verify_codes')
                ->where('scene', '=', $scene)
                ->where('target', '=', $target)
                ->where('channel', '=', $channel)
                ->orderBy('id', 'DESC')
                ->first();
            if (!$row || (int) $row['used'] !== 0
                || strtotime($row['expires_at']) <= time()
                || (int) $row['attempts'] >= $maxAttempts
            ) {
                return false;
            }
            if (!hash_equals((string) $row['code'], (string) $code)) {
                $fail = DB::query('verify_codes')
                    ->where('id', '=', (int) $row['id'])
                    ->where('used', '=', 0)
                    ->increment('attempts');
                if ($fail !== false && $fail >= $maxAttempts) {
                    DB::query('verify_codes')->where('id', '=', (int) $row['id'])
                        ->where('used', '=', 0)->update(array('used' => 1));
                }
                return false;
            }
            // 除一次性标记外，提交时复检有效期与尝试次数，避免等待期间越过边界。
            $affected = DB::query('verify_codes')
                ->where('id', '=', (int) $row['id'])
                ->where('used', '=', 0)
                ->where('attempts', '<', $maxAttempts)
                ->where('expires_at', '>', now())
                ->update(array('used' => 1));
            return $affected > 0;
        } finally {
            DB::unlock($lockName);
        }
    }

}
