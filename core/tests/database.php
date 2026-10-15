<?php
/** 查询构造、日志守卫与事务边界回归：记录真实构造器产生的 SQL 和绑定参数。 */
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
define('APP_BOOT', true);
defined('APP_BOOT') or exit;
require __DIR__ . '/support.php';
require dirname(__DIR__) . '/DB.php';
require dirname(__DIR__) . '/Schema.php';
require dirname(__DIR__) . '/Option.php';

class RecordingConnection
{
    public $statements = array();
    public $rows = array();
    public $scalar = 1;
    public $transaction = false;
    public $commits = 0;
    public $rollbacks = 0;
    public $columns = array();
    public $indexes = array();
    public $duplicatePhones = false;

    public function prepare($sql)
    {
        $statement = new RecordingStatement($sql, $this);
        $this->statements[] = $statement;
        return $statement;
    }

    public function inTransaction() { return $this->transaction; }
    public function beginTransaction() { $this->transaction = true; }
    public function commit() { $this->transaction = false; $this->commits++; }
    public function rollBack() { $this->transaction = false; $this->rollbacks++; }
    public function lastInsertId() { return 1; }
}

class RecordingStatement
{
    public $sql;
    public $params;
    private $connection;

    public function __construct($sql, $connection)
    {
        $this->sql = $sql;
        $this->connection = $connection;
    }
    public function execute($params = array())
    {
        $this->params = $params;
        if (strpos($this->sql, 'ADD COLUMN phone_identity') !== false) {
            $this->connection->columns['phone_identity'] = true;
        }
        foreach (array('uk_phone', 'idx_created') as $index) {
            if (strpos($this->sql, 'ADD UNIQUE KEY ' . $index) !== false || strpos($this->sql, 'ADD KEY ' . $index) !== false) {
                $this->connection->indexes[$index] = true;
            }
        }
    }
    public function fetchAll() { return $this->connection->rows; }
    public function fetch()
    {
        if (strpos($this->sql, 'SHOW COLUMNS') === 0) {
            return isset($this->connection->columns[$this->params[0]]) ? array('Field' => $this->params[0]) : false;
        }
        if (strpos($this->sql, 'SHOW INDEX') === 0) {
            return isset($this->connection->indexes[$this->params[0]]) ? array('Key_name' => $this->params[0]) : false;
        }
        return empty($this->connection->rows) ? false : $this->connection->rows[0];
    }
    public function fetchColumn()
    {
        if (strpos($this->sql, 'GROUP BY phone') !== false) {
            return $this->connection->duplicatePhones ? 2 : false;
        }
        return $this->connection->scalar;
    }
    public function rowCount() { return 1; }
}

