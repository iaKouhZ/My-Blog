# 主题（模板）开发指南

本博客系统的主题机制：目录即主题、`style.css` 头部元数据声明、原生 PHP 模板、
数据经上下文注入（模板内禁止直接操作数据库）。完整参考实现：`themes/default/`。

## 1. 目录结构

每个主题是 `themes/{dir}/` 目录，目录名只允许小写字母、数字、下划线、连字符
（`[a-z0-9_-]{1,64}`）：

```
themes/
└── my-theme/
    ├── style.css       ← 必需：头部元数据（主题发现的唯一依据）
    ├── index.php       ← 必需：文章列表页（也是其它模板缺失时的回退模板）
    ├── single.php      ← 文章详情
    ├── page.php        ← 独立页面（如"关于我"）
    ├── archive.php     ← 分类/归档列表
    ├── search.php      ← 搜索结果
    ├── 404.php         ← 404 页
    ├── login.php       ← 登录页
    ├── register.php    ← 注册页
    ├── forgot.php      ← 找回密码页
    ├── functions.php   ← 可选：主题自有钩子挂载与助手函数（含插件整页外壳 theme_plugin_page_shell，见 §5.0）
    ├── settings.php    ← 可选：后台主题设置清单（声明式，内核据此渲染表单与校验保存）
    ├── langs/          ← 可选：主题语言包（xx_XX.php；多语言约定见 §4.3）
    ├── js/             ← 可选：主题自有脚本子目录（主题静态资源一律随主题目录存放）
    ├── header.php      ← 可选：页头局部模板（Theme::part('header') 引入）
    ├── sidebar.php     ← 可选：侧边栏局部模板
    ├── theme.js        ← 可选：主题脚本（theme_footer() 自动输出）
    └── …其它静态资源
```

- `style.css` 缺失的目录不会被识别为主题。
- 某个页面模板（如 `single.php`）缺失时自动回退渲染 `index.php`。
- 主题静态资源用 `Theme::assetsUrl('xxx.png')` 生成 URL；内核会自动追加
  `?v={文件修改时间}` 缓存版本号，文件更新后浏览器自动加载新版，
  主题无需也不得自行拼接版本参数；`assets_url()`（非主题资源）同理。
  禁止引用任何 CDN 资源；需要展示第三方图床图片时走 `ext_img()` + 可信图床白名单（见 §7.3）。
- **主题自有静态资源（脚本/样式/图片）必须随主题放在主题目录内**（可建
  `js/` 等子目录，参考 `themes/default/js/`），禁止放 `assets/`；仅内核
  跨场景共用脚本（前台与后台视图均引用的，如 `assets/front/verify.js`、
  `assets/front/password_check.js`）与第三方本地化资源（`assets/vendor/`）例外。

## 2. style.css 头部元数据

```css
/*
Theme Name: My Theme
Author: Your Name
Version: 1.0.0
Description: 主题描述（后台列表展示，截断 40 字）
*/
```

后台「模板管理」据此展示主题名称/作者/版本/描述。

## 3. 渲染上下文（$ctx）

模板由 `Theme::render()` 渲染，内核按页面类型注入变量（同时可用同名变量直接访问）：

| 模板 | 注入变量 |
|---|---|
| index / archive / search | `page_type`、`title`、`posts`（数组）、`page`、`totalPages`、`route`、`routeParams`；archive 另有 `subject`（分类路由为分类对象；作者路由为用户行：`id`/`username`/`nickname`/`avatar`/`signature`，作者页建议展示头像/昵称/签名三行居中，不带“作者：”前缀）；search 另有 `kw` |
| single | `page_type`、`title`、`post`（含 `author` 快照）、`comments`（当前页可见评论）、`commentTotal`、`commentPage`、`commentTotalPages`、`editCommentId` |
| page | `page_type`、`title`、`post` |
| login / register / forgot | `page_type`、`title`、`error`，及各自表单回显变量 |
| 404 | `page_type`、`title` |

补充：`login` 另有 `account`（账号回显）；`register` 另有 `old`（表单回显数组）
与 `emailEnabled`、`smsEnabled`（邮件/短信插件是否启用）；`forgot` 另有
`info`（提示文案）与 `emailEnabled`、`smsEnabled`。

