<?php
/**
 * 数据库封装：PDO 预处理 + 表前缀 + 结构化查询构造器
 * 安全约束：不暴露执行裸 SQL 字符串的公开方法；logs 表只增不改（无 UPDATE/DELETE 通用入口）
 */
defined('APP_BOOT') or exit;

class DB
{
    /** @var PDO|null */
    private static $pdo = null;

    /** @var string 表前缀 */
    private static $prefix = 'cb_';

    /**
     * 初始化 PDO 连接
     *
     * @param array $config db 配置段（host/port/name/user/pass/prefix/charset）
     * @return void
     */
    public static function init(array $config)
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=%s',
            $config['host'],
            isset($config['port']) ? (int) $config['port'] : 3306,
            $config['name'],
            isset($config['charset']) ? $config['charset'] : 'utf8mb4'
        );
        self::$pdo = new PDO($dsn, $config['user'], $config['pass'], array(
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ));
        if (isset($config['prefix']) && $config['prefix'] !== '') {
            self::$prefix = $config['prefix'];
        }
        // MySQL 5.7 兼容：不使用 8.0 专属排序规则与会话特性
    }

    /**
     * 逻辑表名加前缀（所有表名必须经此方法，禁止硬编码）
     *
     * @param string $name 逻辑表名
     * @return string
     */
    public static function table($name, $prefix = null)
    {
        $prefix = $prefix === null ? self::$prefix : $prefix;
        if (!preg_match('/^[A-Za-z0-9_]+$/', $prefix . $name)) {
            throw new InvalidArgumentException('invalid table identifier');
        }
        return $prefix . $name;
    }

    /** @return PDO 仅供内核与查询构造器内部使用 */
    public static function pdo()
    {
        return self::$pdo;
    }

    /** 开始事务 */
    public static function begin()
    {
        self::$pdo->beginTransaction();
    }

    /** 提交事务 */
    public static function commit()
    {
        self::$pdo->commit();
    }

    /** 回滚事务 */
    public static function rollback()
    {
        if (self::$pdo->inTransaction()) {
            self::$pdo->rollBack();
        }
    }

    /** 原子执行多步写入；显式拒绝嵌套，防止误提交或回滚调用方事务。 */
    public static function transaction($callback)
    {
        if (self::$pdo->inTransaction()) {
            throw new LogicException('nested transaction is not supported');
        }
        self::begin();
        try {
            $result = call_user_func($callback);
            self::commit();
            return $result;
        } catch (Throwable $ex) {
            self::rollback();
            throw $ex;
        }
    }

    /**
     * 新建结构化查询构造器（唯一对外查询入口）
     *
     * @param string $table 逻辑表名
     * @return DB_Query
     */
    public static function query($table)
    {
        return new DB_Query($table);
    }

    /**
     * 插入一行
     *
     * @param string $table 逻辑表名
     * @param array  $data  字段=>值
     * @return int 自增主键
     */
    public static function insert($table, array $data)
    {
        return self::query($table)->insert($data);
    }

    /**
     * 按等值条件更新（logs 表禁止）
     *
     * @param string $table 逻辑表名
     * @param array  $data  字段=>值
     * @param array  $where 等值条件
     * @return int 影响行数
     */
    public static function update($table, array $data, array $where)
    {
        self::guardLogs($table, 'UPDATE');
        return self::query($table)->whereMap($where)->update($data);
    }

    /**
     * 按等值条件删除（logs 表禁止）
     *
     * @param string $table 逻辑表名
     * @param array  $where 等值条件
     * @return int 影响行数
     */
    public static function delete($table, array $where)
    {
        self::guardLogs($table, 'DELETE');
        return self::query($table)->whereMap($where)->delete();
    }

    /**
     * 审计日志留存期到期清理：全站唯一允许的 logs 删除通道
     *
     * @param string $cutoff 截止时间（Y-m-d H:i:s），删除早于该时间的记录
     * @return int 清理条数
     */
    public static function purgeLogsBefore($cutoff)
    {
        $stmt = self::$pdo->prepare(
            'DELETE FROM `' . self::table('logs') . '` WHERE created_at < ? LIMIT 5000'
        );
        $stmt->execute(array($cutoff));
        return $stmt->rowCount();
    }

    /**
     * 日志表保护：审计记录只增不改，通用 UPDATE/DELETE 一律拒绝
     */
    private static function guardLogs($table, $op)
    {
        if ($table === 'logs') {
            throw new RuntimeException('audit log is append-only, ' . $op . ' forbidden');
        }
    }

    /**
     * MySQL 命名锁（GET_LOCK）：用于跨请求互斥的读-改-写场景（限流计数、发送配额），
     * 锁名自动加表前缀避免多站共库互串；调用方必须以 db_lock_release 或 try/finally 配对释放
     *
     * @param string $name 逻辑锁名（仅字母数字与短横线）
     * @param int    $timeoutSeconds 获取等待秒数，0 为立即返回
     * @return bool 是否取得锁
     */
    public static function lock($name, $timeoutSeconds = 0)
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name)) {
            return false;
        }
        $stmt = self::$pdo->prepare('SELECT GET_LOCK(?, ?)');
        $stmt->execute(array(self::lockKey($name), max(0, (int) $timeoutSeconds)));
        // fetchColumn 与连接级默认 fetch 模式（ASSOC）无关，必须用它取标量结果
        $value = $stmt->fetchColumn();
        return $value !== false && (int) $value === 1;
    }

    /**
     * 释放命名锁（未持锁时静默；连接断开锁亦自动释放）
     *
     * @param string $name 逻辑锁名
     * @return void
     */
    public static function unlock($name)
    {
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $name)) {
            return;
        }
        $stmt = self::$pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute(array(self::lockKey($name)));
        $stmt->fetch();
    }

    /** MySQL 锁名最多 64 字节；长表前缀仍须与其它站点隔离。 */
    private static function lockKey($name)
    {
        $key = self::$prefix . $name;
        return strlen($key) <= 64 ? $key : 'cb_lock_' . substr(hash('sha256', $key), 0, 56);
    }
}

