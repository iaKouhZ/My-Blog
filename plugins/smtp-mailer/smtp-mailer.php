<?php
/**
 * Plugin Name: SMTP Mailer
 * Description: 通过 SMTP 发送邮箱验证码与测试邮件（纯 PHP 实现，零依赖）
 * Version: 1.0.0
 * Author: Blog Team
 * Requires: 1.0
 */
defined('APP_BOOT') or exit;

require_once dirname(__FILE__) . '/SmtpClient.php';

/** 声明邮箱渠道验证码能力（内核入口探测/策略读取仅认声明，不硬编码插件名） */
register_verify_provider('email');

/** 接管邮箱验证码发送（channel=email） */
add_filter('send_verify_code', 'smtp_mailer_send', 10, 5);

/** 注册后台设置页 */
add_action('admin_menu', 'smtp_mailer_menu');

function smtp_mailer_menu()
{
    register_plugin_page('smtp-mailer', plugin_t('smtp-mailer', 'page_title', array(), 'SMTP 发信设置'), 'smtp_mailer_page');
}

/**
 * 读取插件 SMTP 配置
 *
 * @return array
 */
function smtp_mailer_config()
{
    return array(
        'host'       => (string) plugin_option('smtp-mailer', 'host', ''),
        'port'       => (int) plugin_option('smtp-mailer', 'port', 465),
        'encryption' => (string) plugin_option('smtp-mailer', 'encryption', 'ssl'),
        'username'   => (string) plugin_option('smtp-mailer', 'username', ''),
        'password'   => (string) plugin_option('smtp-mailer', 'password', ''),
        'from_name'  => (string) plugin_option('smtp-mailer', 'from_name', site_name()),
    );
}

/**
 * 构造客户端；配置不完整时返回 null
 *
 * @return SmtpClient|null
 */
function smtp_mailer_client()
{
    $cfg = smtp_mailer_config();
    if ($cfg['host'] === '' || $cfg['username'] === '') {
        return null;
    }
    return new SmtpClient(
        $cfg['host'], $cfg['port'], $cfg['encryption'],
        $cfg['username'], $cfg['password'], $cfg['from_name']
    );
}

/**
 * send_verify_code 过滤器回调：写入 verify_codes 并发送验证码邮件
 *
 * @param mixed  $handled 上游是否已接管
 * @param string $scene   register/reset
 * @param string $target  邮箱
 * @param string $channel email/sms
 * @param string $code    内核生成的 6 位验证码
 * @return bool
 */
function smtp_mailer_send($handled, $scene, $target, $channel, $code)
{
    if ($handled === true || $channel !== 'email') {
        return $handled;
    }
    $client = smtp_mailer_client();
    if ($client === null) {
        plugin_log('smtp.send', array('result' => 'fail', 'reason' => 'not_configured'));
        return false;
    }

    // 外发邮件按站点默认语言（admin_locale），不随请求者浏览器语言
    $subject = plugin_t('smtp-mailer', 'mail_subject', array(site_name()));
    $body = plugin_t('smtp-mailer', 'mail_body', array($code));
    $ok = $client->send($target, $subject, $body);
    if (!$ok) {
        // 错误信息可能含服务器响应，截断后入库；绝不包含授权码与验证码
        plugin_log('smtp.send', array(
            'result' => 'fail', 'scene' => $scene,
            'error' => mb_substr($client->error(), 0, 120),
        ));
        return false;
    }

    DB::insert('verify_codes', array(
        'scene'      => $scene,
        'target'     => $target,
        'code'       => $code,
        'channel'    => 'email',
        'expires_at' => date('Y-m-d H:i:s', time() + 600),
        'used'       => 0,
        'attempts'   => 0,
        'created_at' => now(),
    ));
    plugin_log('smtp.send', array('result' => 'success', 'scene' => $scene));
    return true;
}

