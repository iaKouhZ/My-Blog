/**
 * 后台公共交互（layui 版）：
 * 1. 明暗模式切换（与前台默认主题共用 cb-darkmode 键，localStorage 记忆，默认跟随系统）
 * 2. 移动端 ☰ 抽屉式导航（固定侧栏滑入滑出 + 遮罩）
 * 3. layui 模块初始化：导航/表单控件渲染、data-confirm 弹层确认、闪存消息轻提示
 */
(function () {
    'use strict';

    // 与前台默认主题共用同一键，前后台明暗偏好保持一致
    var STORAGE_KEY = 'cb-darkmode';
    // 与 admin.css 的媒体查询断点保持一致：≤860px 侧栏为抽屉式
    var SIDE_BREAKPOINT = 860;

    function storedPreference() {
        try { return localStorage.getItem(STORAGE_KEY); } catch (err) { return null; }
    }

    function savePreference(value) {
        try { localStorage.setItem(STORAGE_KEY, value); } catch (err) { /* 隐私模式下静默失败 */ }
    }

    function applyDarkMode(enable) {
        if (enable) {
            document.documentElement.classList.add('darkmode');
        } else {
            document.documentElement.classList.remove('darkmode');
        }
        // 图标随状态切换：暗色下显示太阳（点击回亮色），亮色下显示月亮
        var icon = document.getElementById('admin-darkmode-icon');
        if (icon) {
            icon.classList.remove('layui-icon-moon', 'layui-icon-light');
            icon.classList.add(enable ? 'layui-icon-light' : 'layui-icon-moon');
        }
        // Vditor 编辑器联动（仅文章编辑页存在）：整体主题 + 预览内容主题同步切换
        if (window.cbVditor && typeof window.cbVditor.setTheme === 'function') {
            try {
                window.cbVditor.setTheme(enable ? 'dark' : 'classic', enable ? 'dark' : 'light');
            } catch (err) { /* 编辑器未就绪时静默，不影响明暗切换 */ }
        }
    }

    function initDarkMode() {
        // head 内联脚本已在渲染前应用偏好（防闪烁），此处仅同步图标状态并绑定切换
        var isDark = document.documentElement.classList.contains('darkmode');
        applyDarkMode(isDark);

        var toggle = document.getElementById('admin-darkmode-toggle');
        if (toggle) {
            toggle.addEventListener('click', function () {
                var dark = document.documentElement.classList.contains('darkmode');
                applyDarkMode(!dark);
                savePreference(!dark ? 'true' : 'false');
            });
        }

        // 系统偏好变化时仅在用户未手动选择的情况下跟随（与前台主题行为一致）
        if (window.matchMedia) {
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (ev) {
                if (storedPreference() === null) {
                    applyDarkMode(ev.matches);
                }
            });
        }
    }

    function initSidebar() {
        var toggle = document.getElementById('admin-side-toggle');
        var side = document.getElementById('admin-side');
        var overlay = document.getElementById('admin-side-overlay');
        if (!toggle || !side) {
            return;
        }

        function closeSidebar() {
            side.classList.remove('open');
            if (overlay) {
                overlay.classList.remove('open');
            }
            toggle.setAttribute('aria-expanded', 'false');
        }

        toggle.addEventListener('click', function () {
            var open = side.classList.toggle('open');
            if (overlay) {
                if (open) {
                    overlay.classList.add('open');
                } else {
                    overlay.classList.remove('open');
                }
            }
            toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
        });

        if (overlay) {
            overlay.addEventListener('click', closeSidebar);
        }
        // 移动端点击导航链接后自动收起抽屉；仅限真实跳转的链接——
        // 组标题（href="javascript:;"）只展开二级菜单不跳转，收起会导致子菜单无法操作
        var links = side.querySelectorAll('a');
        for (var i = 0; i < links.length; i++) {
            links[i].addEventListener('click', function () {
                if (window.innerWidth > SIDE_BREAKPOINT) {
                    return;
                }
                var href = this.getAttribute('href') || '';
                if (href === '' || href === '#' || href.indexOf('javascript:') === 0) {
                    return;
                }
                // 新窗口打开的链接不影响当前页面抽屉状态
                if (this.getAttribute('target') === '_blank') {
                    return;
                }
                closeSidebar();
            });
        }
    }

    /** data-confirm 表单：提交前经 layer.confirm 二次确认（取代原生 confirm） */
    function initConfirmForms(layer) {
        document.addEventListener('submit', function (e) {
            var form = e.target;
            if (!form || !form.getAttribute) {
                return;
            }
            var msg = form.getAttribute('data-confirm');
            if (!msg) {
                return;
            }
            if (layer) {
                e.preventDefault();
                // 确认弹层文案可由后台语言包覆盖（window.CB_ADMIN_LANG 由 layout 注入）
                var lang = window.CB_ADMIN_LANG || {};
                layer.confirm(escapeHtml(msg).replace(/\n/g, '<br>'), {
                    icon: 3,
                    title: lang.confirm_title || '操作确认',
                    btn: [lang.confirm_ok || '确认', lang.confirm_cancel || '取消']
                }, function (index) {
                    layer.close(index);
                    var input = form.querySelector('input[name="img_confirm"]');
                    if (input) {
                        input.value = '1';
                    }
                    form.submit();
                });
            } else {
                // layui 未加载时回退原生确认，功能不受影响
                if (!window.confirm(msg)) {
                    e.preventDefault();
                } else {
                    var input = form.querySelector('input[name="img_confirm"]');
                    if (input) {
                        input.value = '1';
                    }
                }
            }
        });
    }

    /** 闪存消息轻提示（DOM 内已有可见块，toast 仅作强化，取第一条避免连弹） */
    function initFlashToast(layer) {
        var msgs = window.CB_FLASH;
        if (!msgs || !msgs.length) {
            return;
        }
        var first = msgs[0];
        // layer.msg 按 HTML 渲染内容：闪存文本可能含主题名等受控字符串，必须先转义
        layer.msg(escapeHtml(first.text), {
            icon: first.type === 'success' ? 1 : 2,
            time: 2500,
            anim: 6
        });
    }

    /** HTML 特殊字符转义（用于把不可信文本安全交给按 HTML 渲染的组件） */
    function escapeHtml(text) {
        return String(text).replace(/[&<>"']/g, function (ch) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[ch];
        });
    }

    /** 通用 laydate 日期范围选择：.cb-date-range 可见框选定后经 data-from/data-to
        拆回隐藏域提交（后端仍收独立 from/to 参数）；无 JS 时隐藏域保持原值不受影响 */
    function initDateRanges(laydate) {
        if (!laydate) {
            return;
        }
        var elems = document.querySelectorAll('.cb-date-range');
        Array.prototype.forEach.call(elems, function (el) {
            var fromSel = el.getAttribute('data-from');
            var toSel = el.getAttribute('data-to');
            laydate.render({
                elem: el,
                type: 'date',
                format: 'yyyy-MM-dd',
                range: '~',
                done: function (value) {
                    var fromEl = fromSel ? document.querySelector(fromSel) : null;
                    var toEl = toSel ? document.querySelector(toSel) : null;
                    if (!fromEl || !toEl) {
                        return;
                    }
                    if (!value) {
                        fromEl.value = '';
                        toEl.value = '';
                        return;
                    }
                    var parts = value.split('~');
                    fromEl.value = (parts[0] || '').replace(/^\s+|\s+$/g, '');
                    toEl.value = (parts[1] || '').replace(/^\s+|\s+$/g, '');
                }
            });
        });
    }

    /** layui 模块初始化：导航/下拉/开关渲染 + 弹层交互 */
    function initLayui() {
        if (!window.layui || !layui.use) {
            // layui 缺失时仅保留原生确认回退
            initConfirmForms(null);
            return;
        }
        layui.use(['element', 'form', 'layer', 'laydate'], function () {
            var layer = layui.layer;
            initConfirmForms(layer);
            initFlashToast(layer);
            initDateRanges(layui.laydate);
            // element 模块渲染导航树/选项卡/折叠面板；部分版本自动初始化，此处显式兜底
            if (layui.element && layui.element.render) {
                layui.element.render();
            }
            // form 模块加载时会自动渲染页面上的下拉、开关等，
            // 此处显式再渲染一次，兜底插件钩子在 DOM 就绪后追加的控件
            if (layui.form && layui.form.render) {
                layui.form.render();
            }
            // 暴露给视图脚本（封面/头像上传等）复用
            window.CB_ADMIN = { layer: layer, form: layui.form };
        });
    }

    function init() {
        initDarkMode();
        initSidebar();
        initLayui();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