评论每页 50 条，按 created_at/id 升序；游客只见已发布评论，登录用户额外见自己的待审核评论。
评论分页使用 `paginate($commentPage,$commentTotalPages,'post',array('id'=>...,'slug'=>...))`，由 Router 生成 comment_page 参数与 #comments 锚点；搜索分页同样保留 page。
当前页不含父评论时，回复应按独立评论展示，避免跨页回复被主题丢弃；父评论同页时沿用一层回复结构。总数标题应使用 commentTotal。
现有主题忽略新增字段仍可渲染，建议补充分页导航。Theme 既有方法和助手保持兼容，语言与公共数据分别由 ThemeLocale / ThemeData 实现，主题无需直接依赖这些内部类。

### 3.1 认证页验证码约定（register / forgot）

- 验证码输入框与「发送验证码」按钮**必须按 `$emailEnabled` / `$smsEnabled` 条件渲染**：
  插件未启用时不显示对应验证码行。
- **短信插件启用时注册手机号为必填项**，且必须通过短信验证码核验；
  主题的 `register.php` 应据此去掉「（可选）」标注并加 `required`。
  插件未启用时手机号保持可选、免验。
- 发送按钮复用内核通用脚本 `assets/front/verify.js`：按钮声明
  `data-scene`（如 `register`）、`data-channel`（`email`/`sms`）、
  `data-target`（目标输入框选择器）三个属性，并在引入脚本**之前**定义
  `window.CB_VERIFY = { url: Router::url('verify_send'), csrf: Csrf::token() }`
  （参考 `themes/default/register.php`）。服务端已内置 60 秒重发间隔、
  每日上限与 CSRF 校验，主题无需重复实现。
- 注册页另加载默认主题自带的 `themes/default/js/register_check.js`：提交前逐项校验
  （信息不全/不合法时阻止请求并在字段下方提示）+ 确认密码实时一致反馈；
  其它主题可参考其实现随主题自带校验脚本，若自写校验须保持与服务端规则一致（前端仅为体验，
  弱口令黑名单与验证码真实性始终以服务端判定为准）。
- 找回密码页（forgot）须加载 `assets/front/password_check.js` 并在 form 上
  声明 `data-pwd-check` 与 `data-account`（指向账号输入框的选择器）：
  新密码强度（8-64 位、四类字符至少三类、不含用户名）+ 两次输入一致性
  实时反馈，提交前拦截不合法请求；确认输入框旁需放置
  `<span class="pwd-match" aria-live="polite">` 占位（参考
  `themes/default/forgot.php`）。服务端终判见 `Auth::validate_password_strength`。

文章行常用字段：`id`、`title`、`slug`（可为 NULL，URL 生成自动回退 id）、
`excerpt`、`cover`、`views`、`created_at`、`is_top`（置顶标记，非 0 时默认主题
在列表标题前展示「置顶」徽标）、`author`（`username`/`nickname`/`avatar`）。
文章正文必须经 `render_content($post['content'])` 输出（Markdown 解析 + 白名单 XSS 过滤）。
`cover` 存 `uploads/...` 相对路径，主题渲染需用 `Router::base() . '/' . $post['cover']`
拼接（空值表示无封面，按需判断）。

## 4. 模板 API（`core/Theme.php`）

