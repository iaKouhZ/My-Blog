/**
 * 注册表单前端校验：信息不全/不合法时阻止提交并逐项提示；确认密码实时反馈
 * 规则与服务端 Front::register 对齐（弱口令黑名单与验证码真实性仍由服务端终判）
 */
(function () {
    'use strict';

    var form = document.querySelector('.auth-form');
    if (!form) {
        return;
    }

    // 文案覆盖：主题语言包经 window.CB_REG_LANG 注入；缺省保持中文（与内核 CB_PWD_LANG 同风格）
    var LANG = window.CB_REG_LANG || {};
    function L(key, def) {
        return LANG[key] || def;
    }

    var username = document.getElementById('regUsername');
    var email = document.getElementById('regEmail');
    var emailCode = document.getElementById('regEmailCode');
    var phone = document.getElementById('regPhone');
    var smsCode = document.getElementById('regSmsCode');
    var pwd = document.getElementById('regPassword');
    var pwd2 = document.getElementById('regPassword2');
    var pwdMatch = document.getElementById('pwdMatch');

    /** 在字段下方显示错误（同一字段复用同一提示节点） */
    function setError(input, msg) {
        if (!input) {
            return;
        }
        // 验证码输入位于 .verify-row 内，提示挂到该行之后
        var anchor = input;
        if (anchor.parentNode && anchor.parentNode.className.indexOf('verify-row') !== -1) {
            anchor = anchor.parentNode;
        }
        var holder = anchor.parentNode.querySelector('.field-error');
        if (!holder) {
            holder = document.createElement('span');
            holder.className = 'field-error';
            anchor.parentNode.insertBefore(holder, anchor.nextSibling);
        }
        holder.textContent = msg;
        input.classList.add('invalid');
    }

    /** 清除字段错误 */
    function clearError(input) {
        if (!input) {
            return;
        }
        var anchor = input;
        if (anchor.parentNode && anchor.parentNode.className.indexOf('verify-row') !== -1) {
            anchor = anchor.parentNode;
        }
        var holder = anchor.parentNode.querySelector('.field-error');
        if (holder) {
            holder.parentNode.removeChild(holder);
        }
        input.classList.remove('invalid');
    }

    /** 密码强度：长度 8-64、四类字符至少三类、不含用户名（与后端口径一致） */
    function pwdError(v, name) {
        if (v === '') {
            return L('need_password', '请输入密码');
        }
        if (v.length < 8 || v.length > 64) {
            return L('pwd_len', '密码长度须为 8-64 位');
        }
        var classes = 0;
        if (/[A-Z]/.test(v)) { classes++; }
        if (/[a-z]/.test(v)) { classes++; }
        if (/[0-9]/.test(v)) { classes++; }
        if (/[^A-Za-z0-9]/.test(v)) { classes++; }
        if (classes < 3) {
            return L('pwd_classes', '密码须包含大写/小写/数字/特殊字符中的至少三类');
        }
        if (name !== '' && v.toLowerCase().indexOf(name.toLowerCase()) !== -1) {
            return L('pwd_contain_name', '密码不得包含用户名');
        }
        return '';
    }

    /** 逐字段校验，返回错误文案（空串表示通过） */
    function validateField(input) {
        var v = input.value.replace(/^\s+|\s+$/g, '');
        var name = username ? username.value.replace(/^\s+|\s+$/g, '') : '';

        if (input === username) {
            if (v === '') { return L('need_username', '请输入用户名'); }
            if (!/^[a-zA-Z0-9_]{3,32}$/.test(v)) { return L('username_invalid', '用户名为 3-32 位字母、数字或下划线'); }
        } else if (input === email) {
            // 邮箱可选，填写则校验格式
            if (v !== '' && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(v)) { return L('email_invalid', '邮箱格式不正确'); }
        } else if (input === emailCode) {
            // 邮箱验证码：填写了邮箱则必须填写
            var emailVal = email ? email.value.replace(/^\s+|\s+$/g, '') : '';
            if (emailVal !== '' && v === '') { return L('need_email_code', '请填写邮箱验证码'); }
        } else if (input === phone) {
            // 短信插件启用时（smsCode 存在）手机号必填；否则填写才校验格式
            if (smsCode) {
                if (v === '') { return L('need_phone', '请输入手机号'); }
                if (!/^1[3-9][0-9]{9}$/.test(v)) { return L('phone_invalid', '手机号格式不正确'); }
            } else if (v !== '' && !/^1[3-9][0-9]{9}$/.test(v)) {
                return L('phone_invalid', '手机号格式不正确');
            }
        } else if (input === smsCode) {
            if (v === '') { return L('need_sms_code', '请填写短信验证码'); }
        } else if (input === pwd) {
            return pwdError(v, name);
        } else if (input === pwd2) {
            if (v === '') { return L('need_password2', '请再次输入密码'); }
            if (pwd && v !== pwd.value) { return L('pwd_mismatch', '两次输入的密码不一致'); }
        }
        return '';
    }

    /** 确认密码实时反馈（输入即显示一致/不一致） */
    function updateMatch() {
        if (!pwd2 || !pwdMatch) {
            return;
        }
        if (pwd2.value === '') {
            pwdMatch.textContent = '';
            pwdMatch.className = 'pwd-match';
        } else if (pwd2.value === pwd.value) {
            pwdMatch.textContent = L('match_ok', '两次输入一致 ✓');
            pwdMatch.className = 'pwd-match ok';
        } else {
            pwdMatch.textContent = L('match_no', '两次输入不一致');
            pwdMatch.className = 'pwd-match err';
        }
    }

    // 参与校验的字段（验证码字段仅在插件启用、元素存在时参与）
    var fields = [username, email, emailCode, phone, smsCode, pwd, pwd2];
    var i;
    for (i = fields.length - 1; i >= 0; i--) {
        if (!fields[i]) {
            fields.splice(i, 1);
        }
    }

    // 输入即重验：已标错的字段修正后立刻消除提示
    for (i = 0; i < fields.length; i++) {
        (function (input) {
            input.addEventListener('input', function () {
                if (input.classList.contains('invalid') && validateField(input) === '') {
                    clearError(input);
                }
                if (input === pwd || input === pwd2 || input === username) {
                    updateMatch();
                }
            });
        })(fields[i]);
    }

    // 提交前全量校验：任一不通过即阻止请求并定位到第一个错误字段
    form.addEventListener('submit', function (ev) {
        var firstInvalid = null;
        for (var k = 0; k < fields.length; k++) {
            var msg = validateField(fields[k]);
            if (msg !== '') {
                setError(fields[k], msg);
                if (!firstInvalid) {
                    firstInvalid = fields[k];
                }
            } else {
                clearError(fields[k]);
            }
        }
        updateMatch();
        if (firstInvalid) {
            ev.preventDefault();
            firstInvalid.focus();
        }
    });

    updateMatch();
})();