$connection = new RecordingConnection();
$property = new ReflectionProperty('DB', 'pdo');
$property->setAccessible(true);
$property->setValue(null, $connection);
foreach (array('update', 'delete', 'increment', 'upsert') as $operation) {
    TestSuite::run('audit guard rejects ' . $operation, function () use ($operation, $connection) {
        $before = count($connection->statements);
        try {
            $query = DB::query('logs');
            switch ($operation) {
                case 'update': $query->update(array('action' => 'changed')); break;
                case 'delete': $query->delete(); break;
                case 'increment': $query->increment('id'); break;
                case 'upsert': $query->upsert(array('id' => 1), array('id')); break;
            }
            TestSuite::check(false);
        } catch (RuntimeException $ex) {
            TestSuite::check(count($connection->statements) === $before);
        }
    });
}
TestSuite::run('upsert binds data and validates identifiers', function () use ($connection) {
    $value = "value' with SQL-like text";
    DB::query('options')->upsert(array('option_key' => 'test', 'option_value' => $value), array('option_value'));
    $statement = end($connection->statements);
    TestSuite::check(strpos($statement->sql, 'ON DUPLICATE KEY UPDATE') !== false);
    TestSuite::check(strpos($statement->sql, $value) === false && $statement->params === array('test', $value));
    try {
        DB::query('options')->upsert(array('bad field' => 1), array('bad field'));
        TestSuite::check(false);
    } catch (InvalidArgumentException $ex) {
        TestSuite::check(true);
    }
});
TestSuite::run('grouped counts bind filters and aggregate in SQL', function () use ($connection) {
    $connection->rows = array(array('cb_group' => 3, 'cb_count' => '7'));
    $counts = DB::query('posts')->where('status', '=', 'published')->countsBy('category_id');
    $statement = end($connection->statements);
    TestSuite::check($counts === array(3 => 7));
    TestSuite::check(strpos($statement->sql, 'GROUP BY category_id') !== false && $statement->params === array('published'));
    $connection->rows = array();
});
TestSuite::run('OR groups keep ownership bound and cannot escape outer filters', function () use ($connection) {
    DB::query('comments')->where('post_id', '=', 7)->whereAnyGroups(array(
        array(array('status', '=', 'published')),
        array(array('status', '=', 'pending'), array('user_id', '=', 1)),
    ))->select();
    $statement = end($connection->statements);
    TestSuite::check(strpos($statement->sql, 'post_id = ? AND ((status = ?) OR (status = ? AND user_id = ?))') !== false);
    TestSuite::check($statement->params === array(7, 'published', 'pending', 1));
});
TestSuite::run('row locking follows bounded selection', function () use ($connection) {
    DB::query('posts')->where('id', '=', 7)->forUpdate()->first();
    $statement = end($connection->statements);
    TestSuite::check(substr($statement->sql, -19) === ' LIMIT 1 FOR UPDATE');
    TestSuite::check($statement->params === array(7));
});
TestSuite::run('increment is atomic and binds delta', function () use ($connection) {
    DB::query('posts')->where('id', '=', 7)->increment('views', 1);
    $statement = end($connection->statements);
    TestSuite::check(strpos($statement->sql, 'LAST_INSERT_ID(`views` + ?)') !== false);
    TestSuite::check($statement->params === array(1, 7));
    try {
        DB::query('posts')->increment('views', 0);
        TestSuite::check(false);
    } catch (InvalidArgumentException $ex) {
        TestSuite::check(true);
    }
});
TestSuite::run('transaction commits returned result', function () use ($connection) {
    $before = $connection->commits;
    $result = DB::transaction(function () use ($connection) {
        TestSuite::check($connection->inTransaction());
        return 17;
    });
    TestSuite::check($result === 17 && $connection->commits === $before + 1 && !$connection->inTransaction());
});
TestSuite::run('transaction rolls back Throwable and propagates it', function () use ($connection) {
    $before = $connection->rollbacks;
    try {
        DB::transaction(function () { throw new Error('expected'); });
        TestSuite::check(false);
    } catch (Error $ex) {
        TestSuite::check($ex->getMessage() === 'expected');
    }
    TestSuite::check($connection->rollbacks === $before + 1 && !$connection->inTransaction());
});
TestSuite::run('nested transaction cannot commit caller transaction', function () use ($connection) {
    DB::begin();
    try {
        DB::transaction(function () {});
        TestSuite::check(false);
    } catch (LogicException $ex) {
        TestSuite::check($connection->inTransaction());
    }
    DB::rollback();
});
TestSuite::run('long prefixes preserve bounded and matching lock keys', function () use ($connection) {
    $property = new ReflectionProperty('DB', 'prefix');
    $property->setAccessible(true);
    $property->setValue(null, str_repeat('p', 60));
    DB::lock('verification_123456789', 5);
    $lock = end($connection->statements)->params[0];
    DB::unlock('verification_123456789');
    TestSuite::check(strlen($lock) <= 64 && end($connection->statements)->params[0] === $lock);
    $property->setValue(null, 'cb_');
});
TestSuite::run('schema uses prefixes and allows multiple empty phones', function () {
    $tables = Schema::definitions('custom_');
    TestSuite::check(strpos($tables['users'], '`custom_users`') !== false);
    TestSuite::check(strpos($tables['users'], "NULLIF(`phone`, '')") !== false);
    TestSuite::check(strpos($tables['users'], 'UNIQUE KEY `uk_phone` (`phone_identity`)') !== false);
    TestSuite::check(strpos(Schema::passwordHistory('custom_'), '`custom_password_history`') !== false);
});
TestSuite::run('schema upgrade can be repeated without repeating ALTER', function () use ($connection) {
    $connection->statements = array();
    Schema::upgrade();
    $first = count(array_filter($connection->statements, function ($statement) { return strpos($statement->sql, 'ALTER TABLE') === 0; }));
    $connection->statements = array();
    Schema::upgrade();
    $second = count(array_filter($connection->statements, function ($statement) { return strpos($statement->sql, 'ALTER TABLE') === 0; }));
    TestSuite::check($first === 3 && $second === 0);
});
TestSuite::run('schema upgrade refuses duplicate phones before any alteration', function () use ($connection) {
    $connection->statements = array();
    $connection->duplicatePhones = true;
    try {
        Schema::upgrade();
        TestSuite::check(false);
    } catch (RuntimeException $ex) {
        TestSuite::check($ex->getMessage() === 'duplicate phone numbers require manual resolution before upgrade');
    }
    TestSuite::check(count(array_filter($connection->statements, function ($statement) { return strpos($statement->sql, 'ALTER TABLE') === 0; })) === 0);
    TestSuite::check(end($connection->statements)->sql === 'SELECT RELEASE_LOCK(?)');
});
exit(TestSuite::finish());