/**
 * 结构化查询构造器：字段名/排序走标识符校验，值一律绑定参数，LIMIT 整型强转
 */
class DB_Query
{
    /** @var string 带前缀表名 */
    private $table;

    /** @var string 逻辑表名（无前缀，供 logs 守卫比对） */
    private $logicalTable;

    /** @var array where 片段与绑定值 */
    private $wheres = array();
    private $params = array();

    /** @var array join 片段 */
    private $joins = array();

    /** @var string order 片段 */
    private $order = '';

    /** @var string limit 片段 */
    private $limit = '';

    /** @var bool 事务内锁定业务行，避免读完状态后被另一写入改变 */
    private $forUpdate = false;

    /** 允许的比较运算符白名单 */
    private static $ops = array('=', '!=', '<', '>', '<=', '>=', 'LIKE', 'NOT LIKE');

    /**
     * @param string $table 逻辑表名（自动加前缀）
     */
    public function __construct($table)
    {
        $this->logicalTable = self::checkIdent($table);
        $this->table = DB::table($this->logicalTable);
    }

    /**
     * 日志表保护：审计记录只增不改。静态包装 DB::update()/delete() 已有同名守卫，
     * 此处兜底查询构造器直连路径（DB::query('logs')->delete() 等），唯一合法删除
     * 通道是 DB::purgeLogsBefore()（裸 PDO，不经本构造器）
     */
    private function guardLogs($op)
    {
        if ($this->logicalTable === 'logs') {
            throw new RuntimeException('audit log is append-only, ' . $op . ' forbidden');
        }
    }

    /**
     * 校验标识符（表名/字段名，允许 表.字段 与别名空格形式外的纯标识符）
     *
     * @param string $name 标识符
     * @return string
     */
    public static function checkIdent($name)
    {
        if (!preg_match('/^[A-Za-z0-9_]+$/', $name)) {
            throw new InvalidArgumentException('invalid identifier: ' . $name);
        }
        return $name;
    }

    /**
     * 校验 表.字段 形式
     */
    private static function checkField($field)
    {
        if (!preg_match('/^[A-Za-z0-9_]+(\.[A-Za-z0-9_]+)?$/', $field)) {
            throw new InvalidArgumentException('invalid field: ' . $field);
        }
        return $field;
    }

    /** LEFT JOIN（on 两侧为 表.字段 标识符） */
    public function leftJoin($table, $left, $right)
    {
        $table = self::checkIdent($table);
        $this->joins[] = 'LEFT JOIN `' . DB::table($table) . '` ON '
            . self::checkField($left) . ' = ' . self::checkField($right);
        return $this;
    }

    /** 等值/比较条件（运算符白名单） */
    public function where($field, $op, $value)
    {
        $op = strtoupper($op);
        if (!in_array($op, self::$ops, true)) {
            throw new InvalidArgumentException('invalid operator: ' . $op);
        }
        $this->wheres[] = self::checkField($field) . ' ' . $op . ' ?';
        $this->params[] = $value;
        return $this;
    }

