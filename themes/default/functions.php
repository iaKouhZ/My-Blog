<?php
/**
 * 默认主题自有钩子与助手函数
 */
defined('APP_BOOT') or exit;

// 主题在 init 之后加载，此处直接挂载页头/页尾输出点
add_action('front_head', 'default_theme_math_css');
add_action('front_footer', 'default_theme_render_js');

/**
 * 输出 KaTeX 样式（仅当本地化资源存在）
 */
function default_theme_math_css()
{
    $katexCss = Theme::dir() . '/../../assets/vendor/katex/katex.min.css';
    if (is_file($katexCss)) {
        echo '<link rel="stylesheet" href="' . e(assets_url('vendor/katex/katex.min.css')) . '">' . "\n";
    }
    $hljsCss = assets_url('vendor/highlight.js/default.min.css');
    if (is_file(Theme::dir() . '/../../assets/vendor/highlight.js/default.min.css')) {
        echo '<link rel="stylesheet" href="' . e($hljsCss) . '">' . "\n";
    }
}

/**
 * 输出公式/代码渲染脚本（仅当本地化资源存在）
 */
function default_theme_render_js()
{
    $vendor = Theme::dir() . '/../../assets/vendor';
    if (is_file($vendor . '/katex/katex.min.js')) {
        echo '<script src="' . e(assets_url('vendor/katex/katex.min.js')) . '"></script>' . "\n";
    }
    if (is_file($vendor . '/highlight.js/highlight.min.js')) {
        echo '<script src="' . e(assets_url('vendor/highlight.js/highlight.min.js')) . '"></script>' . "\n";
    }
    echo '<script src="' . e(Theme::assetsUrl('js/render.js')) . '"></script>' . "\n";
}

/**
 * 插件整页外壳：本主题的 header.php 自带侧边栏与 #main，
 * 页面模板只需开启 #content，由 footer.php 统一闭合
 *
 * @param string $innerHtml 插件正文 HTML（调用方已转义）
 * @return void
 */
function theme_plugin_page_shell($innerHtml)
{
    Theme::part('header');
    echo '<div id="content">' . "\n";
    echo $innerHtml;
    Theme::part('footer');
}
