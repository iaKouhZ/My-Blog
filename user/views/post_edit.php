<?php
/**
 * 后台视图：文章编辑（layui 表单 + Vditor Markdown 编辑器，本地化资源缺失时降级为纯文本域）
 */
defined('APP_BOOT') or exit;
$vditorAvailable = is_file(APP_ROOT . '/assets/vendor/vditor/dist/index.min.js');
// Vditor 界面语言白名单映射：dist/js/i18n 仅本地化 zh_CN/en_US 两包，其余回退中文
$vditorLang = in_array(Lang::code(), array('zh_CN', 'en_US'), true) ? Lang::code() : 'zh_CN';
// 新建文章默认直接发布；审核开关开启且无审核权限时默认提交审核
if ($post) {
    $currentStatus = $post['status'];
} else {
    $currentStatus = ($postAudit && !Auth::check_cap('moderate_posts')) ? 'pending' : 'published';
}
// 状态选项按审核开关与角色收敛
$statusOptions = array('draft' => admin_t('admin.post.status_draft'));
if ($postAudit) {
    if (Auth::check_cap('moderate_posts')) {
        $statusOptions['published'] = admin_t('admin.post.opt_publish');
    } else {
        $statusOptions['pending'] = admin_t('admin.post.opt_submit_review');
    }
    if ($currentStatus === 'published' && Auth::check_cap('moderate_posts')) {
        $statusOptions['published'] = admin_t('admin.post.opt_publish');
    }
} else {
    $statusOptions['published'] = admin_t('admin.post.opt_publish');
}
if ($currentStatus === 'pending' && !isset($statusOptions['pending'])) {
    $statusOptions['pending'] = admin_t('admin.post.status_pending');
}
// 是否勾选了独立页面：控制“显示在侧边栏导航”项的显隐（该选项仅独立页面生效）
$isPageOn = $post && (int) $post['is_page'] === 1;
?>
<div class="card">
<form method="post" action="<?php echo e(site_base_admin('post/save')); ?>" class="layui-form v-form">
    <?php echo Csrf::field(); ?>
    <input type="hidden" name="id" value="<?php echo $post ? (int) $post['id'] : 0; ?>">

    <div class="layui-form-item">
        <label class="v-label"><?php echo e(admin_t('admin.post.field_title')); ?> *</label>
        <input type="text" name="title" value="<?php echo $post ? e($post['title']) : ''; ?>" required class="layui-input" style="max-width:100%">
    </div>

    <?php // 别名/分类/状态/页面选项：行内多列 ?>
    <div class="filter-bar" style="align-items:flex-start;margin-bottom:14px">
        <div>
            <label class="v-label"><?php echo e(admin_t('admin.post.field_slug')); ?></label>
            <input type="text" name="slug" value="<?php echo $post ? e($post['slug']) : ''; ?>" pattern="[a-z0-9-]*" class="layui-input" style="width:240px">
        </div>
        <div>
            <label class="v-label"><?php echo e(admin_t('admin.post.col_category')); ?></label>
            <select name="category_id">
                <option value="0"><?php echo e(admin_t('admin.post.uncategorized')); ?></option>
                <?php foreach ($categories as $cat): ?>
                <option value="<?php echo (int) $cat['id']; ?>" <?php echo $post && (int) $post['category_id'] === (int) $cat['id'] ? 'selected' : ''; ?>>
                    <?php echo e($cat['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div>
            <label class="v-label"><?php echo e(admin_t('admin.common.status')); ?></label>
            <select name="status">
                <?php foreach ($statusOptions as $optKey => $optName): ?>
                <option value="<?php echo e($optKey); ?>" <?php echo $currentStatus === $optKey ? 'selected' : ''; ?>><?php echo e($optName); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div style="padding-top:24px">
            <label style="display:flex;gap:6px;align-items:center">
                <input type="checkbox" name="is_page" value="1" <?php echo $isPageOn ? 'checked' : ''; ?>>
                <?php echo e(admin_t('admin.post.as_page')); ?>
            </label>
        </div>
        <div style="padding-top:24px<?php echo $isPageOn ? '' : ';display:none'; ?>" id="showInNavRow">
            <label style="display:flex;gap:6px;align-items:center">
                <input type="checkbox" name="show_in_nav" value="1" <?php echo !empty($inNav) ? 'checked' : ''; ?>>
                <?php echo e(admin_t('admin.post.show_in_nav')); ?>
            </label>
        </div>
    </div>

    <?php do_action('post_edit_fields', $post); /* 插件注入点：文章表单扩展字段（新建时 $post 为 null），输出需自带 .form-row 结构并自行转义 */ ?>

    <?php if ($postAudit && !Auth::check_cap('moderate_posts')): ?>
    <div class="form-hint" style="margin-bottom:10px"><?php echo e(admin_t('admin.post.audit_hint')); ?></div>
    <?php endif; ?>

    <div class="layui-form-item">
        <label class="v-label"><?php echo e(admin_t('admin.post.field_content')); ?></label>
        <?php // visibility:hidden + min-height：编辑器 JS/CSS 就绪前先隐身占位（防无样式 FOUC 与高度跳动），Vditor after 回调再显示 ?>
        <div id="vditor" style="<?php echo $vditorAvailable ? 'visibility:hidden;min-height:420px' : 'display:none'; ?>"></div>
        <textarea name="content" id="contentArea" class="layui-textarea" style="max-width:100%;min-height:360px;width:100%"
            <?php echo $vditorAvailable ? 'hidden' : ''; ?>><?php echo $post ? e($post['content']) : ''; ?></textarea>
    </div>

    <div class="layui-form-item">
        <label class="v-label"><?php echo e(admin_t('admin.post.field_excerpt')); ?></label>
        <textarea name="excerpt" class="layui-textarea" style="max-width:100%;width:100%;min-height:60px"><?php echo $post ? e($post['excerpt']) : ''; ?></textarea>
    </div>

    <div class="layui-form-item">
        <label class="v-label"><?php echo e(admin_t('admin.post.field_cover')); ?></label>
        <div class="filter-bar" style="align-items:center">
            <img src="<?php echo ($post && $post['cover'] !== '') ? e(Router::base() . '/' . $post['cover']) : ''; ?>"
                 id="coverPreview" alt="<?php echo e(admin_t('admin.post.cover_preview')); ?>"
                 style="display:<?php echo ($post && $post['cover'] !== '') ? 'inline-block' : 'none'; ?>;width:120px;height:80px;border-radius:4px;object-fit:cover">
            <?php // 封面路径为隐藏域：只接受上传接口回写的值，不提供手动填写入口 ?>
            <input type="hidden" name="cover" id="coverPath" value="<?php echo $post ? e($post['cover']) : ''; ?>">
            <?php if (Auth::check_cap('upload')): ?>
            <input type="file" id="coverFile" accept="image/jpeg,image/png,image/webp,image/gif" style="display:none">
            <button type="button" class="layui-btn layui-btn-primary" id="coverUploadBtn">
                <i class="layui-icon layui-icon-upload"></i> <?php echo e(admin_t('admin.post.cover_upload')); ?>
            </button>
            <?php endif; ?>
        </div>
    </div>

    <button class="layui-btn" type="submit"><i class="layui-icon layui-icon-ok"></i> <?php echo e(admin_t('admin.common.save')); ?></button>
    <a class="layui-btn layui-btn-primary" href="<?php echo e(site_base_admin('post/list')); ?>"><?php echo e(admin_t('admin.common.back')); ?></a>
</form>
</div>

<?php if ($vditorAvailable): ?>
<link id="cbVditorCss" rel="stylesheet" href="<?php echo e(assets_url('vendor/vditor/dist/index.css')); ?>">
<script src="<?php echo e(assets_url('vendor/vditor/dist/index.min.js')); ?>"></script>
<script>
// index.css 必须移入 head 尾部：Vditor 初始化时会把 content-theme css（dark/light）
// 动态追加到 head 末尾，两条 .vditor-reset 规则特异性相同、文档顺序居后者生效；
// 若 index.css 留在 body（位于动态样式之后），其浅色文字色会覆盖 dark.css 导致暗色黑字
(function () {
    var link = document.getElementById('cbVditorCss');
    if (link) { document.head.appendChild(link); }
})();
(function () {
    var area = document.getElementById('contentArea');
    // 明暗联动：head 内联脚本已在渲染前应用 darkmode 类，此处读取即可；
    // 运行时切换由 admin.js 的 applyDarkMode 调 setTheme 同步
    var isDark = document.documentElement.classList.contains('darkmode');
    window.cbVditor = new Vditor('vditor', {
        // 必须指定本地 cdn：Vditor 默认从 unpkg CDN 懒加载 lute/图标等子资源，违反禁 CDN 约束
        cdn: <?php echo json_out_script(assets_url('vendor/vditor')); ?>,
        // 界面语言：随后台语言，经白名单映射（dist/js/i18n 已本地化对应语言包）
        lang: <?php echo json_out_script($vditorLang); ?>,
        // emoji 图片路径同样默认指向内置 CDN，须显式指向本地 dist/images/emoji
        emojiPath: <?php echo json_out_script(assets_url('vendor/vditor/dist/images/emoji')); ?>,
        height: 420,
        // 默认分屏渲染（sv）；所见即所得 wysiwyg / 即时渲染 ir 可在工具栏切换
        mode: 'sv',
        // 编辑器整体主题（工具栏/编辑区）：暗色用官方 dark 皮肤（样式已含在本地 index.css）
        theme: isDark ? 'dark' : 'classic',
        value: area.value,
        cache: { enable: false },
        // 预览内容主题：必须显式指定本地 path —— Vditor 默认从 unpkg CDN 拉取 content-theme css，
        // 不指定则暗色下 dark.css 加载失败，wysiwyg/ir/预览区文字呈黑底黑字
        preview: {
            // 引擎名必须为官方大小写 "KaTeX"：mathRender 内部以 === "KaTeX" 严格匹配，
            // 写成小写 katex 会导致 KaTeX/MathJax 两个分支都不命中，公式完全不渲染
            math: { engine: 'KaTeX' },
            theme: {
                current: isDark ? 'dark' : 'light',
                path: <?php echo json_out_script(assets_url('vendor/vditor/dist/css/content-theme')); ?>
            }
        },
        toolbarConfig: { pin: true },
        upload: {
            url: <?php echo json_out_script(site_base_admin('upload/image')); ?>,
            fieldName: 'file',
            extraData: { _csrf: <?php echo json_out_script(Csrf::token()); ?> },
            max: 2 * 1024 * 1024,
            accept: 'image/jpeg,image/png,image/webp,image/gif',
            format: function (files, responseText) {
                var resp = JSON.parse(responseText);
                if (resp.code === 0) {
                    return JSON.stringify({ code: 0, data: { succMap: (function () {
                        var m = {};
                        for (var i = 0; i < files.length; i++) { m[files[i].name] = resp.data.url; }
                        return m;
                    })() } });
                }
                return JSON.stringify({ code: 1, msg: resp.msg || <?php echo json_out_script(admin_t('admin.upload.failed')); ?> });
            }
        },
        input: function (value) { area.value = value; },
        // 编辑器就绪（含样式应用）后显示编辑区，消除加载期的无样式闪现
        after: function () {
            document.getElementById('vditor').style.visibility = 'visible';
        }
    });
    // 提交前确保内容同步
    area.form.addEventListener('submit', function () {
        if (window.cbVditor && window.cbVditor.getValue) {
            area.value = window.cbVditor.getValue();
        }
    });
})();
</script>
<?php endif; ?>

<?php if (Auth::check_cap('upload')): ?>
<script>
(function () {
    var btn = document.getElementById('coverUploadBtn');
    var fileInput = document.getElementById('coverFile');
    if (!btn || !fileInput) { return; }
    // 反馈优先用 layui layer.msg（admin.js 暴露于 CB_ADMIN），缺失时回退 alert
    function feedback(msg, ok) {
        if (window.CB_ADMIN && CB_ADMIN.layer) {
            CB_ADMIN.layer.msg(msg, { icon: ok ? 1 : 2 });
        } else {
            window.alert(msg);
        }
    }
    btn.addEventListener('click', function () { fileInput.click(); });
    fileInput.addEventListener('change', function () {
        if (!fileInput.files.length) { return; }
        var fd = new FormData();
        fd.append('file', fileInput.files[0]);
        fd.append('_csrf', <?php echo json_out_script(Csrf::token()); ?>);
        var xhr = new XMLHttpRequest();
        xhr.open('POST', <?php echo json_out_script(site_base_admin('upload/image')); ?>, true);
        xhr.onreadystatechange = function () {
            if (xhr.readyState === 4) {
                try {
                    var resp = JSON.parse(xhr.responseText);
                    if (resp.code === 0) {
                        // 表单项只存 uploads/ 相对路径，预览用完整 URL
                        document.getElementById('coverPath').value = resp.data.path;
                        var preview = document.getElementById('coverPreview');
                        preview.src = resp.data.url;
                        preview.style.display = 'inline-block';
                        feedback(<?php echo json_out_script(admin_t('admin.upload.cover_ok')); ?>, true);
                    } else {
                        feedback(resp.msg || <?php echo json_out_script(admin_t('admin.upload.failed')); ?>, false);
                    }
                } catch (err) { feedback(<?php echo json_out_script(admin_t('admin.upload.failed')); ?>, false); }
            }
        };
        xhr.send(fd);
    });
})();
</script>
<?php endif; ?>

<script>
(function () {
    // “显示在侧边栏导航”仅对独立页面生效：取消勾选独立页面时隐藏该项并清除勾选
    //（后端对非独立页面传入该选项同样直接忽略）
    var pageBox = document.querySelector('input[name="is_page"]');
    var navRow = document.getElementById('showInNavRow');
    if (!pageBox || !navRow) { return; }
    var navBox = navRow.querySelector('input[name="show_in_nav"]');
    function syncNavRow() {
        navRow.style.display = pageBox.checked ? '' : 'none';
        if (!pageBox.checked && navBox) { navBox.checked = false; }
    }
    pageBox.addEventListener('change', syncNavRow);
})();
</script>
