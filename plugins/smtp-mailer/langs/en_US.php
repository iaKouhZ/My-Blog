<?php
/**
 * SMTP Mailer language pack (English US)
 */
defined('APP_BOOT') or exit;

return array(
    '_name'              => 'English (US)',
    'page_title'         => 'SMTP Mail Settings',
    'mail_subject'       => '[%s] Email Verification Code',
    'mail_body'          => "Your verification code is: %s\nValid for 10 minutes. Do not share it with anyone.",
    'saved'              => 'Settings saved.',
    'test_invalid_email' => 'Please enter a valid test recipient email address.',
    'test_need_config'   => 'Please complete the SMTP configuration first.',
    'test_subject'       => '[%s] Test Email',
    'test_body'          => 'This is a test email. Your SMTP configuration is working.',
    'test_sent'          => 'Test email sent, please check your inbox.',
    'test_failed'        => 'Send failed: %s',
    'label_host'         => 'SMTP Host',
    'label_port'         => 'Port',
    'label_encryption'   => 'Encryption',
    'enc_none'           => 'None',
    'label_account'      => 'Account (sender email)',
    'label_passcode'     => 'Authorization code (leave blank to keep unchanged)',
    'label_from_name'    => 'Sender name',
    'label_max_attempts' => 'Max verification attempts (1-5, default 2; code is voided when reached and must be resent)',
    'save'               => 'Save Settings',
    'test_placeholder'   => 'Test recipient email',
    'test_send'          => 'Send Test Email',
);
