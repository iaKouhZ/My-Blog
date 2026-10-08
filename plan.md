# 个人博客系统实施方案（plan.md）

> 本文件是完整的实施蓝图，任何模型/开发者应严格按照本方案执行。
> 配套的强制性约束见同目录 `AGENTS.md`，两者冲突时以 `AGENTS.md` 的硬性约束为准。

---

## 0. 项目目标与技术约束

### 0.1 目标
从零构建一个功能完整的个人博客系统，包含：安装程序、前台博客、用户/管理后台、权限体系、插件机制（含 SMTP 与阿里云短信两个预装插件）、统一日志、模板机制（默认模板仿宣纸风极简风格）。

### 0.2 硬性技术约束
| 约束 | 说明 |
|---|---|
| PHP 版本 | 必须兼容 **PHP 7.4+**。允许 7.4 及以下语法/函数（`fn` 箭头函数、`??=`、类型属性、数组内展开等）；禁止仅 PHP 8.0+ 支持的语法/函数（命名参数、`match`、构造器属性提升、`enum` 等）。所有代码交付前需通过 `php -l`（7.4 语法）检查。 |
| 数据库 | **MySQL 5.7.44+**，统一使用 **PDO (pdo_mysql)** + 预处理语句；禁止使用 mysqli、mysql_*；所有表名必须带可配置前缀。 |
| 依赖管理 | **禁止使用 PHP Composer** 及任何需要 Composer 加载的库。第三方 JS/CSS 库以本地化静态文件方式引入。 |
| 伪静态 | 所有浏览器访问路径均为伪静态路径（见 §3）。单一入口 `index.php`；同时提供 `.htaccess`（Apache mod_rewrite）与 `nginx.conf.example`；重写不可用时回退 `index.php?r=...` 形式。 |
| 资源本地化 | 所有第三方 JS/CSS（Vditor、KaTeX、highlight.js 等）必须下载到 `assets/vendor/`，其附带的字体文件（如 KaTeX fonts）也必须本地化，禁止运行时引用 CDN。 |

### 0.3 已确认的产品决策
1. **编辑器**：采用 **Vditor**（Markdown，所见即所得/即时渲染/分屏三模式，原生支持 KaTeX、代码高亮、Mermaid，纯静态 dist 资源，无构建链依赖）。前台渲染用本地化 KaTeX + highlight.js。研判过程与备选（TinyMCE、Editor.md）见 §9。
2. **伪静态服务器**：Apache 与 Nginx 均支持。
3. **审核机制**：
   - 评论：游客可阅读**已公开（审核通过）**的评论；后台提供"评论审核"开关，开启时新评论为待审核状态，管理员审核后显示。
   - 文章：后台提供"文章审核"开关，开启时编辑发布的文章为 pending，**仅管理员**可审核发布；关闭时编辑可直接发布。
4. **注册**：公开注册仅产生**普通用户（user）**角色；editor、admin 角色只能由管理员在后台授予。
5. **安全合规**：审计日志、防注入、口令策略、登录失败处理等按**网络安全等级保护二级（GB/T 22239-2019）**相关要求设计（集中见 §6、§11、§12）；**密码过期功能预留但默认不启用**（见 §6.2）。

---

## 1. 目录结构

浏览器访问路径 = 实际目录结构（伪静态路由除外）。

```
/                          ← 站点根目录
├── index.php              ← 前台单一入口（伪静态路由分发）
├── .htaccess              ← Apache 重写规则
├── nginx.conf.example     ← Nginx 重写配置示例
├── config.php             ← 安装程序生成；安装前不存在；禁止 HTTP 直接访问
├── README.md              ← 安装/部署/伪静态配置/插件开发指引
├── install/               ← 安装程序（安装完成生成 install.lock 后拒绝访问）
│   ├── index.php
│   └── ...（步骤页、环境自检逻辑）
├── user/                  ← 后台单一入口目录
│   └── index.php          ← /user/login、/user/admin、/user/center 均由此分发
├── core/                  ← 内核（禁止 HTTP 直接访问，rewrite 屏蔽）
│   ├── bootstrap.php      ← 内核引导：加载 config、DB、Session、Hook、Logger
│   ├── Config.php
│   ├── DB.php             ← PDO 封装（预处理、表前缀、查询构造辅助）
│   ├── Router.php         ← 伪静态路由解析与 URL 生成
│   ├── Hook.php           ← add_action/do_action/add_filter/apply_filters
│   ├── Logger.php         ← 统一日志组件
│   ├── Auth.php           ← 登录态、角色、能力点 check_cap()
│   ├── Csrf.php           ← CSRF token 生成与校验
│   ├── Utils.php          ← e() 转义、分页、时间格式化等
│   ├── Markdown.php       ← Markdown→HTML + 服务端 XSS 白名单过滤（DOMDocument）
│   ├── Plugin.php         ← 插件发现、加载、启用状态管理
│   ├── Lang.php           ← 后台多语言：语言包发现、按需加载、中文基线降级
│   └── langs/
│       └── zh_CN.php      ← 后台中文基线语言包（所有语言的最终降级方案，禁止删除）
├── themes/
│   └── default/           ← 默认模板（仿 qyqiu.cn），结构见 §10
│       └── langs/         ← 主题语言包（zh_CN.php 中文基线随主题发布 + en_US.php 等；主题资源随主题目录存放，禁止放 assets/）
├── plugins/
│   ├── smtp-mailer/       ← 预装 SMTP 发信插件（默认禁用）
│   │   ├── smtp-mailer.php
│   │   └── README.md
│   └── aliyun-sms/        ← 预装阿里云短信插件（默认禁用）
│       ├── aliyun-sms.php
│       ├── AliyunRpc.php  ← 纯 PHP RPC 签名客户端（约 200 行）
│       └── README.md      ← 阿里云开通指引
├── assets/
│   ├── vendor/            ← 本地化第三方资源
│   │   ├── vditor/        ← dist 裁剪版（裁剪清单见 assets/vendor/README.md）
│   │   ├── katex/         ← css/js/fonts 全套
│   │   └── highlight.js/  ← 按需语言包 + 主题 css
│   ├── admin/             ← 后台自有 css/js
│   └── langs/             ← 后台语言包（仅手动放置，禁止 HTTP 直接访问；预装 en_US.php）
└── uploads/               ← 上传目录（头像 uploads/avatars/，文章图片按年月分目录）
```

---

## 2. 数据库设计

所有表名带安装时配置的前缀（默认 `cb_`），代码中通过 `DB::table('users')` 自动加前缀。字符集 `utf8mb4`，引擎 InnoDB。