    /** IN 条件 */
    public function whereIn($field, array $values)
    {
        if (empty($values)) {
            // 空集合恒假，避免拼出非法 SQL
            $this->wheres[] = '1 = 0';
            return $this;
        }
        $marks = implode(',', array_fill(0, count($values), '?'));
        $this->wheres[] = self::checkField($field) . ' IN (' . $marks . ')';
        foreach ($values as $v) {
            $this->params[] = $v;
        }
        return $this;
    }

    /** 多字段 LIKE 任一命中（搜索场景）；值内通配符转义，防止 % _ 被当作通配符枚举数据 */
    public function likeAny(array $fields, $value)
    {
        // MySQL LIKE 默认以反斜杠为转义符，先转义 \ 再转义 % _
        $escaped = strtr($value, array('\\' => '\\\\', '%' => '\\%', '_' => '\\_'));
        $parts = array();
        foreach ($fields as $field) {
            $parts[] = self::checkField($field) . ' LIKE ?';
            $this->params[] = '%' . $escaped . '%';
        }
        $this->wheres[] = '(' . implode(' OR ', $parts) . ')';
        return $this;
    }

    /** 等值条件数组便捷入口 */
    public function whereMap(array $map)
    {
        foreach ($map as $field => $value) {
            $this->where($field, '=', $value);
        }
        return $this;
    }

    /** 多组条件满足任意一组；每组内为 AND，标识符、运算符与值分别校验或绑定。 */
    public function whereAnyGroups(array $groups)
    {
        $parts = array();
        foreach ($groups as $conditions) {
            $query = new self($this->logicalTable);
            foreach ($conditions as $condition) {
                $query->where($condition[0], $condition[1], $condition[2]);
            }
            if (!empty($query->wheres)) {
                $parts[] = '(' . implode(' AND ', $query->wheres) . ')';
                $this->params = array_merge($this->params, $query->params);
            }
        }
        $this->wheres[] = empty($parts) ? '1 = 0' : '(' . implode(' OR ', $parts) . ')';
        return $this;
    }

    /** 排序（字段名标识符校验 + 方向白名单，可多次调用叠加） */
    public function orderBy($field, $dir = 'DESC')
    {
        $dir = strtoupper($dir) === 'ASC' ? 'ASC' : 'DESC';
        $part = self::checkField($field) . ' ' . $dir;
        $this->order .= ($this->order === '' ? ' ORDER BY ' : ', ') . $part;
        return $this;
    }

    /** 分页（整型强转） */
    public function limit($limit, $offset = 0)
    {
        $this->limit = ' LIMIT ' . max(0, (int) $offset) . ',' . max(1, (int) $limit);
        return $this;
    }

    /** 为事务内的查询加行锁。 */
    public function forUpdate()
    {
        $this->forUpdate = true;
        return $this;
    }

    /** 组装 FROM 片段 */
    private function buildFrom()
    {
        return ' FROM `' . $this->table . '`' . implode('', $this->joins);
    }

    /** 组装 WHERE 片段 */
    private function buildWhere()
    {
        return empty($this->wheres) ? '' : ' WHERE ' . implode(' AND ', $this->wheres);
    }

    /** 校验字段列表（* 或标识符数组） */
    private function buildFields($fields)
    {
        if ($fields === '*') {
            return '*';
        }
        $list = array();
        foreach ((array) $fields as $f) {
            $list[] = self::checkField($f);
        }
        return implode(',', $list);
    }

    /** 查询多行 */
    public function select($fields = '*')
    {
        $sql = 'SELECT ' . $this->buildFields($fields) . $this->buildFrom()
            . $this->buildWhere() . $this->order . $this->limit . ($this->forUpdate ? ' FOR UPDATE' : '');
        $stmt = DB::pdo()->prepare($sql);
        $stmt->execute($this->params);
        return $stmt->fetchAll();
    }

    /** 查询单行 */
    public function first($fields = '*')
    {
        $this->limit = ' LIMIT 1';
        $rows = $this->select($fields);
        return empty($rows) ? null : $rows[0];
    }

    /** 查询单值 */
    public function value($field)
    {
        $row = $this->first(array($field));
        if ($row === null) {
            return null;
        }
        $values = array_values($row);
        return $values[0];
    }

    /** 计数 */
    public function count()
    {
        $sql = 'SELECT COUNT(*) AS cb_cnt' . $this->buildFrom() . $this->buildWhere();
        $stmt = DB::pdo()->prepare($sql);
        $stmt->execute($this->params);
        $row = $stmt->fetch();
        return (int) $row['cb_cnt'];
    }

