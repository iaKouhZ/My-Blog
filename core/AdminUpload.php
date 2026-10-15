<?php
/**
 * 后台图片上传：fileinfo 真实 MIME 校验 + 白名单 + 内容哈希命名（秒传去重）
 * imageAction 能力点 upload（文章配图/封面）；avatarAction 能力点 edit_profile（个人头像，全体登录用户可用）
 * 上传目录 uploads/年/月/，禁止 PHP 执行（见 uploads/.htaccess 与 nginx 规则）
 */
defined('APP_BOOT') or exit;

class AdminUpload
{
    /** MIME → 扩展名白名单 */
    private static $mimeMap = array(
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/gif'  => 'gif',
    );

    /** 图片上传接口（JSON 响应，供 Vditor 与封面上传调用） */
    public static function imageAction()
    {
        Auth::require_cap('upload');
        $relative = self::saveImage(2 * 1024 * 1024, admin_t('admin.upload.image_size'));
        blog_log('post', 'upload.image', 'success', array('path' => $relative));
        // 同时返回相对路径：文章封面表单只接受 uploads/ 开头的相对路径（与头像接口一致）
        json_out(array('code' => 0, 'msg' => 'ok', 'data' => array(
            'url'  => Router::base() . '/' . $relative,
            'path' => $relative,
        )));
    }

    /** 头像上传接口（个人资料页调用，全体登录用户可用，上限 1MB） */
    public static function avatarAction()
    {
        Auth::require_cap('edit_profile');
        $relative = self::saveImage(1024 * 1024, admin_t('admin.upload.avatar_size'));
        blog_log('user', 'upload.avatar', 'success', array('path' => $relative));
        // 同时返回相对路径：个人资料表只接受 uploads/ 开头的相对路径
        json_out(array('code' => 0, 'msg' => 'ok', 'data' => array(
            'url' => Router::base() . '/' . $relative,
            'path' => $relative,
        )));
    }

    /**
     * 统一落盘逻辑：校验上传文件 → fileinfo 真实 MIME 白名单 → 内容哈希命名存入 uploads/年/月/
     * （md5_file 命名实现秒传去重：内容相同的图片命中已存在文件即跳过落盘，为既定 feature）
     *
     * @param int    $maxBytes 大小上限（字节）
     * @param string $sizeMsg  超限提示
     * @return string uploads/ 下相对路径
     */
    private static function saveImage($maxBytes, $sizeMsg)
    {
        $identity = 'user:' . Auth::id();
        $avatar = $maxBytes <= 1024 * 1024;
        $daily = $avatar ? 10 : 100;
        if (!RateLimiter::allowFor('upload', $identity, 10)
            || !RateLimiter::allowFor($avatar ? 'upload_avatar' : 'upload_image', $identity, $daily, 86400)
            || !ip_throttle_allow('upload', 30)) {
            if (RateLimiter::allowFor('upload_denied', $identity, 1)) {
                blog_log('security', 'upload.throttled', 'fail', array());
            }
            http_response_code(429);
            header('Retry-After: 60');
            json_out(array('code' => 1, 'msg' => admin_t('admin.auth.throttled')));
        }
        if (empty($_FILES['file']) || !is_array($_FILES['file'])) {
            json_out(array('code' => 1, 'msg' => admin_t('admin.upload.no_file')));
        }
        $file = $_FILES['file'];
        if (!isset($file['error'], $file['tmp_name']) || !is_int($file['error']) || !is_string($file['tmp_name'])) {
            json_out(array('code' => 1, 'msg' => admin_t('admin.upload.no_file')));
        }
        if ((int) $file['error'] !== UPLOAD_ERR_OK) {
            json_out(array('code' => 1, 'msg' => admin_t('admin.upload.error_code', array((int) $file['error']))));
        }
        if (!is_uploaded_file($file['tmp_name'])) {
            json_out(array('code' => 1, 'msg' => admin_t('admin.upload.no_file')));
        }
        // 只相信磁盘真实长度，不依赖客户端声明的 size。
        if (filesize($file['tmp_name']) > $maxBytes || filesize($file['tmp_name']) <= 0) {
            json_out(array('code' => 1, 'msg' => $sizeMsg));
        }
        $image = self::inspectImage($file['tmp_name'], $maxBytes);
        if ($image === null) {
            json_out(array('code' => 1, 'msg' => admin_t('admin.upload.image_invalid')));
        }

        $subDir = date('Y/m');
        $targetDir = APP_ROOT . '/uploads/' . $subDir;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
            json_out(array('code' => 1, 'msg' => admin_t('admin.upload.dir_failed')));
        }
        // 用文件内容 md5 命名：内容相同的图片得到相同文件名，命中已存在文件则跳过落盘（秒传去重）
        $dest = $targetDir . '/' . $image['hash'] . '.' . $image['extension'];
        if (!is_file($dest)) {
            if (!move_uploaded_file($file['tmp_name'], $dest)) {
                json_out(array('code' => 1, 'msg' => admin_t('admin.upload.save_failed')));
            }
        }
        return 'uploads/' . $subDir . '/' . basename($dest);
    }

    /** 检查真实 MIME、文件长度及像素上限；保留原始字节的内容哈希去重约定。 */
    public static function inspectImage($path, $maxBytes)
    {
        if (!is_file($path) || !is_readable($path) || filesize($path) <= 0 || filesize($path) > $maxBytes) {
            return null;
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);
        if (!isset(self::$mimeMap[$mime])) {
            return null;
        }
        try {
            $size = getimagesize($path);
        } catch (Throwable $ex) {
            return null;
        }
        // 限制解码资源需求；仅看 MIME 无法识别超大尺寸的压缩图片。
        if (!$size || $size[0] < 1 || $size[1] < 1 || $size[0] > 10000 || $size[1] > 10000
            || $size[0] * $size[1] > 25000000 || !isset($size['mime']) || $size['mime'] !== $mime) {
            return null;
        }
        $hash = md5_file($path);
        return $hash === false ? null : array('hash' => $hash, 'extension' => self::$mimeMap[$mime]);
    }
}