### 2.1 `{prefix}users`
| 字段 | 类型 | 说明 |
|---|---|---|
| id | INT UNSIGNED AUTO_INCREMENT PK | |
| username | VARCHAR(32) UNIQUE | 登录名 |
| nickname | VARCHAR(64) | 显示昵称 |
| password | VARCHAR(255) | `password_hash(PASSWORD_DEFAULT)` |
| email | VARCHAR(128) UNIQUE | 可用于登录/找回密码 |
| phone | VARCHAR(20) | 手机号，可用于短信验证 |
| avatar | VARCHAR(255) | 头像路径，空则用默认头像 |
| signature | VARCHAR(255) | 个性签名，用户在个人资料页自助设置，展示于作者页（注销用户匿名化时一并隐藏） |
| role | ENUM('admin','editor','user') | **权限唯一判定字段** |
| status | TINYINT | 1 正常 0 禁用 |
| is_banned | TINYINT DEFAULT 0 | 1 封禁：已发布内容保留，但禁止登录（既有会话即时失效） |
| is_deleted | TINYINT DEFAULT 0 | 1 注销：禁止登录；前台历史文章/评论作者匿名显示为“用户已注销”、头像恢复默认（数据不删除） |
| password_changed_at | DATETIME NULL | 最近一次改密时间；安装/注册时初始化为创建时间（密码过期功能用，见 §6.2） |
| login_fail | INT UNSIGNED DEFAULT 0 | 连续登录失败次数（等保二级登录失败处理，见 §6.3） |
| locked_until | DATETIME NULL | 账号锁定截止时间，NULL 表示未锁定 |
| created_at | DATETIME | |

### 2.2 `{prefix}posts`
| 字段 | 类型 | 说明 |
|---|---|---|
| id | INT UNSIGNED AUTO_INCREMENT PK | |
| author_id | INT UNSIGNED | 关联 users.id |
| title | VARCHAR(255) | |
| slug | VARCHAR(255) UNIQUE | 伪静态用别名，空则回退 id |
| content | MEDIUMTEXT | Markdown 原文 |
| excerpt | TEXT | 摘要，空则自动截取 |
| category_id | INT UNSIGNED | |
| cover | VARCHAR(255) | 封面图，存 `uploads/...` 相对路径 |
| status | ENUM('draft','pending','published','trash') | 文章审核开关见 §0.3 |
| views | INT UNSIGNED DEFAULT 0 | |
| created_at / updated_at | DATETIME | |

### 2.3 `{prefix}comments`
| 字段 | 类型 | 说明 |
|---|---|---|
| id | INT UNSIGNED AUTO_INCREMENT PK | |
| post_id | INT UNSIGNED | |
| user_id | INT UNSIGNED | 评论必须登录后发表 |
| parent_id | INT UNSIGNED DEFAULT 0 | 支持一层回复 |
| content | TEXT | |
| status | ENUM('pending','published','trash') | 评论审核开关见 §0.3 |
| created_at | DATETIME | |

### 2.4 `{prefix}categories`
id / name / slug UNIQUE / description / sort。

### 2.5 `{prefix}options`
`option_key` VARCHAR(64) PK / `option_value` MEDIUMTEXT。存储：站点名、座右铭、SEO、每页条数、文章审核开关、评论审核开关、启用模板、启用插件列表（JSON）、各插件配置（命名空间 `plugin_{slug}_*`）。

### 2.6 `{prefix}verify_codes`
| 字段 | 说明 |
|---|---|
| id PK | |
| scene | ENUM('register','reset','profile')（profile=后台改绑邮箱/手机） |
| target | 邮箱或手机号 |
| code | 6 位数字 |
| channel | ENUM('email','sms') |
| expires_at | 10 分钟有效 |
| used | TINYINT |
| attempts | 错误尝试计数；上限由渠道声明者管理，缺省 2、内核钳制 1–5，达到上限立即作废 |
| created_at | 用于 60s 重发间隔与每日上限判断 |

### 2.7 `{prefix}logs`（统一审计日志，等保二级）
| 字段 | 类型 | 说明 |
|---|---|---|
| id | BIGINT UNSIGNED AUTO_INCREMENT PK | |
| user_id | INT UNSIGNED | 操作者；0 表示游客/系统（如登录失败时尚无身份） |
| role | VARCHAR(16) | 操作发生时的角色快照（admin/editor/user/guest/system） |
| category | VARCHAR(24) | 事件类别：auth/post/comment/user/setting/template/plugin/verify/security |
| action | VARCHAR(64) | 具体动作，如 `login`、`login.fail`、`user.role_change` |
| result | ENUM('success','fail') | 事件结果（等保要求记录成败） |
| detail | TEXT | 脱敏后的详情（JSON），禁止含明文密码/验证码/密钥；键名 `content` 为正文快照（如评论发布/修改），保留原文不打码 |
| ip | VARCHAR(45) | 支持 IPv6 |
| ua | VARCHAR(255) | |
| created_at | DATETIME | |

约束：
- **只增不改**：系统不提供任何修改/删除单条日志的接口（含数据库层封装中禁止暴露 logs 的 UPDATE/DELETE 方法）；仅允许到期自动归档清理与管理员导出。
- **留存期**：默认保留 **≥180 天**（满足《网络安全法》第 21 条"不少于六个月"），存 options `log_retention_days`，可配置但下限 180；到期由计划任务/惰性清理归档删除。
- 日志查询/导出操作本身也写入一条 `category=security` 的审计记录。
- 建议运维侧对 logs 表定期备份（README 提供 mysqldump 指引）。

---

## 3. 伪静态路由

### 3.1 前台路由（浏览器可见路径）
| 路径 | 含义 |
|---|---|
| `/`、`/page/{n}` | 首页及分页 |
| `/post/{id}.html`、`/post/{slug}.html` | 文章详情 |
| `/category/{slug}`、`/category/{slug}/page/{n}` | 分类归档 |
| `/author/{id}` | 作者归档 |
| `/page/{slug}.html` | 独立页面（如"关于我"） |
| `/search?q=...` | 搜索 |
| `/login`、`/register`、`/forgot` | 认证表单页（前台模板渲染） |
| `/lang/{code}` | 前台语言切换（GET，写 `cb_theme_lang` cookie 偏好后安全回跳；仅白名单语言码生效） |
| `/user/...` | 后台（独立目录入口） |
| `/install/...` | 安装程序 |

### 3.2 实现要点
- `Router.php` 解析 `PATH_INFO`/重写后的 `r` 参数，正则匹配路由表。
- URL 生成统一走 `Router::url('post', ['id'=>1])`，**禁止在模板中手写路径**，保证重写开关切换时链接一致。
- 回退模式：未启用重写时 URL 为 `index.php?r=post/1.html`，由 install 自检或后台设置决定。
- `.htaccess`：所有非真实文件/目录的请求重写至 `index.php`；同时屏蔽 `config.php`、`core/`、`assets/langs/`（语言包目录），并禁止 `plugins/`、`themes/` 下 PHP 文件直接执行（与 Nginx 规则对齐）。
- `nginx.conf.example`：等价的 `try_files` + `location` 示例，含 `core/`、`config.php`、`assets/langs/` 拒绝访问规则。
- 三套重写示例（含宝塔）统一拒绝隐藏文件/目录（如 `.git`）；Nginx 的隐藏路径正则须先于通用 PHP 处理规则，`^~ /uploads/` 内另设隐藏路径拦截，避免跳过外层正则。

