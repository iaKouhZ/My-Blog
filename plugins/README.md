# 插件开发规范

本博客系统的插件机制仿照 WordPress 规范实现：目录即插件、头部元数据声明、Hook 接入、零框架依赖。

## 1. 目录结构

每个插件是 `plugins/{slug}/` 目录，主文件必须为 `{slug}.php`：

```
plugins/
└── hello-world/
    ├── hello-world.php    ← 主文件（必需）
    └── langs/             ← 语言包（可选，见 §4.3；zh_CN.php 为基线）
        ├── zh_CN.php
        └── en_US.php
```

- `slug` 只允许小写字母、数字、连字符（`[a-z0-9-]{1,64}`）。
- 主文件缺失或头部元数据缺少 `Plugin Name` 的目录不会被识别为插件。
- 插件静态资源（JS/CSS/图片）放在插件目录内，用 `plugin_url($slug, $path)` 生成 URL；
  禁止引用任何 CDN 资源。

### 1.1 后台 zip 上传安装

插件管理页可直接上传 zip 包安装/更新，无需 FTP：

- 包内须含 `{slug}.php` 主文件（可再套一层目录，内核自动扁平化），slug 由主文件名推导
  而非 zip 文件名；主文件头部必须声明 `Plugin Name`，否则整包拒绝。
- zip 条目安全白名单与主题上传一致（`core/ZipSafe.php`：禁路径穿越/隐藏文件/可执行伪装
  扩展名/符号链接），大小 ≤10MB 且受服务器 `upload_max_filesize`/`post_max_size` 限制。
- 同名插件执行**覆盖更新**：新旧包 `Plugin Name` 不一致时拒绝（防同名异插件误覆盖）；
  旧目录自动备份、失败回滚，启用中的插件覆盖后保持启用。覆盖记 `plugin.update` 审计
  （新装记 `plugin.upload`），成败均留痕。

## 2. 头部元数据

```php
<?php
/**
 * Plugin Name: Hello World
 * Description: 插件开发示例
 * Version: 1.0.0
 * Author: Your Name
 * Requires: 1.0
 */
defined('APP_BOOT') or exit;
```

第二行的防护语句必须保留：防止文件被浏览器直接访问执行。

## 3. Hook 机制

语义与 WordPress 一致（`core/Hook.php`）：

- `add_action($hook, $callback, $priority = 10)` / `do_action($hook, ...$args)`
- `add_filter($hook, $callback, $priority = 10)` / `apply_filters($hook, $value, ...$args)`

插件主文件在内核 `init` 之后被加载，**请勿再挂 `init` 钩子**（此时已触发完毕），
直接挂业务钩子即可。

### 预置钩子一览

