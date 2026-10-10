<?php
/**
 * SMTP Mailer 语言包（中文基线，必备）
 */
defined('APP_BOOT') or exit;

return array(
    '_name'              => '中文（简体）',
    'page_title'         => 'SMTP 发信设置',
    'mail_subject'       => '【%s】邮箱验证码',
    'mail_body'          => "您的验证码是：%s\n验证码 10 分钟内有效，请勿泄露给他人。",
    'saved'              => '配置已保存。',
    'test_invalid_email' => '请填写有效的测试收件邮箱。',
    'test_need_config'   => '请先完成 SMTP 配置。',
    'test_subject'       => '【%s】测试邮件',
    'test_body'          => '这是一封测试邮件，SMTP 配置正常。',
    'test_sent'          => '测试邮件已发送，请查收。',
    'test_failed'        => '发送失败：%s',
    'label_host'         => 'SMTP 主机',
    'label_port'         => '端口',
    'label_encryption'   => '加密方式',
    'enc_none'           => '无',
    'label_account'      => '账号（发件邮箱）',
    'label_passcode'     => '授权码（留空表示不修改）',
    'label_from_name'    => '发件人名称',
    'label_max_attempts' => '验证码错误容忍次数（次，1-5，默认 2；错满即作废，需重新发送）',
    'save'               => '保存配置',
    'test_placeholder'   => '测试收件邮箱',
    'test_send'          => '发送测试邮件',
);