---

## 4. install/ 安装程序

流程为向导式四步，`install/index.php` 分发；已安装（存在 `install/install.lock` 或根目录 `config.php`，双重守卫）时一律 301 跳转首页，防止锁文件被误删后经重装覆盖 `config.php` 导致站点被接管。

安装向导界面中英双语：文案包为 `install/langs.php`（`install.{步骤}.{语义}` 键，随安装程序发布），经 `ins_t()` 查询（当前语言 → 中文基线 → 原样返回键，`%s` 占位）；界面语言由 `ins_lang()` 决定，优先级为 `?lang=` 显式切换（白名单二值并写 Session）→ Session 已存选择 → 浏览器 `Accept-Language` 首选检测（中文→zh_CN，否则 en_US）。该机制独立于后台 `Lang` 体系——安装期无数据库（`Option` 不可用），且 `assets/langs/` 语言包属用户可删资产，安装器文案不寄生于其中。

1. **环境自检**（不通过则禁止下一步，红绿清单展示）：
   - PHP ≥ 7.4；
   - 扩展：pdo_mysql、mbstring、openssl、json、curl、fileinfo、gd；
   - 可写性：站点根目录（生成 config.php）、`uploads/`；
   - 重写能力：Apache 下探测 mod_rewrite；Nginx 下展示 `nginx.conf.example` 配置指引。
2. **数据库配置**：连接地址、端口（默认 3306）、账户名、数据库名、密码、**表前缀**（默认 `cb_`，仅允许字母数字下划线）。提供"测试连接"按钮（PDO 实测并回报错误信息）。
3. **管理员信息**：用户名、昵称、密码（二次确认；**服务端强制口令复杂度校验，规则同 §6.1**：≥8 位、四类字符至少三类、不得含用户名、不得命中弱口令黑名单）、邮箱、手机号、**后台语言**（`admin_locale`，下拉可选项 = 中文基线 + `assets/langs/` 扫描结果，缺省跟随安装向导界面语言，非法值回退 `zh_CN`）。写入 users 表（role=admin，`password_changed_at` 初始化为当前时间）。
4. **执行安装**：按 §2 建全部表（带前缀）→ 写入默认 options，其中安全策略默认项：`log_retention_days=180`、`login_max_fail=5`、`login_lock_minutes=10`、`session_timeout_minutes=30`、`pwd_expire_enabled=0`（**密码过期默认关闭**）、`pwd_expire_days=90`、`pwd_history_count=0`（密码历史默认关闭）、`ip_header_enabled=0`（**自定义 IP 标头默认关闭**）、`ip_header_name='X-Forwarded-For'`（CDN 真实 IP 标头名，见 §6.4）、`debug=0`；另有站点名、审核开关默认关、默认模板 default、启用插件空列表 → 生成 `config.php`（DB 配置 + 随机 64 位密钥，用于 CSRF/Session 加固）→ 写 `install/install.lock` → 提示安装完成。

---

## 5. user/ 后台与权限体系

### 5.1 入口与身份
- `/user/index.php` 为后台单一入口，内部按 `?m={module}/{action}` 分发（如 `?m=post/list`）。
- 未登录访问任何后台页 → 302 到 `/login`（登录后回跳）。未登录身份 = **游客**，仅能浏览前台文章与已公开评论。
- 页面动作按白名单允许 GET；其他后台动作仅接受 POST（非法方法返回 405），POST 统一校验 CSRF，防止 GET 触发缺省值覆盖配置。插件设置页 GET 仅用于展示，写入必须走 POST 分支。

### 5.2 能力点矩阵（`Auth::check_cap($cap)` 实现）
| 能力 | 管理员 | 编辑 | 用户 | 游客 |
|---|---|---|---|---|
| 浏览文章/公开评论 | ✓ | ✓ | ✓ | ✓ |
| 发表/修改/删除**自己**的评论 | ✓ | ✓ | ✓ | ✗ |
| 新增/编辑文章 | ✓（全部） | ✓ | ✗ | ✗ |
| 删除文章 | ✓ | ✗ | ✗ | ✗ |
| 文章/评论审核 | ✓ | ✗ | ✗ | ✗ |
| 管理全部评论 | ✓ | ✗ | ✗ | ✗ |
| 用户管理、角色授予 | ✓ | ✗ | ✗ | ✗ |
| 站点设置、分类管理 | ✓ | ✗ | ✗ | ✗ |
| 模板/插件 启用·禁用·安装·删除 | ✓ | ✗ | ✗ | ✗ |
| 日志查看/导出 | ✓ | ✗ | ✗ | ✗ |
| 修改自己密码/头像 | ✓ | ✓ | ✓ | ✗ |

说明：编辑默认可编辑所有文章但不删除（该粒度由能力点 `edit_others_posts` 控制，默认对编辑开启）。

### 5.3 后台页面清单
仪表盘、文章管理（列表/新增/编辑/回收站/审核队列）、评论管理（列表/审核队列）、分类管理、用户管理（管理员，含手动解锁被锁账号、"下次登录强制改密"勾选）、站点设置、**安全设置（仅管理员**：口令复杂度开关细则、登录失败次数/锁定时长、会话超时、密码过期功能开关与天数、密码历史开关、**客户端 IP 来源设置（自定义标头开关与标头名，含 CDN 伪造风险提示）**、日志留存天数、登录/审计日志快捷入口**）**、模板管理、插件管理、日志中心（管理员）、个人资料（昵称/邮箱/手机号/密码/头像，所有登录角色；改密必须验证原密码）。
- 仪表盘：所有登录角色均显示时段问候（按早上/中午/晚上显示“昵称，XX好！欢迎访问站点名”）；全站统计（文章总数/已发布/待审核/评论/注册用户等）与审计日志预览**仅管理员可见**，控制器层不向 user/editor 提供该数据。
- 后台 UI：`assets/admin/` 自有 css/js，左侧菜单 + 顶栏布局，响应式；菜单项按能力点动态显隐；导航侧栏固定不随页面滚动（对齐前台主题）；明暗模式切换（与前台默认主题共用 `cb-darkmode` 偏好键）；窄屏下侧栏为抽屉式（☰ 按钮 + 遮罩）。
- 后台多语言（i18n）：`core/Lang.php` 提供 `Lang::t()`/模板助手 `admin_t()`，语义键 `admin.{模块}.{语义}`；中文基线包 `core/langs/zh_CN.php` 为最终降级方案（随内核，禁止删除），其余语言包（`return array(...)` 的 `xx_XX.php`，含预装 `en_US.php`）一律放 `assets/langs/`，仅允许手动放置、无后台上传入口；站点级选项 `admin_locale`（默认 `zh_CN`）在「站点设置」切换，查询链为 当前包 → 中文基线 → 原样返回键；`assets/langs/` 目录为空时后台自动保持中文。后台共享 JS（admin.js/verify.js/password_check.js/nav_sort.js）的提示语经视图注入 `window.CB_*` 覆盖实现，缺省保持中文、前台不受影响。
- 头像上传：限 jpg/png/webp、≤2MB，服务端校验 MIME（fileinfo），重命名为随机文件名存 `uploads/avatars/`。