| 钩子 | 类型 | 参数 | 说明 |
|---|---|---|---|
| `front_head` | action | 无 | 前台 `</head>` 前输出点 |
| `front_footer` | action | 无 | 前台页尾输出点 |
| `admin_head` | action | 无 | 后台 `</head>` 前输出点（插件后台样式/脚本） |
| `admin_footer` | action | 无 | 后台 `</body>` 前输出点 |
| `post_content` | filter | `$html` | 文章正文渲染后、输出前（可追加处理） |
| `front_posts` | filter | `$posts, $context` | 首页/归档/搜索列表当前页文章数组，可排序/修饰（`$context` 为 home/category/author/search；分页已在此之前确定，不宜移除条目） |
| `comment_before_save` | filter | `$data` | 评论入库前，可改写或拦截 |
| `comment_write_allowed` | filter | `$allowed, $action, $postId, $commentId` | 前台评论写入统一拦截点（内核触发）；`$action` 为 create/update/delete（create 时 `$commentId=0`），返回 `false` 拒绝并记审计日志；后台评论管理不受影响 |
| `post_edit_fields` | action | `$post` | 后台文章编辑表单内扩展字段注入点（内核视图触发，新建时 `$post` 为 null；输出需自带 `.form-row` 结构并自行转义） |
| `post_saved` | action | `$id, $data, $isNew` | 文章保存完成后（内核触发），插件在此持久化随文章的扩展数据 |
| `post_deleted` | action | `$id` | 文章彻底删除后（内核触发），插件清理随文章的扩展数据 |
| `post_list_row_actions` | action | `$post` | 后台文章列表行内追加操作按钮（内核视图触发，输出需自带 CSRF 表单） |
| `comment_list_row_actions` | action | `$comment` | 后台评论列表行内追加操作按钮（内核视图触发，输出需自带 CSRF 表单） |
| `comment_area_state` | filter | `$state, $post` | 文章详情页评论区渲染状态（内核+主题各触发一次）；`$state` 为 `array('list','form','actions')` 布尔数组，分别控制评论列表/发表框/编辑删除按钮，`list=false` 时内核跳过评论查询 |
| `admin_menu` | action | 无 | 注册后台菜单项/设置页 |
| `user_register` | action | 注册数据 | 注册前置校验点位 |
| `password_reset` | action | 找回数据 | 找回密码前置校验点位 |
| `send_verify_code` | filter | `$handled, $scene, $target, $channel, $code` | 返回 `true` 表示已接管发送 |
| `verify_code_check` | filter | `$result, $scene, $target, $code, $channel` | 返回 `bool` 接管核验；返回 `null` 走本地表 |
| `password_blacklist` | filter | `$list` | 扩展弱口令黑名单 |
| `route_parse` | filter | `$route, $path` | 未知路径落入 404 前认领自定义路由 |
| `front_route_{路由名}` | action | `$params` | 接管经 `route_parse` 认领的路由 |
| `auth_form_footer` | action | `$page` | 认证表单下方注入点（主题触发；`$page` 为 `login`/`register`/`forgot`，回调应按参数区分页面） |
| `post_card_before` / `post_card_after` | action | `$post, $context` | 列表页文章卡片前/后注入点（主题触发，适合加徽标/推荐位等） |
| `single_content_after` | action | `$post` | 文章详情正文结束后、评论区前（主题触发，如打赏/转载/相关推荐） |
| `comments_before` / `comments_after` | action | `$post` | 文章详情页评论区整体之前/之后（主题触发） |
| `page_content_after` | action | `$post` | 独立页面正文结束后（主题触发） |
| `author_header_after` | action | `$subject` | 作者归档页头部区块内末尾（主题触发，如认证标识/社交链接；`$subject` 为用户行） |
| `sidebar_widgets` | action | 无 | 前台侧边栏追加分组/小工具（主题触发，输出需自带 `.sidebar-section` 结构并自行转义） |
| `theme_settings_schema` | filter | `$schema, $theme` | 主题设置清单（后台设置页字段定义），插件可给指定主题追加设置项 |
| `profile_cards` | action | `$user` | 后台个人资料页追加卡片（内核视图触发） |
| `plugin_activate` / `plugin_deactivate` / `plugin_uninstall` | action | `$slug` | 插件生命周期 |

## 3.1 自定义路由（OAuth 回调 / Webhook 等）

内核路由表为硬编码，插件通过两步接管未知路径（参考 `plugins/qq-login`）：

```php
// 第一步：认领路径，返回自有路由名
add_filter('route_parse', 'my_claim_route', 10);
function my_claim_route($route, $path)
{
    if ($path === 'my-callback') {
        return array('route' => 'my_callback', 'params' => array());
    }
    return $route;
}

// 第二步：注册处理动作（未注册则照常 404）
add_action('front_route_my_callback', 'my_handle_callback');
function my_handle_callback($params)
{
    // GET/POST 自行处理；改状态的 POST 必须 Csrf::verifyOrDie()
}
```

URL 生成需兼容伪静态开关：开启时 `Router::base() . '/my-callback'`，
关闭时 `Router::base() . '/index.php?r=' . rawurlencode('my-callback')`。

## 4. 插件 API

