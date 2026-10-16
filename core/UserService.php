<?php
/** 用户写入服务：串行裁决联系方式唯一性，兼容尚未补齐唯一索引的存量站。 */
defined('APP_BOOT') or exit;

class UserService
{
    /** 新建账号；公开路径只能创建普通用户。 */
    public static function create(array $data)
    {
        if (isset($data['role']) && $data['role'] !== 'user' && !Auth::check_cap('manage_users')) {
            throw new RuntimeException('user role change requires manage_users');
        }
        return self::write(0, $data);
    }

    /** 保存资料；调用方负责敏感操作重验密码，角色变更仍需管理员能力。 */
    public static function update($userId, array $data)
    {
        if (!Auth::check_cap('manage_users')
            && ((int) $userId !== Auth::id() || !Auth::check_cap('edit_profile')
                || array_diff(array_keys($data), array('nickname', 'signature', 'email', 'phone', 'avatar')))) {
            throw new RuntimeException('user update requires capability');
        }
        return self::write((int) $userId, $data);
    }

    /** 全部注册/后台建号/改绑共用同一锁；不能只锁某个入口。 */
    private static function write($userId, array $data)
    {
        if (!DB::lock('user_contacts', 5)) {
            throw new UserConflictException('busy');
        }
        try {
            foreach (array('username', 'email', 'phone') as $field) {
                if (!isset($data[$field]) || $data[$field] === '') {
                    continue;
                }
                $query = DB::query('users')->where($field, '=', $data[$field]);
                if ($userId > 0) {
                    $query->where('id', '!=', $userId);
                }
                if ($query->value('id') !== null) {
                    throw new UserConflictException($field);
                }
            }
            if ($userId > 0) {
                DB::update('users', $data, array('id' => $userId));
                return $userId;
            }
            return DB::insert('users', $data);
        } catch (PDOException $ex) {
            if ((string) $ex->getCode() === '23000') {
                throw new UserConflictException('duplicate');
            }
            throw $ex;
        } finally {
            DB::unlock('user_contacts');
        }
    }
}

/** 唯一性冲突只携带字段名，不向日志或页面传递 SQL、手机号等原始值。 */
class UserConflictException extends RuntimeException
{
    /** 异常仅保存不含联系方式和 SQL 的原因码。 */
    public function __construct($field)
    {
        parent::__construct($field);
    }
}