---

## 6. 注册 / 登录 / 找回密码

- **登录**（`/login`）：单字段接受**用户名或邮箱**（自动判别是否含 `@`）+ 密码；连续失败 5 次锁定 10 分钟（按用户名+IP，失败计数经 SQL 原子自增，并发爆破不可绕过）；CSRF token；登录成功 `session_regenerate_id(true)`。
- **注册**（`/register`）：用户名、昵称、邮箱、手机号、密码；角色固定为 user；按启用的验证插件决定是否需要邮箱/短信验证码（均未启用则免验证直接注册，但保留接入点）；短信插件启用时手机号为必填项且必须通过验证码核验；注册提交按 IP 维度限流（每分钟 ≤10 次），抬高"已占用"提示被批量枚举的成本。
- **找回密码**（`/forgot`）：输入用户名或邮箱 → 经 `send_verify_code` 钩子发送验证码（需 SMTP 或短信插件已启用，否则提示"请联系管理员重置"）→ 验证通过后设置新密码；与账号存在性相关的失败（账号不存在/未绑定联系方式/验证码错误）统一返回同一话术，防止账号枚举。
- **验证码统一机制**：6 位数字、10 分钟有效、同场景同目标 60s 重发间隔、每目标每日 ≤10 条（配额检查与插件写入经 MySQL 命名锁互斥，并发请求无法超限群发）；错误容忍次数归声明者插件管理（内核按渠道声明读取 `plugin_option($声明者,'max_attempts')`，缺省 2、内核钳制 1-5），达到上限立即置 used=1 作废（即使仍在有效期内）；**不提供独立的核验（预检）接口，验证码对错不向前端暴露**，仅在表单提交时经条件更新原子消费一次性裁决（同一验证码并发下仅可用一次），失败统一提示“验证码错误或已过期”；发送接口另设 IP 维度限流（同一 IP 每分钟 ≤10 次，IPv6 按 /64 前缀归一计数，防地址轮换绕过）；存储见 §2.6；发送渠道 email/sms 由已启用插件提供，两者都启用时用户可选。**是否具备验证码能力按渠道声明探测（`register_verify_provider`，见 §7.4），内核不硬编码插件名**，第三方插件声明后即可接管对应渠道。

### 6.1 口令复杂度策略（等保二级·身份鉴别，服务端强制）
以下规则在**注册、安装程序建管理员、后台管理员建号、用户改密**四个入口全部由服务端统一函数 `Auth::validate_password_strength()` 强制校验（前端强度条仅作辅助提示，不作判定依据）：
1. 长度 8–64 位；
2. 字符类别：大写字母 / 小写字母 / 数字 / 特殊字符（``!@#$%^&*()-_=+[]{};:,.?`` 等）四类中**至少包含三类**；
3. 不得与用户名相同，也不得包含用户名（大小写不敏感）；
4. 不得命中内置常见弱口令黑名单（如 `12345678`、`password`、`qwerty123` 等 top-100，存于 `core/` 内常量数组，可经 `password_blacklist` 过滤器由插件扩展）；
5. 修改密码必须验证原密码；管理员后台重置他人密码时生成符合本策略的随机初始密码。
6. （预留）密码历史：options `pwd_history_count`（默认 0=关闭，可设 3/5/10）；开启后新密码不得与最近 N 次密码相同，历史哈希存 `{prefix}password_history` 表（id/user_id/password_hash/created_at，仅在开启时建表并启用校验逻辑）。

### 6.2 密码过期功能（预留，默认不启用）
- options：`pwd_expire_enabled`（**默认 0=关闭**）、`pwd_expire_days`（默认 90，可配 30–365）。
- 数据支撑：`users.password_changed_at`（安装/注册时初始化，每次改密更新）。
- 启用后的行为（开关打开即生效，代码路径预留完整）：
  - `Auth::loginUser()` 对所有角色和登录通道（含管理员、QQ 登录）建立到期标记；`Auth::user()` 每请求按数据库中的 `password_changed_at` 重判，既有会话到期、管理员要求改密或开启策略时也立即生效 → `pwd_expired=1`，**改密完成前禁止访问其他任何后台页面**（路由层统一拦截并审计）；QQ 绑定/解绑同样拦截；
  - 到期前 7 天登录后显示一次性提醒；
  - 强制改密后清除标记并写审计日志（`category=auth, action=password.expired_change`）。
- 管理员在用户管理可勾选"要求下次登录改密"：将目标用户 `password_changed_at` 置为过期阈值之前，复用同一拦截逻辑。
- 开关关闭时以上检查全部短路跳过，不产生任何行为差异。

### 6.3 登录失败处理与会话安全（等保二级·身份鉴别 c/d）
- **锁定**：同一账号连续失败 `login_max_fail`（默认 5）次 → 锁定 `login_lock_minutes`（默认 10）分钟（`users.login_fail` / `locked_until` 持久化，重启不丢；失败计数经 SQL 原子自增——`LAST_INSERT_ID` 表达式模式，并发失败请求不会互相覆盖计数）；锁定期内直接拒绝；成功登录后清零。另按 IP 维度做二级限流（同一 IP 每分钟 ≤20 次登录尝试，独立计数器实现，不依赖审计表聚合；计数器读-改-写经 MySQL 命名锁互斥，IPv6 客户端按 /64 前缀归一计数防地址轮换）。锁定/禁用/封禁/注销等状态的登录失败与密码错误统一提示，防止账号枚举（具体原因仅写入审计日志）。

- **并发裁决与提示一致性**：用户名/邮箱解析为用户 ID 后取账号锁，锁内重读状态并完成失败计数、置锁或成功登录，防止旧快照清除并发生效的锁定。锁定触发的当次失败也统一为 `invalid_credentials`，仅审计保留锁定原因，不再依赖影子账号计数消除提示差异。IP 限流取锁后直接从数据库读取计数，不使用取锁前已加载的 Option 缓存；锁超时拒绝请求。
- **审计**：登录成功、登录失败（含锁定触发）、登出、管理员手动解锁、验证码发送与核验，全部写 logs（`category=auth/verify`，`result=success/fail`）。
- **会话**：无操作 `session_timeout_minutes`（默认 30）分钟自动失效；登出时 `session_destroy()` 并使 cookie 失效（剩余信息保护）；登录成功 `session_regenerate_id(true)`；检测到 HTTPS 时 cookie 自动加 `Secure`；会话携带口令指纹（口令哈希的二级散列），改密（自助/找回/管理员重置）后其余既有会话指纹失配自动失效。
- 登录成功清除认证前 CSRF token，后续按新会话重新生成；检测到禁用/封禁/注销或指纹失配时清除会话身份及 token；登出同步清除当前请求用户缓存。仪表盘审计日志预览同样记录 `security/log.view`。