| 函数 | 说明 |
|---|---|
| `theme_head($extra)` | 页头输出：charset/viewport/title/description/style.css + `front_head` 钩子 |
| `theme_footer()` | 页尾输出：`front_footer` 钩子 + `theme.js` |
| `site_name()` / `site_motto()` / `site_description()` | 站点设置 |
| `page_title()` | 当前页标题（含站点名后缀） |
| `site_author()` | 首位管理员信息（头像卡片等） |
| `avatar_url($avatar)` | 头像 URL（空头像回退默认图） |
| `nav_pages()` | 独立页面导航列表（仅后台勾选“显示在侧边栏导航”的已发布页面，返回 `id`/`title`/`slug`；无别名页面经 `Router::url('page', array('slug'=>…, 'id'=>…))` 回退数字 id 访问） |
| `nav_items()` | 自定义导航项（后台「导航管理」增删，返回 `title`/`url` 数组） |
| `nav_categories()` | 分类导航（含 `count` 文章数） |
| `theme_setting($key, $default)` | 当前主题配置项（后台「模板管理 → 设置」维护） |
| `icp_number()` / `gongan_number()` | ICP / 公安备案号（未配置返回空串，前台据此隐藏） |
| `gongan_code($text)` | 从公安备案文本提取纯数字编号（拼官方查询链接） |
| `the_posts()` / `the_post()` / `the_comments()` | 读取上下文数据 |
| `render_content($markdown)` | 正文渲染（唯一允许的正文输出方式） |
| `paginate($page, $totalPages, $route, $params)` | 分页 HTML |
| `copyright_line()` | 页脚版权行 |
| `Theme::assetsUrl($path)` | 主题静态资源 URL |
| `Theme::part($name)` | 引入局部模板（如 `Theme::part('sidebar')`） |
| `Theme::renderPluginPage($innerHtml, $ctx)` | 插件整页渲染入口：设上下文 → `loadFunctions()` → 优先调用主题的 `theme_plugin_page_shell()`，未声明时降级为 `header`+`#content`+正文+`footer`。骨架由主题决定，插件不得自行拼接（见 §5.0） |
| `ext_img($url, $opts)` | 外链图片输出（唯一允许的第三方图片输出方式）：https 且主机命中管理员配置的可信白名单时返回 `<img>`（自带 `referrerpolicy="no-referrer"` / `loading="lazy"`），否则返回**空串**，模板必须降级；`$opts` 支持 `alt`、`class` |
| `theme_t($key, $args, $default)` | 主题文案翻译（查询链：当前语言包 → 主题中文基线 → `$default` → 原样返回键，支持 `%s` 占位） |
| `Theme::locale()` | 前台 `<html lang>` 属性值（BCP47，如 `zh-CN`/`en-US`） |
| `Theme::langCode()` | 当前主题语言码（如 `zh_CN`） |
| `Theme::availableLangs()` | 主题实有语言包列表（`code => 显示名`，语言切换器用） |
| `Theme::langSwitchUrl($code)` | 语言切换链接（自动携带当前页地址作为回跳目标） |
| `theme_date($datetime)` | 前台日期格式化（格式串随语言包 `theme.common.date_format` 切换，缺省中文格式） |
| `json_out_script($data)` | 在 `<script>` 内输出 JSON 字面量的唯一允许方式（HEX 四标志转义 `<>&'"`，防 `</script>` 逃逸；禁止在模板里直接 `json_encode`） |

站内链接一律用 `Router::url($route, $params)` 生成（如文章页
`Router::url('post', array('id' => $post['id']))`），禁止手写站内路径——
伪静态开关切换时链接不能破坏。

### 4.1 自定义导航项（nav_items）

后台「导航管理」（站点设置类，能力点 `manage_options`）维护的自定义导航项存于
`options.nav_items`（JSON），主题用 `nav_items()` 读取，每项含 `title` 与 `url`：

```php
<?php foreach (nav_items() as $navItem): ?>
<li><a href="<?php echo e($navItem['url']); ?>"><?php echo e($navItem['title']); ?></a></li>
<?php endforeach; ?>
```

- URL 在保存时已过白名单（站内相对路径或 http(s) 绝对地址），模板侧仍必须
  对 `title`/`url` 做 `e()` 转义；
- 建议放在侧边栏导航分组中「首页 + `nav_pages()`」之后渲染（参考
  `themes/default/sidebar.php`）；无自定义项时返回空数组，无需额外判空分支。

### 4.2 公式、代码高亮与 Callout 渲染约定

`render_content()` 输出的正文中，数学公式为占位容器：行内公式是
`<span class="tex-inline">原始 TeX</span>`，块级公式是
`<div class="tex-block">原始 TeX</div>`（单独成行的行内公式会被包在
`<p>` 里，**不会出现 `tex-block` 套 `tex-inline` 的嵌套**）；围栏代码块为
`<pre><code class="language-xxx">`。

Markdown 表格一律输出在滚动容器内：

