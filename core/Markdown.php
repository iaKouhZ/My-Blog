<?php
/**
 * Markdown→HTML 轻量解析 + 基于 DOMDocument 的标签/属性白名单 XSS 过滤
 * 数学公式 $...$/$$...$$ 转为占位容器交由前端本地化 KaTeX 渲染
 */
defined('APP_BOOT') or exit;

class Markdown
{
    /** @var array 公式暂存 */
    private $mathBlocks = array();

    /** @var string 每次渲染独立的占位前缀，避免正文伪造内部占位 */
    private $mathPrefix;

    /** @var array|null 同次渲染只构造一次类型表，嵌套块沿用同一声明 */
    private $calloutTypes = null;

    /**
     * 渲染入口：Markdown 原文 → 过滤后的安全 HTML
     *
     * @param string $markdown 原文
     * @return string
     */
    public static function render($markdown)
    {
        $instance = new self();
        return $instance->parse((string) $markdown);
    }

    /**
     * 解析主流程
     */
    private function parse($markdown)
    {
        $text = str_replace(array("\r\n", "\r"), "\n", $markdown);

        $this->mathPrefix = '@@CBMATH' . bin2hex(random_bytes(8)) . '_';
        // 引用前缀必须先由所属块剥离，代码与公式随后在同一层级识别。
        // 最终过滤放在所有占位还原之后，保证 HTML 安全边界只有一个出口。
        return self::sanitize($this->restoreMath($this->parseBlocks($text)));
    }

    /** 行内公式暂存 */
    private function stashInlineMath($m)
    {
        $this->mathBlocks[] = array('tex' => $m[1]);
        $index = count($this->mathBlocks) - 1;
        return $this->mathPrefix . $index . '@@';
    }

