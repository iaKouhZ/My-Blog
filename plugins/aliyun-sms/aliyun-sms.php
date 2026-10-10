<?php
/**
 * Plugin Name: Aliyun SMS
 * Description: 阿里云号码认证服务短信验证码（手写 HMAC-SHA1 RPC 签名，仅依赖 cURL）
 * Version: 1.0.0
 * Author: Blog Team
 * Requires: 1.0
 */
defined('APP_BOOT') or exit;

require_once dirname(__FILE__) . '/AliyunRpc.php';

/** 声明短信渠道验证码能力（内核入口探测/策略读取仅认声明，不硬编码插件名） */
register_verify_provider('sms');

add_filter('send_verify_code', 'aliyun_sms_send', 10, 5);
add_action('admin_menu', 'aliyun_sms_menu');

function aliyun_sms_menu()
{
    register_plugin_page('aliyun-sms', '阿里云短信设置', 'aliyun_sms_page');
}

/** 读取插件配置；密钥类字段统一去首尾空白，避免复制粘贴带入的空白破坏签名 */
function aliyun_sms_config()
{
    return array(
        'access_key_id'     => trim((string) plugin_option('aliyun-sms', 'access_key_id', '')),
        'access_key_secret' => trim((string) plugin_option('aliyun-sms', 'access_key_secret', '')),
        'sign_name'         => trim((string) plugin_option('aliyun-sms', 'sign_name', '')),
        // 默认使用系统赠送模板（搭配赠送签名）
        'template_code'     => trim((string) plugin_option('aliyun-sms', 'template_code', '100001')),
        'scheme_name'       => trim((string) plugin_option('aliyun-sms', 'scheme_name', '')),
        'valid_time'        => max(1, (int) plugin_option('aliyun-sms', 'valid_time', 10)),
        'interval'          => max(0, (int) plugin_option('aliyun-sms', 'interval', 60)),
    );
}

/** 构造 RPC 客户端；配置不完整返回 null */
function aliyun_sms_client($cfg = null)
{
    if ($cfg === null) {
        $cfg = aliyun_sms_config();
    }
    if ($cfg['access_key_id'] === '' || $cfg['access_key_secret'] === '') {
        return null;
    }
    // 仅支持本地生成码：仅依赖 cURL，不依赖阿里云云端核验
    return new AliyunRpc($cfg['access_key_id'], $cfg['access_key_secret'], 'https://dypnsapi.aliyuncs.com');
}

/**
 * 发送验证码（channel=sms）：SendSmsVerifyCode 本地生成码模式
 * 内核生成的验证码直接经 TemplateParam 下发；官方文档明确该模式下
 * 阿里云接口无法校验，故不挂 verify_code_check，核验由内核本地表接管
 */
function aliyun_sms_send($handled, $scene, $target, $channel, $code)
{
    if ($handled === true || $channel !== 'sms') {
        return $handled;
    }
    $cfg = aliyun_sms_config();
    $client = aliyun_sms_client($cfg);
    if ($client === null || $cfg['template_code'] === '') {
        plugin_log('aliyun-sms.send', array('result' => 'fail', 'reason' => 'not_configured'));
        return false;
    }

    $resp = $client->call('SendSmsVerifyCode', array(
        'PhoneNumber'   => $target,
        'CountryCode'   => '86',
        // 本地生成码模式：直接传具体验证码值，不传 ##code## 占位符，
        // 故无需 CodeType（仅占位符模式必填）；官方文档：TemplateParam 必填
        'ValidTime'     => $cfg['valid_time'] * 60,
        'Interval'      => $cfg['interval'],
        // 官方文档：仅支持系统赠送签名（需搭配赠送模板），不支持自定义签名
        'SignName'      => $cfg['sign_name'],
        'TemplateCode'  => $cfg['template_code'],
        'TemplateParam' => json_encode(array('code' => $code, 'min' => (string) $cfg['valid_time'])),
        // 官方文档：SchemeName 选填，不填则使用默认方案；空值由 AliyunRpc 自动剔除
        'SchemeName'    => $cfg['scheme_name'],
        'OutId'         => $scene,
    ));

    if ($resp === null) {
        plugin_log('aliyun-sms.send', array('result' => 'fail', 'reason' => 'network', 'scene' => $scene));
        return false;
    }
    $respCode = isset($resp['Code']) ? (string) $resp['Code'] : '';
    if ($respCode !== 'OK') {
        plugin_log('aliyun-sms.send', array('result' => 'fail', 'scene' => $scene, 'api_code' => $respCode));
        return false;
    }

    // 本地生成码模式：内核负责核验，写入可核验行（used=0），
    // 错误容忍次数/有效期/原子消费均由内核本地表机制接管
    DB::insert('verify_codes', array(
        'scene'      => $scene,
        'target'     => $target,
        'code'       => $code,
        'channel'    => 'sms',
        'expires_at' => date('Y-m-d H:i:s', time() + $cfg['valid_time'] * 60),
        'used'       => 0,
        'attempts'   => 0,
        'created_at' => now(),
    ));
    plugin_log('aliyun-sms.send', array('result' => 'success', 'scene' => $scene));
    return true;
}