### 6.4 客户端 IP 获取策略（CDN 兼容，自定义标头默认不启用）
站点经 CDN/边缘安全加速（如阿里云 ESA、DCDN、Cloudflare）回源时，`REMOTE_ADDR` 会变成 CDN 节点 IP，导致审计日志、登录锁定、IP 限流失真。为此提供统一 IP 获取机制：

- **统一入口**：`core/Utils.php` 提供 `client_ip()`，**全站所有需要客户端 IP 的位置（审计日志、登录锁定、IP 限流、验证码频率限制）必须调用该函数**，禁止直接读取 `$_SERVER['REMOTE_ADDR']`。
- **默认行为（`ip_header_enabled=0`）**：直接返回 `REMOTE_ADDR`，不信任任何 HTTP 标头（防止伪造）。
- **启用后行为（`ip_header_enabled=1`）**：从管理员在"安全设置"中**指定的标头名**（`ip_header_name`，支持英文逗号分隔多个标头、在前优先级高，如 `ali-real-client-ip,X-Forwarded-For`；预设常用值可选：`X-Forwarded-For`、`X-Real-IP`、`Ali-CDN-Real-IP`、`EO-Client-IP`、自定义）取值：
  1. 按配置顺序依次尝试每个标头，某个标头取到合法 IP 即停止返回；标头不存在或为空 → 尝试下一个标头；
  2. 所有标头均未取到 → 回退 `REMOTE_ADDR`；
  3. 单个标头值为逗号分隔列表（XFF 链）→ 取**第一个** IP；
  4. 取值必须经 `filter_var($ip, FILTER_VALIDATE_IP)`（支持 IPv4/IPv6）校验，非法 → 视为未取到，继续下一标头；
  5. 每个标头名只允许字母、数字、连字符，映射到 `$_SERVER['HTTP_*']` 时统一大写、`-`→`_`。
- **安全提示（写入设置页与 README）**：该功能**仅应在站点确实位于可信 CDN/反代之后时启用**；直连源站可访问的情况下启用会导致访客可伪造日志 IP 与绕过 IP 限流，需配合"源站仅放行 CDN 回源 IP 段"使用。
- 该功能开关与标头名变更属于安全设置变更，写 `category=security` 审计日志。

---

## 7. 插件系统（仿 WordPress 规范）

### 7.1 插件目录规范
- 每个插件为 `plugins/{slug}/` 目录，主文件 `{slug}.php`。
- 主文件头部注释声明元数据（解析方式同 WP）：
```php
<?php
/**
 * Plugin Name: SMTP Mailer
 * Description: 注册与找回密码时发送邮件验证码
 * Version: 1.0.0
 * Author: ...
 * Requires: 1.0
 */
```
- 启用状态存于 options（JSON 列表）；仅启用插件的主文件在 `init` 时被 require。

### 7.2 Hook 机制（core/Hook.php，语义与 WP 一致）
- `add_action($hook, $callback, $priority = 10)` / `do_action($hook, ...$args)`
- `add_filter($hook, $callback, $priority = 10)` / `apply_filters($hook, $value, ...$args)`

### 7.3 预置钩子（内核必须在这些点位触发）
| 钩子 | 类型 | 说明 |
|---|---|---|
| `init` | action | 内核引导完成 |
| `front_head` / `front_footer` | action | 前台页头/页尾输出点 |
| `admin_head` / `admin_footer` | action | 后台页头/页尾输出点 |
| `post_content` | filter | 文章正文渲染后输出前 |
| `front_posts` | filter | 首页/归档/搜索列表当前页文章（排序/修饰；分页已定，不宜移除） |
| `comment_before_save` | filter | 评论入库前（可拦截/改写） |
| `comment_write_allowed` | filter | 前台评论增/改/删统一拦截点（返回 false 拒绝，记审计；后台不受影响） |
| `post_saved` | action | 文章保存完成后（插件持久化随文章的扩展数据） |
| `post_deleted` | action | 文章彻底删除后（插件清理随文章的扩展数据） |
| `admin_menu` | action | 注册后台菜单项/设置页 |
| `route_parse` / `front_route_{名}` | filter/action | 插件认领自定义路由（OAuth 回调/Webhook） |
| `user_register` | action | 注册前置校验（验证码核验在此接入） |
| `password_reset` | action | 找回密码前置校验 |
| `send_verify_code` | filter | 发送验证码：插件返回 true 表示已接管发送 |
| `verify_code_check` | filter | 验证码核验：插件返回 bool |
| `plugin_activate` / `plugin_deactivate` / `plugin_uninstall` | action | 插件生命周期 |

另有主题/视图触发的注入点（默认主题已实现，见 `themes/README.md` §5.1 与
`plugins/README.md` §3）：`auth_form_footer`（登录/注册/找回表单下方，按页面参数区分）、
`post_card_before/after`（列表卡片前后）、`single_content_after`（文章正文后）、
`comments_before/after`（评论区前后）、`page_content_after`（独立页面正文后）、
`author_header_after`（作者归档页头部区块内末尾）、`sidebar_widgets`（前台侧边栏）、
`post_edit_fields`（后台文章编辑表单内）、`post_list_row_actions`/`comment_list_row_actions`（后台列表行内操作）、
`comment_area_state`（评论区条件渲染：list/form/actions 三态开关）、`profile_cards`（后台个人资料页）。

### 7.4 插件 API
`plugin_option($slug,$key,$default)`、`plugin_option_update()`、`plugin_log($action,$detail)`（接统一日志）、`plugin_url($slug,$path)`、设置页渲染辅助函数。
写入类 API（`plugin_option_update`/`plugin_data_*`/`plugin_user_*`/`plugin_register_table`）受命名空间强制校验：内核按执行上下文（插件加载、钩子回调、设置页回调期间自动识别归属插件）拒绝跨插件写入，违规记 `security` 审计；读取不受限。
验证码渠道能力声明：`register_verify_provider($channel)`（仅插件加载期可调、强制归属声明者）与 `get_verify_provider($channel)`；内核对验证码能力的探测与策略读取仅认声明，不硬编码插件名。

本地验证码核验与发送共用 `vsend_` 目标锁（渠道 + 小写归一后的目标），防止邮箱大小写别名分到不同锁；错误计数原子递增，核验只裁决同场景/渠道/目标的最新记录，最新码消费、作废或过期后不得回退旧码。消费提交时复检 `used`、尝试次数与有效期，锁超时拒绝核验。接管 `verify_code_check` 的插件须自行实现相同的并发次数控制、最新码裁决与一次性消费契约。