    /**
     * 块级结构解析（按行扫描）
     */
    private function parseBlocks($text, $depth = 0)
    {
        if ($depth >= 64) {
            return '<p>' . e($text) . '</p>';
        }
        $lines = explode("\n", $text);
        $html = '';
        $paragraph = array();
        $listStack = array();
        $inQuote = false;
        $quoteBuf = array();
        $tableBuf = array();

        $flushParagraph = function () use (&$paragraph, &$html) {
            if (!empty($paragraph)) {
                $html .= '<p>' . $this->parseInline(implode("\n", $paragraph)) . '</p>';
                $paragraph = array();
            }
        };
        $flushQuote = function () use (&$inQuote, &$quoteBuf, &$html, $depth) {
            if ($inQuote) {
                $html .= '<blockquote>' . $this->parseBlocks(implode("\n", $quoteBuf), $depth + 1) . '</blockquote>';
                $inQuote = false;
                $quoteBuf = array();
            }
        };
        $closeLists = function () use (&$listStack, &$html) {
            while (!empty($listStack)) {
                $html .= array_pop($listStack) === 'ul' ? '</li></ul>' : '</li></ol>';
            }
        };

        $total = count($lines);
        for ($i = 0; $i < $total; $i++) {
            $line = $lines[$i];

            // 代码围栏先于其它块判定，其内容不再进入公式、Callout 或行内解析。
            if (preg_match('/^```([^`]*)$/', $line, $fence)) {
                $flushQuote();
                $flushParagraph();
                $closeLists();
                $code = array();
                while (++$i < $total && !preg_match('/^```\s*$/', $lines[$i])) {
                    $code[] = $lines[$i];
                }
                $html .= $this->renderCodeBlock(trim($fence[1]), implode("\n", $code));
                continue;
            }

            // 块级公式只消费当前层级的行，不跨越外层引用或 Callout 的边界。
            if (preg_match('/^\s*\$\$(.*)$/', $line, $mathOpen)) {
                $math = $mathOpen[1];
                $end = $i;
                $closed = strpos($math, '$$') !== false;
                while (!$closed && $end + 1 < $total) {
                    $math .= "\n" . $lines[++$end];
                    $closed = strpos($math, '$$') !== false;
                }
                if ($closed) {
                    $flushQuote();
                    $flushParagraph();
                    $closeLists();
                    $parts = explode('$$', $math, 2);
                    $html .= '<div class="tex-block">' . e($parts[0]) . '</div>';
                    $i = $end;
                    if (trim($parts[1]) !== '') {
                        $paragraph[] = $parts[1];
                    }
                    continue;
                }
            }

            // Obsidian callout（> [!type] 标题）：须早于普通引用判定，
            // 否则会被并进上方 blockquote 的缓冲；命中后一次性吃掉整块引用行
            $callout = $this->parseCalloutOpen($line);
            if ($callout !== null) {
                $flushQuote();
                $flushParagraph();
                $closeLists();
                $body = $this->collectCalloutBody($lines, $i);
                $html .= $this->renderCallout($callout['type'], $callout['title'], $body['lines'], $callout['meta'], $depth);
                $i = $body['next'] - 1;
                continue;
            }

            // 表格（当前行含 | 且下一行为分隔行）
            if (strpos($line, '|') !== false && isset($lines[$i + 1])
                && preg_match('/^\s*\|?[\s:|-]+\|?\s*$/', $lines[$i + 1])
                && strpos($lines[$i + 1], '-') !== false) {
                $flushQuote();
                $flushParagraph();
                $closeLists();
                $tableBuf = array($line);
                $i++;
                while (isset($lines[$i + 1]) && strpos($lines[$i + 1], '|') !== false) {
                    $i++;
                    $tableBuf[] = $lines[$i];
                }
                $html .= $this->parseTable($tableBuf);
                continue;
            }

            // 引用
            if (preg_match('/^>\s?(.*)$/', $line, $m)) {
                $flushParagraph();
                $closeLists();
                $inQuote = true;
                $quoteBuf[] = $m[1];
                continue;
            }
            $flushQuote();

            // 标题
            if (preg_match('/^(#{1,6})\s+(.+)$/', $line, $m)) {
                $flushQuote();
                $flushParagraph();
                $closeLists();
                $level = strlen($m[1]);
                $html .= '<h' . $level . '>' . $this->parseInline($m[2]) . '</h' . $level . '>';
                continue;
            }

            // 分隔线
            if (preg_match('/^\s*(-{3,}|\*{3,}|_{3,})\s*$/', $line)) {
                $flushQuote();
                $flushParagraph();
                $closeLists();
                $html .= '<hr>';
                continue;
            }

            // 无序列表
            if (preg_match('/^\s*[-*+]\s+(.+)$/', $line, $m)) {
                $flushQuote();
                $flushParagraph();
                if (empty($listStack) || end($listStack) !== 'ul') {
                    $closeLists();
                    $html .= '<ul><li>';
                    $listStack[] = 'ul';
                } else {
                    $html .= '</li><li>';
                }
                $html .= $this->parseInline($m[1]);
                continue;
            }

            // 有序列表
            if (preg_match('/^\s*\d+\.\s+(.+)$/', $line, $m)) {
                $flushQuote();
                $flushParagraph();
                if (empty($listStack) || end($listStack) !== 'ol') {
                    $closeLists();
                    $html .= '<ol><li>';
                    $listStack[] = 'ol';
                } else {
                    $html .= '</li><li>';
                }
                $html .= $this->parseInline($m[1]);
                continue;
            }
            $closeLists();

            // 空行分段
            if (trim($line) === '') {
                $flushParagraph();
                continue;
            }

            $paragraph[] = $line;
        }

        $flushParagraph();
        $flushQuote();
        $closeLists();

        return $html;
    }

