<?php
/** 数据结构服务：新装定义、可重复升级与按需建表统一留在内核。 */
defined('APP_BOOT') or exit;

class Schema
{
    /** 开启密码历史前先建表，失败时调用方不得启用策略。 */
    public static function ensurePasswordHistory()
    {
        DB::pdo()->prepare(self::passwordHistory(Config::get('db.prefix', 'cb_')))->execute();
    }

    /** 由 CLI 显式升级存量结构；不自动清除或改绑已有重复手机号。 */
    public static function upgrade()
    {
        if (!DB::lock('schema_upgrade', 5)) {
            throw new RuntimeException('schema upgrade is busy');
        }
        try {
            $users = DB::table('users');
            $duplicates = DB::pdo()->prepare('SELECT COUNT(*) FROM `' . $users
                . '` WHERE phone <> ? GROUP BY phone HAVING COUNT(*) > 1 LIMIT 1');
            $duplicates->execute(array(''));
            if ($duplicates->fetchColumn() !== false) {
                throw new RuntimeException('duplicate phone numbers require manual resolution before upgrade');
            }
            if (!self::hasColumn('users', 'phone_identity')) {
                DB::pdo()->prepare('ALTER TABLE `' . $users . '` ADD COLUMN phone_identity VARCHAR(20)'
                    . " GENERATED ALWAYS AS (NULLIF(phone, '')) STORED")->execute();
            }
            if (!self::hasIndex('users', 'uk_phone')) {
                DB::pdo()->prepare('ALTER TABLE `' . $users . '` ADD UNIQUE KEY uk_phone (phone_identity)')->execute();
            }
            if (!self::hasIndex('logs', 'idx_created')) {
                DB::pdo()->prepare('ALTER TABLE `' . DB::table('logs') . '` ADD KEY idx_created (created_at)')->execute();
            }
            Option::set('schema_version', '2');
        } finally {
            DB::unlock('schema_upgrade');
        }
    }

    /** 元数据只接受固定标识符，业务值仍绑定。 */
    private static function hasColumn($table, $column)
    {
        $stmt = DB::pdo()->prepare('SHOW COLUMNS FROM `' . DB::table($table) . '` WHERE Field = ?');
        $stmt->execute(array($column));
        return $stmt->fetch() !== false;
    }

    /** 升级允许重复执行，不依赖客户端是否保留了旧版本标记。 */
    private static function hasIndex($table, $index)
    {
        $stmt = DB::pdo()->prepare('SHOW INDEX FROM `' . DB::table($table) . '` WHERE Key_name = ?');
        $stmt->execute(array($index));
        return $stmt->fetch() !== false;
    }