```html
<div class="table-wrap"><table><thead>…</thead><tbody>…</tbody></table></div>
```

`.table-wrap` 由内核产出，**主题必须给它横向滚动**，否则宽表格会被
`table { max-width: 100% }` 压进版心并逐字折行：

```css
article .table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
article .table-wrap > table { width: auto; min-width: 100%; max-width: none; }
article .tex-block { overflow-x: auto; }   /* KaTeX 块级公式内部 nowrap */
```

`.tex-block` 同样需要滚动——KaTeX 的 `.katex-display > .katex` 是
`white-space: nowrap` 且自身不带溢出处理，不加外层滚动会直接撑破窄屏版心。

表格单元格内的 `|` 只有在行内代码与 `$...$` 公式之外才是列分隔符，
所以 `| $\exists M>0,\ |f(x)|\le M$ |` 这样的绝对值竖线可以正常书写。

Obsidian Callout（`> [!note] 标题`）由内核解析为：

```html
<div class="callout callout-note">
  <div class="callout-title">标题</div>
  <div class="callout-content">…块级 Markdown 渲染结果…</div>
</div>
```

- 第二个类名 `callout-{类型}` 是规范类型（`note`/`abstract`/`info`/`todo`/
  `tip`/`success`/`question`/`warning`/`failure`/`danger`/`bug`/`example`/
  `quote`），**主题按它取语义色即可**（参考默认主题在 `:root` 之外的
  `.callout-*` 规则与 `html.darkmode` 下的提亮覆写）；
- 只有标题、正文为空时不产出 `.callout-content` 容器；
- 标题缺省时内核经 `theme_t('theme.callout.{类型}', array(), '中文缺省')` 取文案，
  主题语言包补 `theme.callout.*` 键即可本地化，无 `langs/` 目录的主题回退中文缺省；
- 结构只用 `div` + `class`，不引入新标签，主题无需改动 XSS 白名单；
- `[!note]+` / `[!note]-` 折叠标记当前**仅识别并丢弃**，不产出折叠结构。

主题若需展示公式/高亮，须在 `functions.php` 中经 `front_head` / `front_footer`
钩子加载**本地化**的 KaTeX / highlight.js 资源，最后加载随主题自带的
渲染脚本（默认主题为 `themes/default/js/render.js`）完成渲染（参考
`themes/default/functions.php`）。

禁止事项：

1. 主题不得自行调用 KaTeX 二次渲染正文（`render.js` 已带幂等守卫，
   重复挂载会浪费资源；对已渲染产物再渲染会把公式内容翻倍）；
2. 主题不得在正文外再包一层 `.tex-block` / `.tex-inline` 容器，
   也不得对 `render_content()` 的输出做正则改写；
3. 代码、公式与 Callout 由内核按层级解析，代码内 `$` 不会触发公式；嵌套上限 64，超出部分转义显示。
4. 主题不得自行解析或改写 Callout（`.callout` 的生成与类型白名单由内核负责，
   改写会绕过类型校验；后台 Vditor 预览仍显示普通引用属已知差异，**前台渲染为准**）。

### 4.3 主题多语言（i18n）

主题可选配 `langs/` 目录实现多语言，参考实现 `themes/default/langs/`（中英双语）：

```
langs/
├── zh_CN.php     ← 主题中文基线（随主题发布，最终降级方案，禁止删除）
└── en_US.php     ← 英文包；缺键自动回退中文基线
```

- **包格式**：与后台语言包同规约——可执行 PHP（`return array(...)`），文件名
  `xx_XX.php`（两位小写 + 下划线 + 两位大写），文件头必须带
  `defined('APP_BOOT') or exit;` 守卫；`_name`（显示名）与 `_locale`
  （BCP47，如 `en-US`）为包元信息键。`themes/` 下 PHP 已被三套重写规则
  禁止直接执行，语言包无需额外防护配置。
- **键规范**：`theme.{模块}.{语义}`（如 `theme.comment.submit`）；
  值支持 `%s` 顺序占位（`%%` 转义字面量 `%`）。
