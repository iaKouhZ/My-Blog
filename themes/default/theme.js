/**
 * 默认主题交互：☾ 明暗切换（localStorage 记忆，默认跟随系统）+ ☰ 移动端侧栏
 * + 正文工具栏（返回 / 目录 / 字号增减 / 打印）+ 回到顶部 + 阅读进度条
 */
(function () {
    'use strict';

    function prefersReducedMotion() {
        return window.matchMedia && window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    }

    var STORAGE_KEY = 'cb-darkmode';

    function storedPreference() {
        try { return localStorage.getItem(STORAGE_KEY); } catch (err) { return null; }
    }

    function savePreference(value) {
        try { localStorage.setItem(STORAGE_KEY, value); } catch (err) { /* 隐私模式下静默失败 */ }
    }

    function updateIcon(isDark) {
        var icon = document.getElementById('darkmode-icon');
        if (icon) {
            icon.textContent = isDark ? '☀' : '☾';
        }
    }

    function applyDarkMode(enable) {
        if (enable) {
            document.documentElement.classList.add('darkmode');
        } else {
            document.documentElement.classList.remove('darkmode');
        }
        updateIcon(enable);
    }

    function initDarkMode() {
        var stored = storedPreference();
        if (stored === 'true') {
            applyDarkMode(true);
        } else if (stored === 'false') {
            applyDarkMode(false);
        } else if (window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches) {
            // 未手动选择过：跟随系统偏好
            applyDarkMode(true);
        }

        var toggle = document.getElementById('darkmode-toggle');
        if (toggle) {
            toggle.addEventListener('click', function () {
                var isDark = document.documentElement.classList.contains('darkmode');
                applyDarkMode(!isDark);
                savePreference(!isDark ? 'true' : 'false');
            });
        }

        // 系统偏好变化时仅在用户未手动选择的情况下跟随
        if (window.matchMedia) {
            window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function (ev) {
                if (storedPreference() === null) {
                    applyDarkMode(ev.matches);
                }
            });
        }
    }

    function initSidebar() {
        var toggle = document.getElementById('sidebar-toggle');
        var sidebar = document.getElementById('sidebar');
        var overlay = document.getElementById('sidebar-overlay');
        if (!toggle || !sidebar) {
            return;
        }

        function closeSidebar() {
            sidebar.classList.remove('open');
            if (overlay) {
                overlay.classList.remove('open');
            }
            toggle.setAttribute('aria-expanded', 'false');
        }

        toggle.addEventListener('click', function () {
            var open = sidebar.classList.toggle('open');
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
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') {
                closeSidebar();
            }
        });
        // 移动端点击侧栏链接后自动收起
        var links = sidebar.querySelectorAll('a');
        for (var i = 0; i < links.length; i++) {
            links[i].addEventListener('click', function () {
                if (window.innerWidth <= 768) {
                    closeSidebar();
                }
            });
        }
    }

    /**
     * 危险操作表单二次确认（.confirm-submit）：文章页 CSP 仅允许 self 脚本，
     * 无法使用内联 onsubmit，故在此统一委托处理；
     * 兜底文案取 body[data-confirm-default]（模板经 data 属性注入，避开 CSP 内联限制）
     */
    function initConfirmForms() {
        document.addEventListener('submit', function (ev) {
            var form = ev.target;
            if (form.classList && form.classList.contains('confirm-submit')) {
                var msg = form.getAttribute('data-confirm')
                    || document.body.getAttribute('data-confirm-default')
                    || '确认执行该操作？';
                if (!window.confirm(msg)) {
                    ev.preventDefault();
                }
            }
        });
    }

    /**
     * 正文工具栏：返回（无站内来路时回首页）/ 字号四档切换（localStorage 记忆）/ 打印
     */
    function initArticleToolbar() {
        var FONT_KEY = 'cb-article-font';
        var fontSizes = ['font-size-sm', 'font-size-md', 'font-size-lg', 'font-size-xl'];
        var fontIndex = 1;
        var content = document.getElementById('article-content');

        function applyFontSize(index) {
            for (var i = 0; i < fontSizes.length; i++) {
                content.classList.remove(fontSizes[i]);
            }
            content.classList.add(fontSizes[index]);
        }

        if (content) {
            try {
                var saved = localStorage.getItem(FONT_KEY);
                if (saved !== null) {
                    fontIndex = Math.max(0, Math.min(3, parseInt(saved, 10)));
                }
            } catch (err) { /* 隐私模式下静默失败 */ }
            applyFontSize(fontIndex);

            var btnDecrease = document.getElementById('btn-font-decrease');
            var btnIncrease = document.getElementById('btn-font-increase');
            if (btnDecrease) {
                btnDecrease.addEventListener('click', function () {
                    if (fontIndex > 0) {
                        fontIndex--;
                        applyFontSize(fontIndex);
                        saveFont(fontIndex);
                    }
                });
            }
            if (btnIncrease) {
                btnIncrease.addEventListener('click', function () {
                    if (fontIndex < 3) {
                        fontIndex++;
                        applyFontSize(fontIndex);
                        saveFont(fontIndex);
                    }
                });
            }
        }

        function saveFont(index) {
            try { localStorage.setItem(FONT_KEY, String(index)); } catch (err) { /* 静默失败 */ }
        }

        var btnBack = document.getElementById('btn-back');
        if (btnBack) {
            btnBack.addEventListener('click', function () {
                // 有站内来路才后退，否则回首页（data-home 由模板按路由模式生成，兼容子目录部署）
                var home = btnBack.getAttribute('data-home') || '/';
                if (window.history.length > 1 && document.referrer && document.referrer.indexOf(window.location.origin) !== -1) {
                    window.history.back();
                } else {
                    window.location.href = home;
                }
            });
        }

        var btnPrint = document.getElementById('btn-print');
        if (btnPrint) {
            btnPrint.addEventListener('click', function () {
                window.print();
            });
        }
    }

    /**
     * 回到顶部：滚动超 400px 淡入，点击平滑回顶（减少动态偏好下瞬移）
     */
    function initBackToTop() {
        var btn = document.getElementById('back-to-top');
        if (!btn) {
            return;
        }
        var ticking = false;
        function onScroll() {
            if (ticking) {
                return;
            }
            ticking = true;
            window.requestAnimationFrame(function () {
                btn.classList.toggle('show', window.pageYOffset > 400);
                ticking = false;
            });
        }
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
        btn.addEventListener('click', function () {
            window.scrollTo({ top: 0, behavior: prefersReducedMotion() ? 'auto' : 'smooth' });
        });
    }

    /**
     * 阅读进度条：文章页顶部 2px 填充，按滚动比例推进
     */
    function initReadingProgress() {
        var bar = document.getElementById('reading-progress-bar');
        if (!bar) {
            return;
        }
        var ticking = false;
        function onScroll() {
            if (ticking) {
                return;
            }
            ticking = true;
            window.requestAnimationFrame(function () {
                var doc = document.documentElement;
                var max = doc.scrollHeight - doc.clientHeight;
                bar.style.width = (max > 0 ? Math.min(100, window.pageYOffset / max * 100) : 0) + '%';
                ticking = false;
            });
        }
        window.addEventListener('scroll', onScroll, { passive: true });
        window.addEventListener('resize', onScroll);
        onScroll();
    }

    /**
     * 文章目录：从正文 h2/h3 构建。宽屏（CSS ≥1500px）右侧常驻；
     * 窄屏经工具栏按钮以右侧抽屉展开（遮罩/Esc/点链接后关闭），当前章节随滚动高亮。
     * 正文无目标标题时隐藏按钮并放弃初始化。
     */
    function initToc() {
        var content = document.getElementById('article-content');
        var btn = document.getElementById('btn-toc');
        if (!content || !btn) {
            return;
        }
        var headings = content.querySelectorAll('h2, h3');
        if (headings.length === 0) {
            btn.style.display = 'none';
            return;
        }

        // 构建目录树：h3 归入上一个 h2 的子表，孤立的 h3 自成顶层；
        // 面板文案取按钮 data-toc-label/title（模板注入，避开文章页 CSP 内联限制）
        var panel = document.createElement('nav');
        panel.className = 'toc-panel';
        panel.id = 'toc-panel';
        panel.setAttribute('aria-label', btn.getAttribute('data-toc-label') || '文章目录');
        var title = document.createElement('div');
        title.className = 'toc-title';
        title.textContent = btn.getAttribute('data-toc-title') || '目录';
        panel.appendChild(title);
        var rootList = document.createElement('ul');
        rootList.className = 'toc-list';
        panel.appendChild(rootList);

        var links = [];
        var lastTopItem = null;
        for (var i = 0; i < headings.length; i++) {
            var heading = headings[i];
            if (!heading.id) {
                heading.id = 'toc-' + i;
            }
            var item = document.createElement('li');
            var link = document.createElement('a');
            link.href = '#' + heading.id;
            link.textContent = heading.textContent;
            link.setAttribute('data-target', heading.id);
            item.appendChild(link);
            links.push(link);
            if (heading.tagName === 'H3' && lastTopItem) {
                var sub = lastTopItem.querySelector('ul');
                if (!sub) {
                    sub = document.createElement('ul');
                    lastTopItem.appendChild(sub);
                }
                sub.appendChild(item);
            } else {
                rootList.appendChild(item);
                lastTopItem = item;
            }
        }
        document.body.appendChild(panel);

        // 抽屉开合（窄屏；宽屏按钮本就不显示）
        var overlay = document.getElementById('toc-overlay');
        function closeToc() {
            panel.classList.remove('open');
            if (overlay) {
                overlay.classList.remove('open');
            }
            btn.setAttribute('aria-expanded', 'false');
        }
        btn.addEventListener('click', function () {
            var open = panel.classList.toggle('open');
            if (overlay) {
                overlay.classList.toggle('open', open);
            }
            btn.setAttribute('aria-expanded', open ? 'true' : 'false');
        });
        if (overlay) {
            overlay.addEventListener('click', closeToc);
        }
        document.addEventListener('keydown', function (ev) {
            if (ev.key === 'Escape') {
                closeToc();
            }
        });
        panel.addEventListener('click', function (ev) {
            if (ev.target.tagName === 'A') {
                closeToc();
            }
        });

        // 当前章节高亮：取滚动位置之上最后一个标题
        var ticking = false;
        function onScroll() {
            if (ticking) {
                return;
            }
            ticking = true;
            window.requestAnimationFrame(function () {
                var current = null;
                for (var i = 0; i < headings.length; i++) {
                    if (headings[i].getBoundingClientRect().top <= 80) {
                        current = headings[i].id;
                    } else {
                        break;
                    }
                }
                for (var j = 0; j < links.length; j++) {
                    links[j].classList.toggle('active', links[j].getAttribute('data-target') === current);
                }
                ticking = false;
            });
        }
        window.addEventListener('scroll', onScroll, { passive: true });
        onScroll();
    }

    function init() {
        initDarkMode();
        initSidebar();
        initConfirmForms();
        initArticleToolbar();
        initBackToTop();
        initReadingProgress();
        initToc();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