    /** 返回全新安装的表结构，表名统一经 DB::table() 校验和加前缀。 */
    public static function definitions($prefix)
    {
        $tables = array();
        $names = array();
        foreach (array('users', 'posts', 'comments', 'categories', 'options', 'verify_codes', 'logs', 'plugin_data') as $name) {
            $names[$name] = DB::table($name, $prefix);
        }

        $tables['users'] = "CREATE TABLE IF NOT EXISTS `{$names['users']}` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `username` VARCHAR(32) NOT NULL,
            `nickname` VARCHAR(64) NOT NULL DEFAULT '',
            `password` VARCHAR(255) NOT NULL,
            `email` VARCHAR(128) NULL DEFAULT NULL,
            `phone` VARCHAR(20) NOT NULL DEFAULT '',
            `phone_identity` VARCHAR(20) GENERATED ALWAYS AS (NULLIF(`phone`, '')) STORED,
            `avatar` VARCHAR(255) NOT NULL DEFAULT '',
            `signature` VARCHAR(255) NOT NULL DEFAULT '',
            `role` ENUM('admin','editor','user') NOT NULL DEFAULT 'user',
            `status` TINYINT NOT NULL DEFAULT 1,
            `is_banned` TINYINT NOT NULL DEFAULT 0,
            `is_deleted` TINYINT NOT NULL DEFAULT 0,
            `password_changed_at` DATETIME NULL DEFAULT NULL,
            `login_fail` INT UNSIGNED NOT NULL DEFAULT 0,
            `locked_until` DATETIME NULL DEFAULT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_username` (`username`),
            UNIQUE KEY `uk_email` (`email`),
            UNIQUE KEY `uk_phone` (`phone_identity`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $tables['posts'] = "CREATE TABLE IF NOT EXISTS `{$names['posts']}` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `author_id` INT UNSIGNED NOT NULL,
            `title` VARCHAR(255) NOT NULL,
            `slug` VARCHAR(255) NULL DEFAULT NULL,
            `content` MEDIUMTEXT,
            `excerpt` TEXT,
            `category_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `cover` VARCHAR(255) NOT NULL DEFAULT '',
            `status` ENUM('draft','pending','published','trash') NOT NULL DEFAULT 'draft',
            `is_page` TINYINT NOT NULL DEFAULT 0,
            `is_top` TINYINT NOT NULL DEFAULT 0,
            `views` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_slug` (`slug`),
            KEY `idx_status_created` (`status`,`created_at`),
            KEY `idx_category` (`category_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $tables['comments'] = "CREATE TABLE IF NOT EXISTS `{$names['comments']}` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `post_id` INT UNSIGNED NOT NULL,
            `user_id` INT UNSIGNED NOT NULL,
            `parent_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `content` TEXT,
            `status` ENUM('pending','published','trash') NOT NULL DEFAULT 'pending',
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_post_status` (`post_id`,`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $tables['categories'] = "CREATE TABLE IF NOT EXISTS `{$names['categories']}` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(64) NOT NULL,
            `slug` VARCHAR(64) NOT NULL,
            `description` VARCHAR(255) NOT NULL DEFAULT '',
            `sort` INT NOT NULL DEFAULT 0,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_slug` (`slug`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $tables['options'] = "CREATE TABLE IF NOT EXISTS `{$names['options']}` (
            `option_key` VARCHAR(64) NOT NULL,
            `option_value` MEDIUMTEXT,
            PRIMARY KEY (`option_key`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $tables['verify_codes'] = "CREATE TABLE IF NOT EXISTS `{$names['verify_codes']}` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `scene` ENUM('register','reset','profile') NOT NULL,
            `target` VARCHAR(128) NOT NULL,
            `code` VARCHAR(8) NOT NULL,
            `channel` ENUM('email','sms') NOT NULL,
            `expires_at` DATETIME NOT NULL,
            `used` TINYINT NOT NULL DEFAULT 0,
            `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_scene_target` (`scene`,`target`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        $tables['logs'] = "CREATE TABLE IF NOT EXISTS `{$names['logs']}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `role` VARCHAR(16) NOT NULL DEFAULT 'guest',
            `category` VARCHAR(24) NOT NULL,
            `action` VARCHAR(64) NOT NULL,
            `result` ENUM('success','fail') NOT NULL,
            `detail` TEXT,
            `ip` VARCHAR(45) NOT NULL DEFAULT '',
            `ua` VARCHAR(255) NOT NULL DEFAULT '',
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_category_created` (`category`,`created_at`),
            KEY `idx_user` (`user_id`),
            KEY `idx_ip` (`ip`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        // 插件通用数据存储：global（配置）/user（用户级键值）两种作用域，
        // expires_at 非空即临时缓存（等价 transient），过期由内核每日惰性清理
        $tables['plugin_data'] = "CREATE TABLE IF NOT EXISTS `{$names['plugin_data']}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `plugin` VARCHAR(64) NOT NULL,
            `scope` ENUM('global','user') NOT NULL DEFAULT 'global',
            `user_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `data_key` VARCHAR(191) NOT NULL,
            `data_value` MEDIUMTEXT,
            `expires_at` DATETIME NULL DEFAULT NULL,
            `created_at` DATETIME NOT NULL,
            `updated_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uk_lookup` (`plugin`,`scope`,`user_id`,`data_key`),
            KEY `idx_expires` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";

        return $tables;
    }

    /**
     * password_history 表 DDL（密码历史功能开启时按需建表）
     *
     * @param string $prefix 表前缀
     * @return string SQL
     */
    public static function passwordHistory($prefix)
    {
        $table = DB::table('password_history', $prefix);
        return "CREATE TABLE IF NOT EXISTS `{$table}` (
            `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
            `user_id` INT UNSIGNED NOT NULL,
            `password_hash` VARCHAR(255) NOT NULL,
            `created_at` DATETIME NOT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
    }
}