/** 后台设置页：配置保存 + 测试邮件 */
function smtp_mailer_page()
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $op = input_enum('op', array('save', 'test'), 'save', 'post');
        if ($op === 'save') {
            plugin_option_update('smtp-mailer', 'host', input_text('host', '', 128, 'post'));
            plugin_option_update('smtp-mailer', 'port', input_enum('port', array('25', '465', '587'), '465', 'post'));
            plugin_option_update('smtp-mailer', 'encryption', input_enum('encryption', array('none', 'ssl', 'tls'), 'ssl', 'post'));
            plugin_option_update('smtp-mailer', 'username', input_email('username', '', 'post'));
            // 授权码留空表示不修改（避免回显明文）
            $password = input_password('password');
            if ($password !== '') {
                plugin_option_update('smtp-mailer', 'password', $password);
            }
            plugin_option_update('smtp-mailer', 'from_name', input_text('from_name', '', 64, 'post'));
            // 邮箱验证码错误容忍次数：内核核验时读取本配置（范围 1-5 由内核钳制），错满即作废
            plugin_option_update('smtp-mailer', 'max_attempts', max(1, min(5, input_int('max_attempts', 2, 'post'))));
            plugin_log('smtp.config', array('result' => 'success'));
            echo '<p class="tip">' . e(plugin_t('smtp-mailer', 'saved')) . '</p>';
        } else {
            $to = input_email('test_to', '', 'post');
            if ($to === '') {
                echo '<p class="tip">' . e(plugin_t('smtp-mailer', 'test_invalid_email')) . '</p>';
            } else {
                $client = smtp_mailer_client();
                if ($client === null) {
                    echo '<p class="tip">' . e(plugin_t('smtp-mailer', 'test_need_config')) . '</p>';
                } else {
                    $ok = $client->send(
                        $to,
                        plugin_t('smtp-mailer', 'test_subject', array(site_name())),
                        plugin_t('smtp-mailer', 'test_body')
                    );
                    plugin_log('smtp.test', array('result' => $ok ? 'success' : 'fail'));
                    echo $ok
                        ? '<p class="tip">' . e(plugin_t('smtp-mailer', 'test_sent')) . '</p>'
                        : '<p class="tip">' . e(plugin_t('smtp-mailer', 'test_failed', array(mb_substr($client->error(), 0, 200)))) . '</p>';
                }
            }
        }
    }

    $cfg = smtp_mailer_config();
    echo '<form method="post">';
    echo Csrf::field();
    echo '<input type="hidden" name="op" value="save">';
    echo '<div class="form-row"><label>' . e(plugin_t('smtp-mailer', 'label_host')) . '</label>'
        . '<input type="text" name="host" value="' . e($cfg['host']) . '" placeholder="smtp.example.com"></div>';
    echo '<div class="form-row"><label>' . e(plugin_t('smtp-mailer', 'label_port')) . '</label><select name="port">';
    foreach (array('25', '465', '587') as $portOption) {
        $selected = (string) $cfg['port'] === $portOption ? ' selected' : '';
        echo '<option value="' . $portOption . '"' . $selected . '>' . $portOption . '</option>';
    }
    echo '</select></div>';
    echo '<div class="form-row"><label>' . e(plugin_t('smtp-mailer', 'label_encryption')) . '</label><select name="encryption">';
    $encNames = array('none' => plugin_t('smtp-mailer', 'enc_none'), 'ssl' => 'SSL', 'tls' => 'STARTTLS');
    foreach ($encNames as $encKey => $encName) {
        $selected = $cfg['encryption'] === $encKey ? ' selected' : '';
        echo '<option value="' . $encKey . '"' . $selected . '>' . e($encName) . '</option>';
    }
    echo '</select></div>';
    echo '<div class="form-row"><label>' . e(plugin_t('smtp-mailer', 'label_account')) . '</label>'
        . '<input type="email" name="username" value="' . e($cfg['username']) . '"></div>';
    echo '<div class="form-row"><label>' . e(plugin_t('smtp-mailer', 'label_passcode')) . '</label>'
        . '<input type="password" name="password" autocomplete="new-password"></div>';
    echo '<div class="form-row"><label>' . e(plugin_t('smtp-mailer', 'label_from_name')) . '</label>'
        . '<input type="text" name="from_name" value="' . e($cfg['from_name']) . '"></div>';
    echo '<div class="form-row"><label>' . e(plugin_t('smtp-mailer', 'label_max_attempts')) . '</label>'
        . '<input type="number" name="max_attempts" min="1" max="5" value="'
        . max(1, min(5, (int) plugin_option('smtp-mailer', 'max_attempts', 2))) . '"></div>';
    echo '<button class="btn" type="submit">' . e(plugin_t('smtp-mailer', 'save')) . '</button>';
    echo '</form>';

    echo '<hr style="margin:20px 0;border:none;border-top:1px solid #e5e5e5">';
    echo '<form method="post" class="form-inline">';
    echo Csrf::field();
    echo '<input type="hidden" name="op" value="test">';
    echo '<input type="email" name="test_to" placeholder="' . e(plugin_t('smtp-mailer', 'test_placeholder')) . '" required>';
    echo '<button class="btn gray" type="submit">' . e(plugin_t('smtp-mailer', 'test_send')) . '</button>';
    echo '</form>';
}