| 函数 | 说明 |
|---|---|
| `plugin_option($slug, $key, $default)` | 读取插件配置（options 键 `plugin_{slug}_{key}`） |
| `plugin_option_update($slug, $key, $value)` | 写入插件配置 |
| `plugin_data_set($slug, $key, $value, $ttl)` | 全局数据写入（ttl>0 即临时缓存，到期自动失效） |
| `plugin_data_get($slug, $key, $default)` | 全局数据读取（过期视为不存在） |
| `plugin_data_delete($slug, $key)` | 全局数据删除 |
| `plugin_user_set($slug, $userId, $key, $value)` | 用户级数据写入（第三方账号绑定等） |
| `plugin_user_get($slug, $userId, $key, $default)` | 用户级数据读取 |
| `plugin_user_delete($slug, $userId, $key)` | 用户级数据删除 |
| `plugin_register_table($slug, $name)` | 登记自建表（卸载时内核自动 DROP） |
| `plugin_table($slug, $name)` | 自建表完整表名（含前缀） |
| `plugin_log($action, $detail)` | 写统一审计日志（category 固定 plugin） |
| `plugin_url($slug, $path)` | 插件静态资源 URL |
| `register_verify_provider($channel)` | 声明验证码渠道能力（仅插件加载期可调，强制归属当前插件） |
| `get_verify_provider($channel)` | 查询渠道声明者 slug（未声明返回 null） |
| `register_plugin_page($slug, $title, $callback)` | 注册后台设置页（在 `admin_menu` 钩子中调用） |
| `plugin_t($slug, $key, $args, $default, $lang)` | 插件文案翻译（见 §4.3 插件多语言约定） |
| `input_password($key, $default, $maxLen)` | 口令/密钥专用输入校验器（原样透传不 trim 不过滤，仅字符串化与长度上限；AccessKeySecret、SMTP 授权码等一律经它读取，禁止直读 `$_POST`） |

其他可用内核 API：`add_action/add_filter`、`e()`、`Router::url()`、`Option::get()`、
`DB::query()`（结构化查询构造器，值自动参数绑定）、`blog_log()`、`client_ip()` 等。
插件后台设置页文案可用 `admin_t($key)` 接入后台多语言（当前语言包缺键时自动回退中文基线
`core/langs/zh_CN.php`；插件亦可完全自管文案，不强制）。
**禁止**在插件中直接拼接 SQL、直接读 `$_GET`/`$_POST`
原值、输出未转义变量——请复用内核的统一输入校验器（`input_text/input_int/input_email/input_phone/input_slug/input_enum/input_password`）与 `e()`；
在 `<script>` 内输出 JSON 一律用 `json_out_script()`。

### 4.1 插件数据存储（plugin_data 表）

一张表覆盖三种场景，插件永远不写 SQL、不管建表：

- **全局配置**：`plugin_data_set/get`（永久）；简单键值也可继续用 `plugin_option`；
- **用户级键值**：`plugin_user_set/get/delete`（替代 usermeta，如 QQ openid 绑定，
  反查可直接 `DB::query('plugin_data')` 按 `data_value` 条件查询）；
- **临时缓存**：`plugin_data_set(..., $ttl)`，过期由内核每日惰性清理。

数组值自动 JSON 编码；读取返回字符串，需数组时自行 `json_decode`。

### 4.2 卸载自动清理

后台「插件管理 → 删除」时内核自动清理：`plugin_{slug}_*` 全部选项、
`plugin_data` 中该插件的行、经 `plugin_register_table()` 登记的自建表。
插件无需自写清理代码；但因此也**不得**把数据写到上述范围之外
（如直接改 users 表结构），否则卸载后残留。

**不规范卸载（直接删文件夹）的安全网**：

1. **启用列表自愈**：`Plugin::activeList()` 每次读取时校验目录存在性，
   目录已消失的 slug 自动从启用列表移除并写审计（`plugin.orphan_deactivate`），
   不会残留“已启用但代码不存在”的状态影响能力判断；
