<?php
/** 重写规则静态回归：直接读取示例中的拒绝表达式，不代替真实服务器配置检查。 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('APP_BOOT', true);
defined('APP_BOOT') or exit;
require __DIR__ . '/support.php';
$root = dirname(__DIR__, 2);
$blocked = array('/config.php', '/config.php.bak', '/CONFIG.PHP.OLD', '/config.php~',
    '/backup.sql.gz', '/uploads/old.log', '/uploads/image.PHTML', '/uploads/image.php/extra',
    '/core/Auth.php', '/CORE/Auth.PHP', '/themes/default/header.PHP', '/assets/LANGS/en_US.php',
    '/.git/config', '/uploads/.hidden/file');
$allowed = array('/index.php', '/user/index.php', '/install/index.php', '/qqlogin/bind',
    '/post/1.html', '/themes/default/style.css', '/assets/vendor/vditor/dist/index.min.js', '/uploads/2026/10/image.png');
foreach (array('.htaccess', 'nginx.conf.example', 'bt-panel.rewrite.conf') as $file) {
    TestSuite::run('deny rules cover backup and script variants while preserving resources: ' . $file, function () use ($root, $file, $blocked, $allowed) {
        $source = file_get_contents($root . '/' . $file);
        $rules = array();
        $nested = array();
        if ($file === '.htaccess') {
            preg_match_all('/^\s*RewriteRule (\S+) - \[(F,L(?:,NC)?)\]$/m', $source, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $rules[] = '#' . $match[1] . '#' . (strpos($match[2], 'NC') !== false ? 'i' : '');
            }
        } else {
            $indent = $file === 'nginx.conf.example' ? 4 : 0;
            preg_match_all('/^( *)location (~\*?) (\S+) \{ deny all; \}/m', $source, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                $pattern = '#' . $match[3] . '#' . ($match[2] === '~*' ? 'i' : '');
                if (strlen($match[1]) === $indent) {
                    $rules[] = $pattern;
                } else {
                    $nested[] = $pattern;
                }
            }
        }
        foreach (array(true => $blocked, false => $allowed) as $expected => $paths) {
            foreach ($paths as $path) {
                $subject = $file === '.htaccess' ? ltrim($path, '/') : $path;
                $patterns = strpos($path, '/uploads/') === 0 && $file !== '.htaccess' ? $nested : $rules;
                $denied = false;
                foreach ($patterns as $pattern) {
                    if (preg_match($pattern, $subject)) {
                        $denied = true;
                        break;
                    }
                }
                TestSuite::check($denied === (bool) $expected, 'path policy mismatch: ' . $path);
            }
        }
    });
}
exit(TestSuite::finish());
