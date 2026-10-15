<?php
/** 服务层数据库替身：模拟行、锁边界及失败，不替代真实 MySQL 联调。 */
defined('APP_BOOT') or exit;

class DB
{
    public static $rows = array();
    public static $locks = array();
    public static $allowLock = true;
    public static $onLock = null;
    public static $failTable = null;
    public static $inTransaction = false;

    public static function query($table)
    {
        return new MemoryQuery($table);
    }

    public static function insert($table, array $data)
    {
        self::beforeWrite($table);
        $data['id'] = isset($data['id']) ? $data['id'] : count(isset(self::$rows[$table]) ? self::$rows[$table] : array()) + 1;
        self::$rows[$table][] = $data;
        return $data['id'];
    }

    public static function update($table, array $data, array $where)
    {
        return self::query($table)->whereMap($where)->update($data);
    }

    public static function delete($table, array $where)
    {
        return self::query($table)->whereMap($where)->delete();
    }

    public static function lock($name, $timeout)
    {
        if (!self::$allowLock) {
            return false;
        }
        self::$locks[$name] = true;
        if (self::$onLock !== null) {
            $callback = self::$onLock;
            self::$onLock = null;
            $callback();
        }
        return true;
    }

    public static function unlock($name)
    {
        unset(self::$locks[$name]);
    }

    public static function transaction($callback)
    {
        $snapshot = self::$rows;
        self::$inTransaction = true;
        try {
            return $callback();
        } catch (Throwable $ex) {
            self::$rows = $snapshot;
            throw $ex;
        } finally {
            self::$inTransaction = false;
        }
    }

    public static function beforeWrite($table)
    {
        if (self::$failTable === $table) {
            throw new RuntimeException('simulated storage failure');
        }
    }
}

class MemoryQuery
{
    private $table;
    private $conditions = array();
    private $orders = array();
    private $limit = null;
    private $offset = 0;

    public function __construct($table)
    {
        $this->table = $table;
    }

    public function where($field, $op, $value)
    {
        $this->conditions[] = array($field, $op, $value);
        return $this;
    }

    public function whereMap(array $map)
    {
        foreach ($map as $field => $value) {
            $this->where($field, '=', $value);
        }
        return $this;
    }

    public function whereIn($field, array $values)
    {
        return $this->where($field, 'IN', $values);
    }

    public function whereAnyGroups(array $groups)
    {
        return $this->where('__any', '=', $groups);
    }

    public function likeAny(array $fields, $value)
    {
        return $this->where('__like', '=', array($fields, $value));
    }

    public function countsBy($field)
    {
        $counts = array();
        foreach ($this->select() as $row) {
            $key = $row[$field];
            $counts[$key] = isset($counts[$key]) ? $counts[$key] + 1 : 1;
        }
        return $counts;
    }

    public function orderBy($field, $direction = 'DESC')
    {
        $this->orders[] = array($field, $direction);
        return $this;
    }

    public function limit($limit, $offset = 0)
    {
        $this->limit = $limit;
        $this->offset = $offset;
        return $this;
    }

    public function forUpdate()
    {
        TestSuite::check(DB::$inTransaction, 'row lock requires transaction');
        return $this;
    }

    private function matches(array $row)
    {
        foreach ($this->conditions as $condition) {
            list($field, $op, $value) = $condition;
            if ($field === '__like') {
                $matched = false;
                foreach ($value[0] as $name) {
                    if (stripos($row[$name], $value[1]) !== false) {
                        $matched = true;
                    }
                }
                if (!$matched) {
                    return false;
                }
                continue;
            }
            if ($field === '__any') {
                $matched = false;
                foreach ($value as $group) {
                    $query = new self($this->table);
                    foreach ($group as $item) {
                        $query->where($item[0], $item[1], $item[2]);
                    }
                    if ($query->matches($row)) {
                        $matched = true;
                        break;
                    }
                }
                if (!$matched) {
                    return false;
                }
                continue;
            }
            $actual = isset($row[$field]) ? $row[$field] : null;
            switch ($op) {
                case '=': $ok = $actual == $value; break;
                case '!=': $ok = $actual != $value; break;
                case '>': $ok = $actual > $value; break;
                case '>=': $ok = $actual >= $value; break;
                case '<': $ok = $actual < $value; break;
                case '<=': $ok = $actual <= $value; break;
                case 'IN': $ok = in_array($actual, $value); break;
                case 'NOT LIKE': $ok = strpos($actual, 'throttle_') !== 0; break;
                default: throw new RuntimeException('unsupported test condition');
            }
            if (!$ok) {
                return false;
            }
        }
        return true;
    }

    public function select($fields = '*')
    {
        $rows = isset(DB::$rows[$this->table]) ? DB::$rows[$this->table] : array();
        $rows = array_values(array_filter($rows, function ($row) { return $this->matches($row); }));
        usort($rows, function ($a, $b) {
            foreach ($this->orders as $order) {
                $difference = $a[$order[0]] <=> $b[$order[0]];
                if ($difference !== 0) {
                    return $order[1] === 'ASC' ? $difference : -$difference;
                }
            }
            return 0;
        });
        return $this->limit === null ? $rows : array_slice($rows, $this->offset, $this->limit);
    }

    public function first($fields = '*')
    {
        $rows = $this->select($fields);
        return empty($rows) ? null : $rows[0];
    }

    public function value($field)
    {
        $row = $this->first();
        return $row === null ? null : $row[$field];
    }

    public function count()
    {
        return count($this->select());
    }

    public function update(array $data)
    {
        DB::beforeWrite($this->table);
        $count = 0;
        foreach (DB::$rows[$this->table] as &$row) {
            if ($this->matches($row)) {
                $row = array_merge($row, $data);
                $count++;
            }
        }
        return $count;
    }

    public function increment($field, $delta = 1)
    {
        $row = $this->first();
        if ($row === null) {
            return false;
        }
        $value = (int) $row[$field] + $delta;
        $this->update(array($field => $value));
        return $value;
    }

    public function delete()
    {
        DB::beforeWrite($this->table);
        DB::$rows[$this->table] = array_values(array_filter(DB::$rows[$this->table], function ($row) { return !$this->matches($row); }));
    }

    public function upsert(array $data, array $updateFields)
    {
        $keyFields = $this->table === 'options' ? array('option_key') : array('plugin', 'scope', 'user_id', 'data_key');
        $query = new self($this->table);
        foreach ($keyFields as $field) {
            $query->where($field, '=', $data[$field]);
        }
        if ($query->first() === null) {
            DB::insert($this->table, $data);
        } else {
            $query->update(array_intersect_key($data, array_flip($updateFields)));
        }
    }
}