    /**
     * 行内语法解析（先整体转义再替换安全标签）
     */
    private function parseInline($text)
    {
        // 行内代码优先保护
        $codes = array();
        $codePrefix = '@@CBINLINE' . bin2hex(random_bytes(8)) . '_';
        $text = preg_replace_callback('/`([^`]+)`/', function ($m) use (&$codes, $codePrefix) {
            $codes[] = '<code>' . e($m[1]) . '</code>';
            return $codePrefix . (count($codes) - 1) . '@@';
        }, $text);

        $text = preg_replace_callback('/(?<![\$\\\\])\$([^\$\n]+?)\$(?!\$)/', array($this, 'stashInlineMath'), $text);
        $text = e($text);

        // 图片
        $text = preg_replace_callback('/!\[([^\]]*)\]\(([^)\s]+)\)/', array($this, 'renderImage'), $text);
        // 链接
        $text = preg_replace_callback('/\[([^\]]+)\]\(([^)\s]+)\)/', array($this, 'renderLink'), $text);
        // 粗体/斜体/删除线
        $text = preg_replace('/\*\*([^\*]+)\*\*/', '<strong>$1</strong>', $text);
        $text = preg_replace('/__([^_]+)__/', '<strong>$1</strong>', $text);
        $text = preg_replace('/\*([^\*]+)\*/', '<em>$1</em>', $text);
        $text = preg_replace('/~~([^~]+)~~/', '<del>$1</del>', $text);
        // 换行
        $text = str_replace("\n", '<br>', $text);

        // 还原行内代码
        $text = preg_replace_callback('/' . preg_quote($codePrefix, '/') . '(\d+)@@/', function ($m) use ($codes) {
            return $codes[(int) $m[1]];
        }, $text);

        return $text;
    }

    /** 图片渲染（URL 协议校验） */
    private function renderImage($m)
    {
        $url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
        if (!$this->isSafeUrl($url)) {
            return $m[1];
        }
        return '<img src="' . e($url) . '" alt="' . e($m[1]) . '">';
    }

    /** 链接渲染（URL 协议校验） */
    private function renderLink($m)
    {
        $url = html_entity_decode($m[2], ENT_QUOTES, 'UTF-8');
        if (!$this->isSafeUrl($url)) {
            return $m[1];
        }
        return '<a href="' . e($url) . '" rel="noopener">' . $m[1] . '</a>';
    }

    /** URL 安全校验（委托静态版，保持单一判定逻辑） */
    private function isSafeUrl($url)
    {
        return self::isSafeUrlStatic($url);
    }

    /** 围栏代码块渲染 */
    private function renderCodeBlock($lang, $code)
    {
        $cls = $lang !== '' ? ' class="language-' . e($lang) . '"' : '';
        return '<pre><code' . $cls . '>' . e(rtrim($code, "\n")) . '</code></pre>';
    }

    /**
     * Callout 类型表：规范类型 => array(别名数组, 语言键, 中文缺省标题)
     *
     * 类型标识符最终会写进 class 属性，因此一律经本表白名单映射：
     * 未注册类型降级为 note；插件可经 markdown_callout_types 过滤器扩展，
     * 非法标识符（不匹配 ^[a-z][a-z0-9-]*$）直接丢弃，防止 class 注入
     *
     * @return array array(别名 => 规范类型, 规范类型 => 元信息)
     */
    private function calloutTypes()
    {
        if ($this->calloutTypes !== null) {
            return $this->calloutTypes;
        }
        $defs = array(
            'note' => array(array(), 'theme.callout.note', '笔记'),
            'abstract' => array(array('summary', 'tldr'), 'theme.callout.abstract', '摘要'),
            'info' => array(array(), 'theme.callout.info', '信息'),
            'todo' => array(array(), 'theme.callout.todo', '待办'),
            'tip' => array(array('hint', 'important'), 'theme.callout.tip', '提示'),
            'success' => array(array('check', 'done'), 'theme.callout.success', '成功'),
            'question' => array(array('help', 'faq'), 'theme.callout.question', '问题'),
            'warning' => array(array('caution', 'attention'), 'theme.callout.warning', '注意'),
            'failure' => array(array('fail', 'missing'), 'theme.callout.failure', '失败'),
            'danger' => array(array('error'), 'theme.callout.danger', '危险'),
            'bug' => array(array(), 'theme.callout.bug', '缺陷'),
            'example' => array(array(), 'theme.callout.example', '示例'),
            'quote' => array(array('cite'), 'theme.callout.quote', '引述'),
        );
        // 兜底类型元信息：过滤器可能删掉 note，未识别类型仍需确定的降级目标
        $note = $defs['note'];

        $filtered = apply_filters('markdown_callout_types', $defs);
        $alias = array();
        $meta = array();
        if (is_array($filtered)) {
            foreach ($filtered as $canonical => $def) {
                if (!is_array($def) || !self::isCalloutTypeName($canonical)) {
                    continue;
                }
                $canonical = strtolower($canonical);
                $names = isset($def[0]) && is_array($def[0]) ? $def[0] : array();
                $meta[$canonical] = array(
                    'title_key' => isset($def[1]) && is_string($def[1]) ? $def[1] : 'theme.callout.' . $canonical,
                    'title_default' => isset($def[2]) && is_string($def[2]) ? $def[2] : $canonical,
                );
                $alias[$canonical] = $canonical;
                foreach ($names as $name) {
                    if (is_string($name) && self::isCalloutTypeName($name)) {
                        $alias[strtolower($name)] = $canonical;
                    }
                }
            }
        }
        if (!isset($meta['note'])) {
            $meta['note'] = array('title_key' => $note[1], 'title_default' => $note[2]);
            $alias['note'] = 'note';
        }
        return $this->calloutTypes = array($alias, $meta);
    }