QQ 登录沿用 `plugin_data` 用户级绑定结构；回调限流复用内核 `ip_throttle_allow('qq_callback', 10)`，计数存于现有 options 的 `throttle_qq_callback_*`，IPv6 按 /64 归一。绑定 OpenID 查重与写入必须成功取得命名锁，否则拒绝写入；密码过期开启后，登录、绑定与解绑均服从内核到期判定。旧插件 `rl_*` 临时计数沿用既有到期清理，无需新增字段或迁移。
插件多语言：`plugin_t($slug,$key,$args,$default,$lang)` + 插件自带 `langs/{xx_XX}.php`（zh_CN 基线必备、APP_BOOT 守卫），查找顺序为当前语言包 → 插件中文基线 → 中文缺省 → 键名；语言缺省取站点默认语言（`admin_locale`），外发邮件必须用站点默认语言。完整约定见 `plugins/README.md` §4.3。

### 7.5 插件管理页（仅管理员）
列表（名称/版本/作者/描述/状态）、启用、禁用、删除（删除目录，二次确认）、插件设置页入口。插件文件必须位于 `plugins/` 且头部元数据合法才会被发现。
支持后台上传插件 zip 安装：slug 由包内 `{slug}.php` 主文件推导（头部须含 `Plugin Name`），条目安全白名单与主题上传共用 `core/ZipSafe.php`；同名插件执行覆盖更新（`Plugin Name` 不一致拒绝、旧目录备份+失败回滚），审计记 `plugin.upload`/`plugin.update` 成败两类。
不规范卸载安全网：启用列表读取时自动剔除目录已不存在的 slug（写审计）；列表页检测目录已删但仍有残留数据的插件，提供一键清理入口（复用卸载回收逻辑）。

### 7.6 插件开发文档
`plugins/README.md` 或根 README 中附完整开发规范与示例（Hello World 插件）。

---

## 8. 预装默认插件（均默认禁用）

### 8.1 smtp-mailer（SMTP 发信）
- 纯 PHP `fsockopen`/`stream_socket_client` 实现 SMTP 客户端类（EHLO/HELO、AUTH LOGIN、SSL 与 STARTTLS、UTF-8 主题 MIME 编码），零外部依赖。
- 设置页（`admin_menu` 钩子注册）：SMTP 主机、端口（25/465/587）、加密方式（none/ssl/tls）、账号、授权码、发件人名称；提供"发送测试邮件"按钮。
- 挂 `send_verify_code`：生成验证码写入 `verify_codes`（channel=email）并发送邮件，返回 true 接管。
- 写统一日志：`plugin_log('smtp.send', 'to=脱敏邮箱 scene=xxx result=ok/fail')`，**日志禁止出现明文验证码与授权码**。

### 8.2 aliyun-sms（阿里云短信认证）
- 实现 `AliyunRpc.php`：约 200 行纯 PHP RPC 签名客户端（仅依赖 cURL）：
  - 参数：`Action`、`Format=JSON`、`Version=2017-05-25`、`AccessKeyId`、`SignatureMethod=HMAC-SHA1`、`SignatureNonce`、`SignatureVersion=1.0`、`Timestamp`(GMT ISO8601) + 业务参数；
  - 签名：`ksort` 参数 → `rawurlencode` 键值拼接 `&` → `HTTPMethod&%2F&` + rawurlencode(查询串) → `base64(hash_hmac('sha1', $strToSign, $secret.'&', true))`；
  - HTTPS POST `https://dypnsapi.aliyuncs.com`。
- 仅实现一个动作 `SendSmsVerifyCode`，固定**本地生成码模式**：
  - 验证码由内核生成（6 位数字），经 TemplateParam 以具体值下发（如 `{"code":"123456","min":"10"}`，不传 `##code##` 占位符）；官方文档明确该模式下阿里云接口无法校验验证码，核验完全由内核本地 `verify_codes` 表执行；
  - 参数：PhoneNumber、CountryCode 默认 86、SignName（仅支持赠送签名，需搭配赠送模板）、TemplateCode、TemplateParam（必填）、ValidTime、Interval、SchemeName（选填，默认空，空值由 AliyunRpc 自动剔除不参签）、OutId；CodeType/CodeLength 仅占位符模式适用，本地生成码模式不传；
  - 不实现 `CheckSmsVerifyCode`，不挂 `verify_code_check`。
- 设置页：AccessKeyId、AccessKeySecret、SignName、TemplateCode、SchemeName（选填，默认空）、ValidTime、Interval、验证码错误容忍次数（声明者配置，内核钳制 1-5）。
- 错误码映射为友好提示：`MOBILE_NUMBER_ILLEGAL`、`BUSINESS_LIMIT_CONTROL`、`FREQUENCY_FAIL`、`INVALID_PARAMETERS`、`FUNCTION_NOT_OPENED`。
- 挂 `send_verify_code`；发送成功后写入可核验行（used=0），错误容忍/有效期/原子消费与其它渠道完全一致；发送写统一日志（手机号脱敏、不含明文验证码、不记录 AccessKeySecret），核验经内核统一 `verify.check` 审计。
- `plugins/aliyun-sms/README.md`：阿里云控制台开通号码认证服务、创建签名/模板、RAM 授权（`dypns:SendSmsVerifyCode`）的指引。

---

## 9. 编辑器研判结论（对应需求第 7 项）

| 候选 | 结论 | 理由 |
|---|---|---|
| **Vditor** | ✅ **采用** | 国产成熟开源 Markdown 编辑器；单 dist 目录即可用，无构建链依赖，与"无 Composer + 静态资源本地化"环境最契合；原生支持 KaTeX 数学公式、代码高亮、Mermaid；所见即所得/即时渲染/分屏三种模式；中文文档完善 |
| TinyMCE | 备选不采用 | 富文本方案，数学公式支持弱、需额外插件、体积大 |
| Editor.md | 不采用 | 已停止维护，存在安全隐患 |

实施要点：
- `assets/vendor/vditor/` 本地化 dist 裁剪版（保留运行必需的 lute/katex/highlight.js/icons/i18n 与字体/图标资源；已移除 dev/类型文件、mathjax 与未使用的图表语法懒加载库，清单见 assets/vendor/README.md）。
- 后台文章编辑页集成 Vditor（Markdown 模式），数据库存 Markdown 原文。
- 前台渲染：`core/Markdown.php` 将 Markdown 转 HTML（自研轻量解析或纯 PHP 单文件解析库），随后用**基于 DOMDocument 的标签/属性白名单**做服务端 XSS 过滤；KaTeX 与 highlight.js 本地化渲染公式与代码块（`$...$`、`$$...$$`、围栏代码块）。

---

## 10. 默认模板（仿 https://qyqiu.cn/）

已勘察参考站（极简个人博客风格）。默认模板 `themes/default/` 必须还原以下特征：