- **语言解析链**（每请求解析一次）：访客手动选择（`cb_theme_lang` cookie，
  由语言切换链接 `/lang/{code}` 写入，优先级最高）→ 浏览器 `Accept-Language`
  （按 q 值降序，先精确匹配 `en-US → en_US`，再按主标签族匹配 `en → 首个 en_*`；
  仅匹配主题实有语言包，标头值不参与路径拼接）→ 后台 `admin_locale`
  （主题存在同名包时沿用）→ `zh_CN`。
- **模板用法**：可见文案一律 `e(theme_t('theme.xxx.yyy'))`；日期用
  `theme_date($post['created_at'])`（格式串取包内 `theme.common.date_format`）；
  `<html lang="<?php echo e(Theme::locale()); ?>">`。内核侧调用
  （`paginate()`、Front 控制器消息）必带中文缺省参数——**无 `langs/` 目录的
  主题行为与硬编码中文时期完全一致**，无需任何改造。
- **语言切换器**：多于一个语言包时在侧边栏渲染（参考
  `themes/default/sidebar.php` 的「语言」分组）：遍历 `Theme::availableLangs()`，
  当前语言纯文本高亮，其余链接到 `Theme::langSwitchUrl($code)`。
- **JS 文案**：无 CSP 的页面（login/register/forgot 等）可内联注入
  `window.CB_*` 配置（经 `json_out_script()`；内核 `verify.js`/
  `password_check.js` 分别读 `CB_VERIFY.msg`/`CB_PWD_LANG`，缺省保持中文，
  参考 `themes/default/register.php`、`forgot.php`）；**文章页/独立页有受限
  CSP（`script-src 'self'`）禁止内联脚本**，这些页面加载的脚本（如 `theme.js`）
  文案须经元素 `data-*` 属性传递（参考 `single.php` 的 `#btn-toc` 与
  `header.php` 的 `<body data-confirm-default>`）。

## 5. functions.php 与钩子

主题的 `functions.php` 在渲染前加载，可直接挂载钩子（此时 `init` 已触发完毕，
请勿再挂 `init`）：

```php
<?php
defined('APP_BOOT') or exit;

add_action('front_head', 'my_theme_head');
add_action('front_footer', 'my_theme_footer');
add_filter('post_content', 'my_theme_content');
```

可用钩子清单与语义见 `plugins/README.md` §3（主题与插件共享同一套 Hook 机制，
常用：`front_head`、`front_footer`、`post_content`）。

主题若需要放宽外链图片来源，唯一方式是返回管理员配置过的主机清单，
**禁止在主题代码里硬编码域名**：

```php
add_filter('front_img_src_hosts', function ($hosts) {
    // 主题设置里由管理员填写（一行为一个主机），此处只做透传与规范化
    return array_merge((array) $hosts, ExternalMedia::sanitizeList(
        preg_split('/[\r\n,;]+/', (string) theme_setting('img_hosts', ''))
    ));
});
```

`Theme::loadFunctions()` 也会在插件整页渲染自定义路由时被显式调用，
因此这里挂的钩子不会因"非主题路由"而失效。

### 5.0 插件整页渲染：`theme_plugin_page_shell()`（重要）

插件认领自定义路由后需要"用主题骨架包裹自身正文"输出整页。**各主题的骨架分工并不统一**：

| | 侧边栏位置 | `#content` 由谁开启 | 额外容器 |
|---|---|---|---|
| 默认主题 | `header.php` 内 | 页面模板 | 无 |
| Argon | 页面模板内 | `header.php` | `#primary` + `<main>` 由页面模板开、`footer.php` 闭 |

因此**插件不得自行拼接骨架**——必在其中一类主题上漏掉侧边栏或多闭合容器。正确做法是主题在
`functions.php` 中声明自己的骨架：

```php
/**
 * 插件整页外壳：插件经 Theme::renderPluginPage() 调用本函数输出整页
 * @param string $innerHtml 插件正文 HTML（调用方已转义）
 */
function theme_plugin_page_shell($innerHtml)
{
    Theme::part('header');
    // ……本主题的侧边栏/内容容器等，按自身页面模板的结构输出……
    echo $innerHtml;
    Theme::part('footer');
}
```