/** 后台设置页 */
function aliyun_sms_page()
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        plugin_option_update('aliyun-sms', 'access_key_id', input_text('access_key_id', '', 64, 'post'));
        // AccessKeySecret 留空表示不修改（避免回显明文）；
        // 剔除全部空白：阿里云密钥不含空白字符，复制粘贴带入的空格/换行会直接导致 SignatureDoesNotMatch
        $secret = preg_replace('/\s+/', '', input_password('access_key_secret', '', 128));
        if ($secret !== '') {
            plugin_option_update('aliyun-sms', 'access_key_secret', $secret);
        }
        plugin_option_update('aliyun-sms', 'sign_name', input_text('sign_name', '', 32, 'post'));
        plugin_option_update('aliyun-sms', 'template_code', input_text('template_code', '', 64, 'post'));
        // 官方文档：SchemeName 选填（不填则使用默认方案），最多 20 字符，允许留空
        plugin_option_update('aliyun-sms', 'scheme_name', input_text('scheme_name', '', 20, 'post'));
        plugin_option_update('aliyun-sms', 'valid_time', max(1, min(60, input_int('valid_time', 10, 'post'))));
        plugin_option_update('aliyun-sms', 'interval', max(0, min(3600, input_int('interval', 60, 'post'))));
        // 短信验证码错误容忍次数：内核核验时读取本配置（范围 1-5 由内核钳制），错满即作废
        plugin_option_update('aliyun-sms', 'max_attempts', max(1, min(5, input_int('max_attempts', 2, 'post'))));
        plugin_log('aliyun-sms.config', array('result' => 'success'));
        echo '<p class="tip">配置已保存。</p>';
    }

    $cfg = aliyun_sms_config();
    echo '<form method="post">';
    echo Csrf::field();
    echo '<div class="form-row"><label>AccessKeyId</label>'
        . '<input type="text" name="access_key_id" value="' . e($cfg['access_key_id']) . '"></div>';
    echo '<div class="form-row"><label>AccessKeySecret（留空表示不修改）</label>'
        . '<input type="password" name="access_key_secret" autocomplete="new-password"></div>';
    echo '<div class="form-row"><label>短信签名 SignName（仅支持号码认证控制台“赠送签名”，需搭配赠送模板）</label>'
        . '<input type="text" name="sign_name" value="' . e($cfg['sign_name']) . '"></div>';
    echo '<div class="form-row"><label>模板 TemplateCode（赠送模板，默认 100001）</label>'
        . '<input type="text" name="template_code" value="' . e($cfg['template_code']) . '"></div>';
    echo '<div class="form-row"><label>方案名 SchemeName（≤20 字符，可留空；不填时使用阿里云默认方案）</label>'
        . '<input type="text" name="scheme_name" value="' . e($cfg['scheme_name']) . '"></div>';
    echo '<div class="form-row"><label>有效期（分钟）</label>'
        . '<input type="number" name="valid_time" min="1" max="60" value="' . (int) $cfg['valid_time'] . '"></div>';
    echo '<div class="form-row"><label>重发间隔（秒，云端侧）</label>'
        . '<input type="number" name="interval" min="0" max="3600" value="' . (int) $cfg['interval'] . '"></div>';
    echo '<div class="form-row"><label>验证码错误容忍次数（次，1-5，默认 2；错满即作废）</label>'
        . '<input type="number" name="max_attempts" min="1" max="5" value="'
        . max(1, min(5, (int) plugin_option('aliyun-sms', 'max_attempts', 2))) . '">'
        . '<div class="form-hint">本插件采用本地生成码模式，核验由内核本地表执行，此配置直接生效。</div></div>';
    echo '<button class="btn" type="submit">保存配置</button>';
    echo '</form>';
    echo '<p class="tip">AccessKeySecret 保存后不会再次显示，日志中不会出现明文密钥与验证码。</p>';
}
