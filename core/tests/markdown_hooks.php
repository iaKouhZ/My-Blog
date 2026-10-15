<?php
/** Markdown 边界与插件执行上下文回归；仅使用内存，不连接站点数据库。 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('APP_BOOT', true);
defined('APP_BOOT') or exit;
require __DIR__ . '/support.php';
require dirname(__DIR__) . '/Utils.php';
require dirname(__DIR__) . '/Hook.php';
require dirname(__DIR__) . '/Plugin.php';
require dirname(__DIR__) . '/Markdown.php';

$examples = array(
    array('basic callout', "> [!note]\n> hello", 'class="callout callout-note"'),
    array('callout alias', "> [!caution]\n> hello", 'class="callout callout-warning"'),
    array('unknown type fallback', "> [!unknown]\n> hello", 'class="callout callout-note"'),
    array('formatted title', "> [!tip] **Title**\n> hello", '<strong>Title</strong>'),
    array('callout list', "> [!note]\n> - first\n> - second", '<ul><li>first</li><li>second</li></ul>'),
    array('callout table', "> [!note]\n> | A | B |\n> | - | - |\n> | 1 | 2 |", '<table>'),
    array('code escaping', "> [!note]\n> ```text\n> a < b\n> ```", '<pre><code class="language-text">a &lt; b</code></pre>'),
    array('inline math', '> [!note]' . "\n" . '> Value $x+1$ here', '<span class="tex-inline">x+1</span>'),
    array('nested callout', "> [!note]\n> > [!tip]\n> > inside", 'class="callout callout-tip"'),
    array('literal title HTML', '> [!note] <img src=x onerror=alert(1)>', '&lt;img src=x onerror=alert(1)&gt;'),
    array('code dollars preserved', '> [!note]' . "\n> ```php\n> " . '$price = $value;' . "\n> ```", '<pre><code class="language-php">$price = $value;</code></pre>'),
    array('code block formula preserved', '> [!note]' . "\n> ```text\n> " . '$$x+1$$' . "\n> ```", '<pre><code class="language-text">$$x+1$$</code></pre>'),
    array('inline code dollars preserved', '`$x$`', '<code>$x$</code>'),
    array('unclosed code fence', "```php\n" . '$a = $b;', '<pre><code class="language-php">$a = $b;</code></pre>'),
    array('literal old placeholders', '@@INLINECODE0@@ @@MATHBLOCK0@@', '@@INLINECODE0@@ @@MATHBLOCK0@@'),
);
foreach ($examples as $example) {
    TestSuite::run($example[0], function () use ($example) {
        TestSuite::check(strpos(Markdown::render($example[1]), $example[2]) !== false);
    });
}
TestSuite::run('block math remains in nested callout', function () {
    $html = Markdown::render('> [!note]' . "\n> > [!tip]\n> > " . '$$' . "\n> > x+1\n> > " . '$$');
    $doc = new DOMDocument();
    $doc->loadHTML('<?xml encoding="utf-8"?>' . $html);
    $xpath = new DOMXPath($doc);
    $nodes = $xpath->query('//div[@class="callout callout-tip"]/div[@class="callout-content"]/div[@class="tex-block"]');
    TestSuite::check($nodes->length === 1);
    TestSuite::check(trim($nodes->item(0)->textContent) === 'x+1');
});
TestSuite::run('adjacent callouts stay separate', function () {
    $html = Markdown::render("> [!note]\n> first\n\n> [!warning]\n> second");
    TestSuite::check(substr_count($html, 'class="callout callout-') === 2);
});
TestSuite::run('dangerous URLs and HTML are removed', function () {
    $html = Markdown::render('> [!note]' . "\n" . '> [click](javascript:alert)');
    TestSuite::check(strpos($html, 'javascript:') === false);
    TestSuite::check(strpos(Markdown::sanitize('<script>alert(1)</script><img src=x onerror=alert(1)>'), 'onerror') === false);
});
TestSuite::run('type registry is normalized and evaluated once per render', function () {
    $calls = 0;
    add_filter('markdown_callout_types', function ($types) use (&$calls) {
        $calls++;
        $types['CUSTOM'] = array(array('extra'), 'theme.callout.custom', 'Custom');
        return $types;
    });
    $html = Markdown::render("> [!custom]\n> > [!extra]\n> > body");
    TestSuite::check($calls === 1);
    TestSuite::check(substr_count($html, 'class="callout callout-custom"') === 2);
});
TestSuite::run('deep nesting is bounded', function () {
    $lines = array();
    for ($i = 1; $i <= 80; $i++) {
        $lines[] = str_repeat('> ', $i) . '[!note]';
    }
    TestSuite::check(substr_count(Markdown::render(implode("\n", $lines)), 'class="callout callout-note"') <= 64);
});
foreach (array('action', 'filter') as $kind) {
    TestSuite::run($kind . ' exception restores nested owner', function () use ($kind) {
        Plugin::setCurrentSlug('test-owner');
        $hook = 'test_exception_' . $kind;
        add_filter($hook, function () {
            TestSuite::check(PluginContext::current() === 'test-owner');
            throw new RuntimeException('expected');
        });
        Plugin::setCurrentSlug('outer-owner');
        try {
            if ($kind === 'action') {
                do_action($hook);
            } else {
                apply_filters($hook, false);
            }
        } catch (RuntimeException $ex) {
            TestSuite::check($ex->getMessage() === 'expected');
        }
        TestSuite::check(PluginContext::current() === 'outer-owner');
        Plugin::setCurrentSlug(null);
    });
}
exit(TestSuite::finish());