插件侧只需 `Theme::renderPluginPage($innerHtml, array('title' => '…', 'page_type' => '…'))`，
内核会先 `loadFunctions()` 再判定该函数是否存在。**未声明时降级**为默认主题的约定
（`header` → `#content` → 正文 → `footer`），因此老主题不会因未实现而白屏，但侧边栏是否出现取决于其
`header.php` 是否自带。新增主题建议都实现该函数，并在其中复用与 `page.php` 相同的容器结构。

骨架函数内不要读取 `Theme::render()` 的局部变量，数据一律经 `cb_ctx()` 获取。

### 5.1 主题应触发的插件注入点

注入点名称和参数保持不变。`comments_before/after` 包围当前页评论及分页导航，不代表一次渲染全部评论；读取总数请用 cb_ctx()['commentTotal']。
文章正文与评论可见性由控制器/查询服务处理，主题注入点不得自行补查其它用户的待审核评论。


除内核自动触发的钩子外，主题模板内应保留以下输出点，供插件注入界面元素
（参考 `themes/default`）：

| 钩子 | 位置 | 说明 |
|---|---|---|
| `auth_form_footer` | `login.php` / `register.php` / `forgot.php` 认证表单下方（卡片内） | `do_action('auth_form_footer', 'login'/'register'/'forgot')`，第三方登录入口（如 QQ 图标）；回调应按页面参数区分 |
| `post_card_before` / `post_card_after` | `index.php` / `archive.php` / `search.php` 每个文章卡片前后 | `do_action('post_card_before', $postItem, $context)`，供插件加徽标/推荐位等 |
| `single_content_after` | `single.php` 正文结束后、评论区前 | `do_action('single_content_after', $post)`，打赏/转载/相关推荐等 |
| `comments_before` / `comments_after` | `single.php` 评论区 `<section>` 之前/之后 | `do_action('comments_before', $post)`，评论区整体前后注入 |
| `comment_area_state`（条件渲染） | `single.php` 评论区 | 主题必须先 `apply_filters('comment_area_state', array('list'=>true,'form'=>true,'actions'=>true), $post)`，再按返回值控制：`list=false` 评论区整体不渲染；`form=false` 隐藏发表框；`actions=false` 隐藏本人评论的编辑/删除按钮与行内编辑（参考 `themes/default/single.php`） |
| `page_content_after` | `page.php` 独立页面正文结束后 | `do_action('page_content_after', $post)` |
| `author_header_after` | `archive.php` 作者头部区块内末尾（头像/昵称/签名之后） | `do_action('author_header_after', $subject)`，认证标识/社交链接等；`$subject` 为用户行 |
| `sidebar_widgets` | `sidebar.php` 分类分组之后（sidebar-body 内） | `do_action('sidebar_widgets')`，插件追加侧边栏分组/小工具，输出需自带 `.sidebar-section` 结构并自行转义 |

缺失这些输出点不会报错，但会导致对应插件功能在新主题上无法展示。

新主题若要支持「插件整页渲染」的场景（插件认领自定义路由后用主题骨架输出整页），
**应实现 `theme_plugin_page_shell()`**（见 §5.0），在其中复用与 `page.php` 相同的容器结构；
同时保证 `header.php` / `footer.php` 通过 `Theme::part()` 引入且不依赖 `Theme::render()`
内的局部变量。未实现时内核降级为默认主题的骨架约定，侧边栏能否出现取决于
`header.php` 是否自带。模板只从 `$GLOBALS['cb_ctx']` 或 `cb_ctx()` 取数据，即可被插件直接复用。

## 6. 启用、上传、删除与主题设置