2. **功能入口不失效**：内核能力探测基于声明/钩子而非启用列表，
   目录消失即无声明无钩子，相关功能自动关闭，不报错；
3. **残留数据可回收**：后台「插件管理」页自动检测目录已删但仍有
   `plugin_data` 行或 `plugin_{slug}_*` 选项的插件，提供「清理残留数据」
   按钮（`plugin/cleanup_orphan`，管理员权限 + CSRF + 审计留痕），
   复用卸载回收逻辑全量清理。

### 4.3 插件多语言约定（plugin_t）

插件用户可见文案一律经 `plugin_t($slug, $key, $args, $default, $lang)` 输出，
语言包随插件目录自带，不占用内核/后台语言包命名空间：

- **目录约定**：`plugins/{slug}/langs/zh_CN.php`（中文基线，**必备**）与可选
  `plugins/{slug}/langs/{xx_XX}.php`；文件为可执行 PHP（`return array(...)`），
  文件头必须带 `defined('APP_BOOT') or exit;` 守卫，可含 `_name` 显示名键。
- **查找顺序**：当前语言插件包 → 插件 zh_CN 基线 → `$default`（中文缺省，调用点
  建议始终给出，保证语言包缺失时可运行）→ 原样返回键名（便于排查遗漏）。
- **语言选择**：缺省取站点默认语言（`admin_locale`，即后台/外发邮件语言）；
  可用第 5 参 `$lang` 强制指定语言码。外发邮件（如验证码邮件）**必须**使用站点
  默认语言，禁止跟随请求者浏览器语言。
- **占位参数**：与内核一致，使用 `%s` 位置占位（`msg_format` 格式化）。
- **zip 上传安装**：`langs/` 目录随插件包一并上传，无需额外登记。

随包插件 `smtp-mailer` 为完整参考实现（`aliyun-sms`/`qq-login` 面向国内服务，文案保持中文硬编码，不做多语言）。

## 5. 后台设置页

在 `admin_menu` 钩子中注册，回调内输出 HTML（自行用 `e()` 转义）：

```php
add_action('admin_menu', function () {
    register_plugin_page('hello-world', 'Hello World', 'hello_world_page');
});

function hello_world_page()
{
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // 后台所有 POST 均已由内核统一校验 CSRF，此处直接读取经校验器的输入
        $text = input_text('hw_text', '', 100, 'post');
        plugin_option_update('hello-world', 'text', $text);
        plugin_log('hello-world.save', array('result' => 'success'));
        echo '<p class="tip">已保存</p>';
    }
    $text = plugin_option('hello-world', 'text', '你好，世界');
    echo '<form method="post">';
    echo Csrf::field();
    echo '<div class="form-row"><label>文案</label>'
        . '<input type="text" name="hw_text" value="' . e($text) . '"></div>';
    echo '<button class="btn" type="submit">保存</button></form>';
}
```

设置页入口自动收进后台左侧「插件」二级菜单（仅 `manage_plugins` 权限用户可见）；
未启用任何插件（无设置页注册）时该菜单不显示。设置页表单必须携带
`Csrf::field()`（内核对后台 POST 统一校验，缺失会返回 419）。

## 6. 验证码插件契约（`register_verify_provider` / `send_verify_code` / `verify_code_check`）

- **渠道能力声明（必须）**：验证码发送插件必须在主文件加载期调用
  `register_verify_provider($channel)`（目前支持 `email`/`sms`）声明渠道能力。
  内核入口探测（注册/找回密码/改绑是否要求验证码、发送接口放行）与
  核验策略读取**仅认声明，不硬编码任何插件名**——第三方插件声明并挂接
  发送钩子后即可完整接管对应渠道。声明仅在插件执行上下文内有效，
  强制归属声明者，不可冒名；同渠道多个声明者以后声明者为准。
- 场景（`$scene`）取值：`register`（注册）、`reset`（找回密码）、
  `profile`（后台个人资料改绑邮箱/手机，仅登录用户可触发）。