- **布局**：顶部站点名 + 一句话座右铭；作者卡片（头像、昵称、签名简介）；侧边栏含搜索框（"搜索："）、导航菜单、分类列表（带文章数）；主栏为倒序文章列表，每项含标题、作者、日期、分类、摘要、"继续阅读"链接；页脚版权行（`© {起始年}-{当前年} {站点名} All rights Reserved.`）。
- **交互**：☾ 明暗主题切换（localStorage 记忆，跟随系统偏好为默认）；☰ 移动端折叠菜单；全站响应式。
- **页面类型**：`index.php`（首页/列表）、`single.php`（文章详情 + 评论区 + 评论表单[登录可见]）、`page.php`（独立页面如"关于我"）、`archive.php`（分类/作者归档）、`search.php`、`404.php`。
- **模板结构（仿 WP）**：`header.php`、`footer.php`、`sidebar.php`、`functions.php`（主题自己的钩子/助手）、`style.css`。
- **主题多语言（i18n）**：语言包随主题放 `themes/{dir}/langs/xx_XX.php`（`return array(...)`，键规范 `theme.{模块}.{语义}`，`_name`/`_locale` 为包元信息），`langs/zh_CN.php` 为随主题发布的中文基线（最终降级方案，禁止删除）；模板与前台控制器文案经 `theme_t()` 查询（当前语言包 → 主题中文基线 → 中文缺省参数 → 原样返回键，支持 `%s` 占位）；语言解析链：访客 `cb_theme_lang` cookie（侧边栏语言切换器经 `/lang/{code}` 路由写入，HttpOnly+SameSite=Lax，HTTPS 下 Secure）→ 浏览器 `Accept-Language`（q 值降序，先精确后按主标签族匹配，仅白名单主题实有包）→ 后台 `admin_locale`（主题存在同名包时沿用）→ `zh_CN`；`<html lang>` 经 `Theme::locale()` 输出，日期格式经 `theme_date()` 随包内 `theme.common.date_format` 切换；主题 JS 文案在无 CSP 页面经 `window.CB_*` 内联注入（复用内核 verify.js/password_check.js 的覆盖机制），文章/独立页受受限 CSP 约束改走元素 `data-*` 属性传递。无 `langs/` 目录的主题行为与硬编码中文时期完全一致（内核侧 `theme_t` 调用必带中文缺省参数）。默认主题随包中英双语（`langs/zh_CN.php` + `langs/en_US.php`）。
- **模板 API**：`site_name()`、`site_motto()`、`the_posts()`、`the_title()`、`the_date()`、`the_category()`、`the_excerpt()`、`the_content()`、`paginate()`、`comment_list()` 等；模板内禁止直接操作 DB。
- **模板管理页**（仅管理员）：主题列表（读取 style.css 头部元数据：Theme Name/Author/Version/Description）、启用、禁用、删除；支持上传主题 zip 包（服务端校验后解压到 `themes/`），同名主题可覆盖更新（`Theme Name` 不一致拒绝；`default` 与启用中主题不可覆盖；旧目录备份+失败回滚），审计记 `theme.upload`/`theme.update` 成败两类。

---

## 11. 统一日志组件（等保二级·安全审计）

按等保二级"安全审计"控制点（审计覆盖每个用户、记录重要安全事件、记录含主体/客体/类型/结果/时间、审计记录受保护）设计：

- **API**：`core/Logger.php` 提供 `blog_log($category, $action, $result, $detail)`，自动附带当前 user_id、role 快照、IP（**经 `client_ip()` 获取**，CDN 标头机制见 §6.4）、UA，写入 `logs` 表（结构见 §2.7）；插件经 `plugin_log($action, $detail)` 接入同一存储（category 自动标记来源插件 slug）。
- **必须审计的事件（含成功与失败两类结果）**：
  - auth：登录成功/失败、登出、账号锁定/解锁、密码修改、强制改密拦截；
  - user：用户增删改、角色/权限变更、账号禁用/启用；
  - security：审计日志的查询/导出、安全设置变更；
  - post/comment：发布、修改、删除、审核；
  - setting/template/plugin：站点设置变更、模板与插件的启用/禁用/安装/删除；
  - verify：验证码发送与核验（由 SMTP/短信插件接入）。
- **审计记录保护（只增不改）**：Logger 与 DB 封装不提供 logs 表的 UPDATE/DELETE 接口；后台无编辑/删除单条日志功能；仅支持到期自动归档清理（按 `log_retention_days`，默认 180 天，下限 180）与管理员导出归档。
- **日志中心**（仅管理员）：按用户/类别/动作/结果/日期/IP 组合筛选、分页、CSV 导出；查询与导出行为本身写入 `category=security` 审计记录。CSV 导出对全部字段做公式注入中和（`csv_safe()`：以 `=` `+` `-` `@` 或 TAB/CR 开头的值前置单引号），防止 UA/详情等攻击者可控内容在 Excel/WPS 中被当作公式执行。
- **脱敏红线**：日志与报错中禁止出现明文密码、明文验证码、AccessKeySecret、SMTP 授权码；邮箱/手机号在 detail 中默认脱敏（如 `138****1234`、`a***@x.com`）；唯一例外是 detail 中键名为 `content` 的业务正文快照（如评论发布/修改审计），为满足取证一致性保留原文。
- **备份建议**：README 提供 logs 表 mysqldump 定期备份与异地留存指引（等保二级"审计记录定期备份"）。

---

## 12. 安全措施清单（全部必须落实）与等保二级映射

### 12.1 防注入与输入输出安全（等保二级·入侵防范）
1. **SQL 注入**：全部 SQL 走 PDO 预处理并绑定参数，禁止字符串拼接；排序字段、LIMIT、表名等无法绑定的位置一律走白名单/整型强转。`DB.php` 不提供执行裸 SQL 字符串的公开方法。
2. **统一输入校验层**：`core/Utils.php` 提供 `input_int()/input_email()/input_phone()/input_slug()/input_enum(白名单)/input_text(长度上限)` 校验器，**所有 GET/POST 入口参数必须先经校验器处理**，禁止直接使用 `$_GET`/`$_POST` 原值。
3. **XSS**：所有输出经 `e()` 转义；Markdown 渲染结果经服务端白名单（DOMDocument）过滤；评论区纯文本化；前端插入 HTML 一律 `textContent` 或等价安全 API。
4. **CSRF**：所有改变状态的表单/AJAX 携带并校验 token（与 Session 及 config.php 密钥绑定）。
5. **SSRF/任意文件**：上传走 MIME 白名单（fileinfo 实测）+ 随机重命名 + 禁止脚本扩展名；`uploads/` 禁止 PHP 执行；不接受用户传入的远程 URL 抓取（插件如需必须显式校验协议与内网地址）。
6. **错误信息**（等保二级·剩余信息/抗抵赖辅助）：`debug=0`（默认）时禁止向页面输出路径、SQL、堆栈；统一 404/500 错误页；错误详情仅写服务器错误日志。
7. **客户端 IP 可信度**：全站 IP 统一经 `client_ip()` 获取（§6.4）；默认只信 `REMOTE_ADDR`；仅当管理员显式启用并指定标头后才从标头取值，且必须经 `FILTER_VALIDATE_IP` 校验、XFF 链取第一个 IP、失败回退 `REMOTE_ADDR`；禁止无条件信任 `X-Forwarded-For` 等可伪造标头。