- **启用**：后台「模板管理 → 启用」，写入 `options.active_theme`。
- **主题设置**：设置页由主题自带的 `settings.php` 清单驱动（可选文件，
  不提供则列表页不展示「设置」按钮）：

  ```php
  <?php
  defined('APP_BOOT') or exit;
  return array(
      'my_option' => array(
          'label'     => '字段标题',
          'type'      => 'text',        // text / textarea / checkbox / select
          'maxlength' => 64,            // text 上限（内核封顶 500）
          'options'   => array('a' => '甲'), // select 专用，键即存储值
          'hint'      => '字段下方提示',
          'default'   => '',
      ),
  );
  ```

  内核按清单通用渲染表单、按类型走统一输入校验器保存，**清单外字段一律拒收**；
  清单经 `theme_settings_schema` 过滤器，插件可追加字段。值按主题目录独立存于
  `options.theme_settings_{dir}`（JSON），模板用 `theme_setting($key, $default)` 读取。
  默认主题内置 ICP/公安备案号两项，展示规则见 `themes/default/footer.php`
  （未填不展示；均填时以「|」分隔；公安备案前带 `gongan-icon.png` 图标并链接
  `beian.mps.gov.cn` 查询页，ICP 链接 `beian.miit.gov.cn`）。主题若需自带备案图标，
  应将 `gongan-icon.png` 随主题包分发。
- **上传**：zip 包（≤10MB）内必须包含 `style.css`；条目白名单：禁止路径穿越与反斜杠条目、
  隐藏文件（`.htaccess`/`.user.ini` 等）及 `phar`/`phtml`/`php3+` 可执行伪装，并拒绝符号链接等
  Unix 特殊类型条目（`getExternalAttributesIndex` 判定，防 Zip Slip 越目录写入），解压失败整体清理；
  解压后目录名取 zip 文件名（清洗为 `[a-z0-9_-]`）。目录已存在时执行**覆盖更新**：
  先解压到临时目录，新旧包均显式声明 `Theme Name` 时必须一致才放行（防止同名异主题误覆盖），
  然后旧目录 rename 备份、新目录就位，失败自动回滚；`default` 与**当前启用中的主题**不可覆盖，
  需先切换到其他主题。覆盖更新记 `theme.update` 审计（新装记 `theme.upload`）。

> **信任警示：主题是服务端 PHP 代码。** 启用主题即在前台渲染时执行其 `functions.php`
> 与全部模板文件，等同于把站点代码执行权交给主题作者。只安装/启用来源可信的主题包；
> 主题包内 PHP 文件的直接访问由重写规则拦截（Apache/Nginx 均含），但宝塔面板可能因自带
> PHP 规则优先匹配而失效，此时依赖"仅启用可信主题"这一前提兜底。
- **删除保护**：
  - `default` 主题**永久禁止删除**（前端不渲染删除按钮 + 服务端双重拦截）；
  - 当前启用中的主题不可删除；
  - 若启用中的主题目录被人为删除（FTP 等旁路），`Theme::current()` 会
    **自动降级回 `default`**，前台不会报错。

## 7. 安全要求（违反将造成系统级漏洞）

1. 所有输出到 HTML 的变量必须 `e()` 转义；正文只准经 `render_content()` 输出。
2. 模板内禁止直接操作 `DB`/`$_GET`/`$_POST`——数据一律来自注入的上下文或模板 API。
3. 禁止任何运行时 CDN 引用（含字体、图片）；第三方资源本地化到 `assets/vendor/`。
   **唯一例外是外链图片**：主题需要展示第三方图床/视频站封面时，主机必须由管理员
   在「安全设置 → 外链图片信任」中声明（主题自身不得硬编码域名），输出统一走
   `ext_img($url, $opts)`——未命中白名单时它返回**空串**，主题必须降级（占位块或纯文字），
   禁止回退成手写 `<img src>`。详见 `plugins/README.md` §7.1。
4. 前台文章页与独立页受受限 CSP 约束（默认仅 `'self'` 本地资源），内联脚本/外部 CDN 会被浏览器拦截；
   CSP 的 `img-src` 会随上述可信图床白名单自动放宽（由 `ExternalMedia::cspHeader()` 统一生成，
   主题**不得**自行 `header()` 覆写 CSP，否则多份 CSP 按交集生效、行为不可预期）。
5. PHP 7.4 语法兼容（允许箭头函数、`??=` 等 7.4 特性；禁止仅 8.0+ 支持的语法/函数）。
6. 每个 PHP 文件头部保留 `defined('APP_BOOT') or exit;` 守卫。

## 8. 快速上手

复制 `themes/default/` 为 `themes/my-theme/`，修改 `style.css` 元数据与样式即可；
模板骨架与全部 API 调用方式保持不变，按需增删局部模板。