    /** 按字段聚合计数，避免把所有业务行传到 PHP 再统计。 */
    public function countsBy($field)
    {
        $field = self::checkField($field);
        $stmt = DB::pdo()->prepare('SELECT ' . $field . ' AS cb_group, COUNT(*) AS cb_count'
            . $this->buildFrom() . $this->buildWhere() . ' GROUP BY ' . $field);
        $stmt->execute($this->params);
        $counts = array();
        foreach ($stmt->fetchAll() as $row) {
            $counts[$row['cb_group']] = (int) $row['cb_count'];
        }
        return $counts;
    }

    /** 按唯一键原子插入或更新；日志表仍只允许普通插入。 */
    public function upsert(array $data, array $updateFields)
    {
        $this->guardLogs('UPSERT');
        if (empty($data) || empty($updateFields)) {
            throw new InvalidArgumentException('upsert requires data and update fields');
        }
        $columns = array();
        foreach (array_keys($data) as $column) {
            $columns[] = '`' . self::checkIdent($column) . '`';
        }
        $updates = array();
        foreach ($updateFields as $column) {
            if (!array_key_exists($column, $data)) {
                throw new InvalidArgumentException('upsert field is absent from data');
            }
            $column = '`' . self::checkIdent($column) . '`';
            $updates[] = $column . ' = VALUES(' . $column . ')';
        }
        $stmt = DB::pdo()->prepare('INSERT INTO `' . $this->table . '` (' . implode(',', $columns)
            . ') VALUES (' . implode(',', array_fill(0, count($data), '?')) . ')'
            . ' ON DUPLICATE KEY UPDATE ' . implode(',', $updates));
        $stmt->execute(array_values($data));
        return $stmt->rowCount();
    }

    /** 插入并返回自增 ID */
    public function insert(array $data)
    {
        $cols = array();
        $marks = array();
        $params = array();
        foreach ($data as $col => $value) {
            $cols[] = '`' . self::checkIdent($col) . '`';
            $marks[] = '?';
            $params[] = $value;
        }
        $sql = 'INSERT INTO `' . $this->table . '` (' . implode(',', $cols)
            . ') VALUES (' . implode(',', $marks) . ')';
        $stmt = DB::pdo()->prepare($sql);
        $stmt->execute($params);
        return (int) DB::pdo()->lastInsertId();
    }

    /** 按已设条件更新 */
    public function update(array $data)
    {
        $this->guardLogs('UPDATE');
        $sets = array();
        $params = array();
        foreach ($data as $col => $value) {
            $sets[] = '`' . self::checkIdent($col) . '` = ?';
            $params[] = $value;
        }
        foreach ($this->params as $p) {
            $params[] = $p;
        }
        $sql = 'UPDATE `' . $this->table . '` SET ' . implode(',', $sets) . $this->buildWhere();
        $stmt = DB::pdo()->prepare($sql);
        $stmt->execute($params);
        return $stmt->rowCount();
    }

    /**
     * 按已设条件原子自增/自减并返回自增后的精确值（LAST_INSERT_ID 表达式，并发不丢计数）
     * 注意：仅适用于单调递增（delta>0）的安全计数场景——值未变化的 UPDATE rowCount=0
     * 且 lastInsertId() 会残留上一次会话值，禁止用于 delta<=0 或可能持平的计数
     */
    public function increment($col, $delta = 1)
    {
        $this->guardLogs('UPDATE');
        $delta = (int) $delta;
        if ($delta <= 0) {
            throw new InvalidArgumentException('increment requires a positive delta');
        }
        $colIdent = self::checkIdent($col);
        // LAST_INSERT_ID(expr) 把表达式值写入连接级 LAST_INSERT_ID，随后 lastInsertId() 取回，
        // 避免"读取-计算-写回"在并发请求下互相覆盖（登录失败计数等安全计数器依赖此语义）
        $sql = 'UPDATE `' . $this->table . '` SET `' . $colIdent . '` = LAST_INSERT_ID(`'
            . $colIdent . '` + ?)' . $this->buildWhere();
        $stmt = DB::pdo()->prepare($sql);
        $stmt->execute(array_merge(array($delta), $this->params));
        if ($stmt->rowCount() < 1) {
            return false;
        }
        return (int) DB::pdo()->lastInsertId();
    }

    /** 按已设条件删除 */
    public function delete()
    {
        $this->guardLogs('DELETE');
        $sql = 'DELETE FROM `' . $this->table . '`' . $this->buildWhere() . $this->limit;
        $stmt = DB::pdo()->prepare($sql);
        $stmt->execute($this->params);
        return $stmt->rowCount();
    }
}