- 发送：内核已完成频率限制（60s 间隔、每日 ≤10、IP 限流，内核强制不可绕过）后
  调用过滤器，参数为 `($handled=false, $scene, $target, $channel, $code)`。
  插件接管时须自行把验证码写入 `verify_codes` 表（或采用云端生成模式），
  然后返回 `true`；返回其它值视为未接管。
- 核验：`($result=null, $scene, $target, $code, $channel)`。返回 `bool` 即接管；
  返回 `null` 回退本地 `verify_codes` 表核验。
  本地核验与发送共用 `vsend_` 目标命名锁（渠道 + 小写归一后的目标），错误计数原子递增，
  仅裁决该场景/渠道/目标的最新记录；最新码消费、作废或过期后不得回退旧码。
  **接管核验的插件必须自行实现等价安全策略**（并发错误次数控制、有效期、
  最新码裁决、一次性消费、审计留痕），此项为契约责任，内核无法运行时强制。
- **错误容忍次数归声明者插件管理**：内核本地表核验时按渠道声明读取
  `plugin_option($声明者, 'max_attempts', 2)`（范围 1-5 由内核钳制，缺省 2），
  错误达到上限即置 `used=1` 作废。验证码发送插件应在自己的设置页提供该配置项。
- 安全基线内核强制、不可被插件配置掉：重发间隔/每日上限/IP 限流、
  原子消费（一次性）、无预检接口、统一失败文案、发送与核验全程审计。

## 7. 安全要求（违反将造成系统级漏洞）

1. 所有输出必须 `e()` 转义；富文本须白名单过滤。
2. 不得绕过 CSRF（内核对后台写入动作强制 POST 并统一校验；插件设置页仍允许 GET 渲染，写入必须在 POST 分支执行，不得自建旁路表单）。
3. 日志不得含明文密码、验证码、密钥、授权码；邮箱/手机号必须脱敏（detail 中键名为 `content` 的业务正文快照除外，保留原文以满足取证一致性）。
4. 不得引入 Composer 依赖与运行时 CDN 资源。
5. PHP 7.4 语法兼容（允许箭头函数、`??=` 等 7.4 特性；禁止仅 8.0+ 支持的语法/函数）。
6. **不得跨插件写入其它插件的命名空间**：内核按执行上下文（插件主文件加载、
   钩子回调、设置页回调期间自动识别归属插件）强制校验 `plugin_option_update` /
   `plugin_data_*` / `plugin_user_*` / `plugin_register_table` 的写入目标，
   越界写入将被静默拒绝并记入 `security` 审计（action=`plugin.write_denied`）。
    读取不受限制。注意：插件仍是管理员安装的可信服务端代码，本机制拦截的是
    插件间的配置/数据篡改路径，不构成完整沙箱。
7. **插件激活即执行 PHP 代码**：启用插件后其主文件随每次请求被内核加载，等同于
   把站点代码执行权交给插件作者。只安装/启用来源可信的插件；`plugins/` 下 PHP
   直接访问由重写规则拦截（宝塔面板可能因自带 PHP 规则优先匹配而失效，见根 README 伪静态节）。

## 8. 完整示例

- `plugins/hello-world/hello-world.php`：钩子与设置页基础用法。
- `plugins/qq-login/`：自定义路由（OAuth 回调）、登录页/个人资料页钩子注入、
  `plugin_data` 用户级绑定存储、内核 IP 限流与 state 防伪的完整实战。
  回调计数使用内核 `options.throttle_qq_callback_*`（每日惰性清理），绑定命名锁失败即拒绝写入；
  旧版本 `plugin_data` 中的 `rl_*` 临时计数按既有到期清理机制回收，无需迁移。
- `plugins/comment-guard/`：文章级评论三态管制——后台表单注入（`post_edit_fields`/`post_saved`）、
  服务端评论写拦截（`comment_write_allowed`）、前台条件渲染（`comment_area_state`）的组合实战。