### 12.2 身份鉴别与会话（等保二级·身份鉴别）
7. 口令策略：复杂度、弱口令黑名单、改密验证原密码（见 §6.1）；密码 `password_hash(PASSWORD_DEFAULT)` 加盐哈希存储。
8. 登录失败锁定 + IP 限流、登录成败审计（见 §6.3）。
9. 会话超时 30 分钟（可配）、登出销毁、`session_regenerate_id(true)`、cookie `HttpOnly` + HTTPS 下 `Secure`。
10. 密码过期功能预留默认关闭（见 §6.2）；敏感操作（改密、改邮箱/手机、导出日志）要求重新验证当前密码。
11. **安全响应头**（内核统一输出）：`X-Content-Type-Options: nosniff`、`X-Frame-Options: SAMEORIGIN`、`Referrer-Policy: strict-origin-when-cross-origin`，前台文章页附带受限 `Content-Security-Policy`（允许 self 的本地化资源）。

### 12.3 权限与文件防护
12. 后台每个动作执行前 `Auth::check_cap()` 鉴权，禁止仅靠菜单隐藏做权限控制；权限唯一判定依据 `users.role`。
13. `config.php`、`core/` 禁止 HTTP 直接访问（rewrite + `defined('APP_BOOT') or exit;` 双重防护）。

### 12.4 等保二级控制点映射表
| 等保二级控制点 | 本项目落实点 |
|---|---|
| 身份鉴别 a) 唯一标识+口令鉴别 | users 唯一用户名/邮箱 + 加盐哈希口令（§2.1、§6.1） |
| 身份鉴别 b) 口令复杂度并定期更换 | §6.1 复杂度强制 + §6.2 密码过期（预留默认关，开启即满足"定期更换"） |
| 身份鉴别 c) 登录失败处理 | §6.3 连续失败锁定 + IP 限流 |
| 身份鉴别 d) 远程管理防窃听 | README 要求管理后台强制 HTTPS；HTTPS 下 cookie `Secure` |
| 安全审计 a) 审计覆盖每个用户/重要事件 | §11 必审事件清单（含成败结果） |
| 安全审计 b) 记录含日期/用户/类型/结果 | logs 表字段（§2.7） |
| 安全审计 c) 审计记录保护 | 只增不改、最小留存 180 天、日志访问自审计（§11） |
| 入侵防范·输入验证 | §12.1 统一校验层 + 预处理 + 白名单过滤 |
| 剩余信息保护 | 登出销毁会话、调试信息不入生产输出（§12.1.6、§6.3） |
| 数据保密性 | 口令加盐哈希；日志脱敏；建议全站 TLS |

### 12.5 部署运维要求（写入 README，非代码）
- 生产环境 `php.ini`：`display_errors=Off`、`log_errors=On`、`expose_php=Off`；建议开启 `open_basedir`。
- 建议全站 HTTPS（面板或证书自动化均可），后台路径建议加 IP 白名单（可选）。
- 数据库账号最小权限（仅对本库 SELECT/INSERT/UPDATE/DELETE/CREATE/INDEX/ALTER），禁止 root 直连应用。
- logs 表与整库定期备份（mysqldump cron 示例）。

---

## 13. 实施顺序（里程碑）

| # | 里程碑 | 验收标准 |
|---|---|---|
| 1 | 内核骨架：config 加载、DB（前缀）、Router+伪静态、Session/CSRF、Utils | 首页空模板可访问，回退模式可用 |
| 2 | install 安装程序 | 全新环境四步装完：自检→DB→管理员→生成 config.php 与 install.lock |
| 3 | 用户体系：注册/登录/找回密码 + 权限中间件 + verify_codes + 口令策略（§6.1）+ 登录锁定与会话安全（§6.3）+ 密码过期拦截逻辑（§6.2，默认关闭） | 三角色登录跳转正确后台视图，能力点生效；弱口令注册被拒；5 次失败锁定生效；手动打开 `pwd_expire_enabled` 后强制改密流程可用 |
| 4 | 统一日志组件（等保二级审计，§11）+ 客户端 IP 机制（§6.4） | 登录成败等操作在 logs 表有 category/result 完整记录；无 UPDATE/DELETE 入口；到期清理可用；日志查询/导出产生自审计记录；启用 `ip_header_enabled` 后日志 IP 取自指定标头且非法值正确回退 |
| 5 | 前台默认模板（仿 qyqiu.cn）：文章/分类/归档/分页/评论展示 | 视觉特征符合 §10，明暗切换可用 |
| 6 | user 后台：文章/评论/分类/用户/设置/**安全设置**/审核/个人资料/头像 | §5.2 能力矩阵全部生效；安全设置页修改锁定阈值、会话超时、密码过期开关即时生效并写审计日志 |
| 7 | 插件机制：Hook + 管理页 + 插件开发文档 | Hello World 示例插件可启用并在前台生效 |
| 8 | 默认插件 smtp-mailer、aliyun-sms | 后台可配置；注册/找回密码可收发验证码；日志有记录且脱敏 |
| 9 | Vditor 集成 + KaTeX/highlight.js 本地化 | 后台可编辑 Markdown，前台公式/代码高亮正常，无 CDN 引用 |
| 10 | 收尾：模板/插件管理页完善、日志中心、.htaccess 与 nginx 示例、README | 全量 `php -l` 通过；全新环境按 README 可完成部署 |
| 11 | 安全加固一轮（外部审计整改）：闪存 toast 转义 + `json_out_script()` 统一 `<script>` 内 JSON 输出；主题 zip 条目白名单（拒隐藏文件/phar/phtml/路径穿越，不拒 `.php` 模板）；口令/密钥统一经 `input_password()`；移除全部 `@` 抑制 | 恶意主题名不再触发存储型 XSS；危险条目 zip 被拒；全库无 `@`；口令字段无 `$_POST` 直读 |
| 12 | 运行时升级 PHP 7.4+：安装自检/文档同步；会话 Cookie 改数组参数并全面启用 `SameSite=Lax`（含安装向导）；install 复用 `is_https()` | 7.4 环境下 Cookie 头含 `SameSite=Lax`；`HTTPS=off` 环境会话 Cookie 不误加 `Secure` |
| 13 | 安全复审：后台写入强制 POST、并发登录/限流/验证码裁决、密码过期通道统一、会话身份清理、QQ 绑定锁失败拒绝、隐藏路径防护与审计补齐 | PHP 7.4 全量语法检查通过；旧码不再生效、并发错误计数不丢失、取锁失败不写入、开启后的管理员与第三方登录受过期拦截；三套重写配置阻断隐藏路径且正常静态资源可用 |

---

## 14. 交付物

完整可部署源码树（§1 结构）+ `.htaccess` + `nginx.conf.example` + `README.md`（安装步骤、两种服务器的伪静态配置、插件开发规范、阿里云短信开通指引、第三方资源版本与来源清单、**等保二级安全配置与运维指引**：口令/锁定/审计留存项说明、php.ini 加固、HTTPS 要求、数据库最小权限、日志备份示例、**CDN 接入说明（阿里云 ESA/DCDN 等真实 IP 标头配置与源站回源 IP 白名单建议）**）。