    /** 类型标识符合法性校验（同时用于白名单与过滤器产出的清洗） */
    private static function isCalloutTypeName($name)
    {
        return is_string($name) && (bool) preg_match('/^[a-z][a-z0-9-]*$/', strtolower($name));
    }

    /**
     * 识别 callout 起始行
     *
     * @param string $line 原始行
     * @return array|null array(type, title, meta)，非 callout 返回 null
     */
    private function parseCalloutOpen($line)
    {
        // [!type] 后可跟 +/- 折叠标记：本期仅识别并丢弃，不产出折叠结构
        if (!preg_match('/^>\s*\[!([a-zA-Z][a-zA-Z0-9_-]*)\][+-]?[ \t]*(.*)$/', $line, $m)) {
            return null;
        }
        $types = $this->calloutTypes();
        $name = strtolower($m[1]);
        $type = isset($types[0][$name]) ? $types[0][$name] : 'note';
        return array('type' => $type, 'title' => trim($m[2]), 'meta' => $types[1][$type]);
    }

    /**
     * 收集 callout 正文：连续引用行去掉 `> ` 前缀，允许中间夹空行
     * （空行之后若非引用行则终止，尾部空行不计入正文）
     *
     * 空行本身不终结 callout（CommonMark 中引用块跨空行延续，支持多段正文），
     * 但空行后紧跟另一个 `[!type]` 起始行时必须断开，否则相邻两个 callout
     * 会被并进前一个的正文；嵌套 callout（`> > [!type]`）不在此处断开，
     * 它的第二层 `>` 使 callout 起始正则不匹配
     *
     * @param array $lines 全部行
     * @param int   $start 起始行行号
     * @return array array('lines' => 正文行, 'next' => 下一个待处理行号)
     */
    private function collectCalloutBody(array $lines, $start)
    {
        $body = array();
        $total = count($lines);
        $pendingBlank = false;
        $i = $start + 1;
        while ($i < $total) {
            $line = $lines[$i];
            if (preg_match('/^>\s?(.*)$/', $line, $m)) {
                if ($pendingBlank) {
                    if ($this->parseCalloutOpen($line) !== null) {
                        break;
                    }
                    $body[] = '';
                }
                $pendingBlank = false;
                $body[] = $m[1];
                $i++;
                continue;
            }
            if (trim($line) === '') {
                $pendingBlank = true;
                $i++;
                continue;
            }
            break;
        }
        return array('lines' => $body, 'next' => $i);
    }

