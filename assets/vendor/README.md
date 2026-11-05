# assets/vendor 第三方本地化资源登记

本站禁止任何运行时 CDN 引用（含字体、图片、统计脚本）。
所有第三方 JS/CSS 必须本地化到本目录，并在此登记名称、版本、来源。

> **外链图片不经过本目录，也不属于本登记表**：本站允许的唯一第三方运行时资源例外是
> `<img>` 直连图床，它由 `options.img_ext_hosts` 可信主机白名单（默认空 = 全禁）控制，
> 主机只能由管理员在「安全设置 → 外链图片信任」或插件设置页声明，输出统一走
> `ext_img()`。因为不落盘、无版本、无来源包，所以既不在本 README 登记，也不属于
> "本地化资源"；需要本地化的图片仍应下载到 `uploads/`。详见 `plugins/README.md` §7.1。

## 资源清单

| 资源 | 版本 | 用途 | 来源 URL | 放置位置 |
|---|---|---|---|---|
| Vditor | 3.11.3 | 后台 Markdown 编辑器 | https://registry.npmjs.org/vditor/-/vditor-3.11.3.tgz（npm 官方包，取 `dist/` 后按下方清单裁剪） | `assets/vendor/vditor/dist/` |
| KaTeX | 0.16.11 | 前台数学公式渲染 | https://registry.npmjs.org/katex/-/katex-0.16.11.tgz（npm 官方包，取 `dist/`） | `assets/vendor/katex/` |
| highlight.js | 11.9.0 | 前台代码高亮 | https://registry.npmjs.org/@highlightjs/cdn-assets/-/cdn-assets-11.9.0.tgz（`highlight.min.js` + `styles/default.min.css`） | `assets/vendor/highlight.js/` |
| layui | 2.13.8 | 后台管理界面 UI 组件库（布局/导航/表单/表格/徽章/选项卡/分页/弹层） | https://registry.npmjs.org/layui/-/layui-2.13.8.tgz（npm 官方包，取 `dist/` 全套：layui.js + css/layui.css + font/图标字体） | `assets/vendor/layui/` |

> 上述资源已从官方 npm registry 下载并放置到位（layui 由用户于 2026-08 放置），无需再次手工下载。

## 目录结构（当前实际）

```
assets/vendor/
├── vditor/
│   └── dist/               ← Vditor 3.11.3 裁剪版（index.min.js / index.css / js 精选 / images / css，见下方裁剪清单）
├── katex/
│   ├── katex.min.js
│   ├── katex.min.css
│   └── fonts/…             ← KaTeX 公式字体全量
├── highlight.js/
│   ├── highlight.min.js
│   └── default.min.css
└── layui/
    ├── layui.js            ← 全量单文件包（含 layer/form/element/laypage/laydate 等全部内置模块）
    ├── css/
    │   ├── layui.css       ← 全部组件样式（含 laydate/layer，2.13.8 全量版已合并）
    │   └── modules/laydate.css ← 占位文件：laydate 运行时会按需请求该路径，缺失会导致日期面板无法弹出（勿删）
    └── font/…              ← 图标字体（layui.css 经相对路径 ../font/ 引用，勿单独移动）
```

## Vditor 裁剪清单（2026-08 随 3.11.3 升级执行，体积 21.35 MB → 6.76 MB）

官方 dist 中以下内容已删除。**升级 Vditor 后必须按本清单重新裁剪**，勿整包覆盖后直接提交：

| 删除项 | 理由 |
|---|---|
| `index.js`、`method.js`、`method.min.js`、`index.d.ts`、`method.d.ts`、`types/`、`ts/` | 未压缩 dev 源码与 TS 类型声明，运行时代码零引用（页面仅加载 `index.min.js` + `index.css`） |
| `js/mathjax/` | 公式引擎固定 KaTeX（mathRender 仅在 engine === "MathJax" 时加载），代码路径不可达 |
| `js/mermaid/`、`js/graphviz/`、`js/echarts/`、`js/markmap/`、`js/abcjs/`、`js/smiles-drawer/`、`js/flowchart.js/`、`js/plantuml/`、`js/wavedrom/` | 图表语法懒加载库，仅文章含对应代码块时才加载；且前台（core/Markdown.php + render.js）无这些语法的渲染支持，读者侧本就无法展示。误删后悔可从 npm vditor 包对应目录拷回 |
| `js/i18n/` 除 `zh_CN.js`/`en_US.js` 外的语言包 | 本站为中文站，仅保留中文与英文回退；语言包按需懒加载，删除不影响现有功能 |

保留项（运行必需/当前在用）：`js/lute/`（解析引擎）、`js/katex/`（公式）、`js/highlight.js/`（预览代码高亮）、`js/icons/`、`js/i18n/`、`css/content-theme/`、`images/emoji/`。

残留 CDN 字符串说明：`index.min.js` 的 unpkg 默认值（Constants.CDN 与导出模板 logo）及 `js/lute/lute.min.js` 的 emoji 默认路径，均被后台编辑页显式传入的 `cdn`/`emojiPath` 配置覆盖，运行时不触发外网请求。

## 加载与降级机制（无需改代码）

- 后台管理界面整体基于 layui（布局、导航、按钮、表格、徽章、选项卡、分页、弹层确认/轻提示、laydate 日期范围选择）；
  layui 加载失败时，核心功能回退为原生表单提交 + 原生 confirm，日期筛选回退为按原隐藏域值提交，不阻断操作。
- 后台文章编辑页检测到 `vditor/dist/index.min.js` 存在即自动启用 Vditor，
  并显式传入本地 `cdn` 参数（Vditor 默认从 unpkg 懒加载子资源，本地化后必须指向本目录）；
  资源缺失时回退为原生 textarea，功能不受影响。
- 前台检测到 `katex/`、`highlight.js/` 文件存在即自动加载渲染公式与代码高亮，
  缺失时静默降级为纯文本展示。

## 说明

- 升级任一资源后，请同步更新本表中的版本号，并确认懒加载子资源仍从本地解析。
- 升级/裁剪的标准流程、验证命令与踩坑记录见同目录 `UPGRADE.md`（升级 Vditor 前必读）。
- 禁止将任何资源改回 CDN 引用（AGENTS.md §2.5）。