    /**
     * callout 渲染：结构仅用 div + class，不触碰 XSS 白名单
     *
     * @param string $type      规范类型
     * @param string $title     标题原文（空则取类型默认标题）
     * @param array  $bodyLines 已去引用前缀的正文行
     * @param array  $meta      类型元信息
     * @return string
     */
    private function renderCallout($type, $title, array $bodyLines, array $meta, $depth)
    {
        $text = implode("\n", $bodyLines);

        if ($title !== '') {
            $titleHtml = $this->parseInline($title);
        } else {
            $default = function_exists('theme_t')
                ? theme_t($meta['title_key'], array(), $meta['title_default'])
                : $meta['title_default'];
            $titleHtml = e($default);
        }

        $content = $this->parseBlocks($text, $depth + 1);

        $html = '<div class="callout callout-' . $type . '">'
            . '<div class="callout-title">' . $titleHtml . '</div>';
        // 仅有标题的 callout 不产出空内容容器，避免主题样式带出多余间距
        if ($content !== '') {
            $html .= '<div class="callout-content">' . $content . '</div>';
        }
        return $html . '</div>';
    }

    /** 简易表格解析 */
    private function parseTable(array $rows)
    {
        $headCells = self::splitTableRow($rows[0]);
        // 外层滚动容器：宽表格在窄屏下横向滑动而不撑破页面
        $html = '<div class="table-wrap"><table><thead><tr>';
        foreach ($headCells as $cell) {
            $html .= '<th>' . $this->parseInline($cell) . '</th>';
        }
        $html .= '</tr></thead><tbody>';
        // rows[0] 为表头；分隔行在 parseBlocks 收集阶段已排除，数据行自索引 1 开始
        $rowTotal = count($rows);
        for ($i = 1; $i < $rowTotal; $i++) {
            $cells = self::splitTableRow($rows[$i]);
            $html .= '<tr>';
            foreach ($cells as $cell) {
                $html .= '<td>' . $this->parseInline($cell) . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</tbody></table></div>';
    }

    /**
     * 表格行按 | 切分单元格
     *
     * |x-a|、\left|…\right| 里的竖线是 TeX 语法而非列分隔符，直接 explode 会把
     * 单元格拦腰拆开：公式只剩半个 $ 对，parseInline 匹配不到，KaTeX 便无从渲染。
     * 故先按 parseInline 相同的规则把行内代码与行内公式换成不含 | 的占位符，
     * 切分后再还原。两处规则必须保持一致，否则仍会出现"切分认为是文本、
     * 渲染认为是公式"的错位。占位符带每次渲染的随机前缀，正文无法伪造。
     *
     * @param string $row 原始表格行
     * @return array 单元格数组
     */
    private static function splitTableRow($row)
    {
        $row = trim($row);
        if (strpos($row, '|') === 0) {
            $row = substr($row, 1);
        }
        if (substr($row, -1) === '|') {
            $row = substr($row, 0, -1);
        }

        $spans = array();
        $prefix = '@@CBTBL' . bin2hex(random_bytes(8)) . '_';
        // 顺序同 parseInline：代码先于公式，代码内的 $ 不参与公式匹配
        $patterns = array(
            '/`([^`]+)`/',
            '/(?<![\$\\\\])\$([^\$\n]+?)\$(?!\$)/',
        );
        foreach ($patterns as $pattern) {
            $row = preg_replace_callback($pattern, function ($m) use (&$spans, $prefix) {
                $spans[] = $m[0];
                return $prefix . (count($spans) - 1) . '@@';
            }, $row);
        }

        $cells = array_map('trim', explode('|', $row));
        if (empty($spans)) {
            return $cells;
        }
        return preg_replace_callback(
            '/' . preg_quote($prefix, '/') . '(\d+)@@/',
            function ($m) use ($spans) {
                return $spans[(int) $m[1]];
            },
            $cells
        );
    }

    /** 还原公式占位为前端 KaTeX 容器 */
    private function restoreMath($html)
    {
        foreach ($this->mathBlocks as $index => $math) {
            $tag = $this->mathPrefix . $index . '@@';
            $replacement = '<span class="tex-inline">' . e($math['tex']) . '</span>';
            $html = str_replace($tag, $replacement, $html);
        }
        return $html;
    }

    /**
     * DOMDocument 白名单过滤：剔除危险标签，剥离非法属性
     *
     * @param string $html 待过滤 HTML
     * @return string
     */
    public static function sanitize($html)
    {
        if (trim($html) === '') {
            return '';
        }
        $allowed = array(
            'p', 'br', 'hr', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'blockquote', 'pre', 'code', 'ul', 'ol', 'li',
            'em', 'strong', 'b', 'i', 'del', 's', 'sup', 'sub',
            'table', 'thead', 'tbody', 'tr', 'th', 'td',
            'a', 'img', 'span', 'div', 'figure', 'figcaption',
        );
        $allowedAttrs = array(
            'a'   => array('href', 'title', 'rel'),
            'img' => array('src', 'alt', 'title'),
            'code' => array('class'),
            'pre' => array('class'),
            'span' => array('class'),
            'div' => array('class'),
        );

        $doc = new DOMDocument('1.0', 'UTF-8');
        libxml_use_internal_errors(true);
        // 声明编码避免中文被转实体；按片段加载
        $doc->loadHTML(
            '<?xml encoding="utf-8"?><div id="cb-root">' . $html . '</div>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();

        $root = $doc->getElementById('cb-root');
        if ($root === null) {
            return '';
        }
        self::cleanNode($root, $allowed, $allowedAttrs);

        $out = '';
        foreach ($root->childNodes as $child) {
            $out .= $doc->saveHTML($child);
        }
        return $out;
    }

    /**
     * 递归清理节点：危险标签整体移除，未知标签解包保留文本，属性白名单过滤
     */
    private static function cleanNode($node, array $allowed, array $allowedAttrs)
    {
        $dangerous = array('script', 'style', 'iframe', 'object', 'embed', 'form',
            'input', 'button', 'textarea', 'select', 'link', 'meta', 'base', 'video', 'audio');

        $children = array();
        foreach ($node->childNodes as $child) {
            $children[] = $child;
        }
        foreach ($children as $child) {
            if ($child->nodeType === XML_ELEMENT_NODE) {
                $tag = strtolower($child->nodeName);
                if (in_array($tag, $dangerous, true)) {
                    $node->removeChild($child);
                    continue;
                }
                if (!in_array($tag, $allowed, true)) {
                    // 未知标签：先处理其后代，再解包为文本/子节点
                    self::cleanNode($child, $allowed, $allowedAttrs);
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    continue;
                }
                // 属性白名单
                $keep = isset($allowedAttrs[$tag]) ? $allowedAttrs[$tag] : array();
                $removeAttrs = array();
                foreach ($child->attributes as $attr) {
                    if (!in_array(strtolower($attr->nodeName), $keep, true)) {
                        $removeAttrs[] = $attr->nodeName;
                    }
                }
                foreach ($removeAttrs as $name) {
                    $child->removeAttribute($name);
                }
                // URL 属性协议复检
                foreach (array('href', 'src') as $urlAttr) {
                    if ($child->hasAttribute($urlAttr)) {
                        $url = $child->getAttribute($urlAttr);
                        if (!self::isSafeUrlStatic($url)) {
                            $child->removeAttribute($urlAttr);
                        }
                    }
                }
                self::cleanNode($child, $allowed, $allowedAttrs);
            } elseif ($child->nodeType === XML_PI_NODE || $child->nodeType === XML_COMMENT_NODE) {
                $node->removeChild($child);
            }
        }
    }

    /**
     * 静态版 URL 安全校验：仅允许相对路径与 http(s) 绝对地址
     * 浏览器解析 URL 前会先百分号解码、再剔除首尾及协议段内的空白与 C0 控制字符，
     * 因此判定前必须做同样规范化，否则 " javascript:"、"%20javascript:"、"java\tscript:"
     * 等变体可绕过位置 0 的协议黑名单在前台执行脚本
     */
    private static function isSafeUrlStatic($url)
    {
        $probe = rawurldecode((string) $url);
        $probe = preg_replace('/[\x00-\x20\x7f]+/', '', $probe);
        if ($probe === '' || stripos($probe, 'javascript:') === 0 || stripos($probe, 'data:') === 0
            || stripos($probe, 'vbscript:') === 0) {
            return false;
        }
        if (preg_match('#^[a-z][a-z0-9+.-]*:#i', $probe)) {
            return (bool) preg_match('#^https?://#i', $probe);
        }
        return true;
    }
}
