<?php
namespace Core;

abstract class Model implements \JsonSerializable
{
    protected static $table = '';           // defaults to lowercase class name + "s"
    protected static $primaryKey = 'id';
    protected static $fillable = [];        // empty = everything is fillable
    protected static $timestamps = true;    // needs created_at / updated_at columns
    protected static $hidden = [];          // attributes left out of JSON output (e.g. password)

    protected $attributes = [];
    protected $exists = false;

    public function __construct(array $attributes = [])
    {
        $this->fill($attributes);
    }

    // ---------- helpers ----------
    public static function table(): string
    {
        if (static::$table !== '') {
            return static::$table;
        }
        return strtolower((new \ReflectionClass(static::class))->getShortName()) . 's';
    }

    private static function db(): Database
    {
        return Database::instance();
    }

    private static function clean(string $column): string
    {
        return preg_replace('/[^\w]/', '', $column);
    }

    private static function orderSql(?string $order): string
    {
        $order = trim((string) $order);
        return ($order !== '' && preg_match('/^\w+(\s+(ASC|DESC))?$/i', $order)) ? ' ORDER BY ' . $order : '';
    }

    private static function whereSql(array $conditions): array
    {
        $parts = [];
        $values = [];
        foreach ($conditions as $col => $val) {
            $col = '`' . self::clean($col) . '`';
            if ($val === null) {
                $parts[] = "$col IS NULL";
            } else {
                $parts[] = "$col = ?";
                $values[] = $val;
            }
        }
        return [$parts ? ' WHERE ' . implode(' AND ', $parts) : '', $values];
    }

    protected static function hydrate(array $row)
    {
        $m = new static();
        $m->attributes = $row;
        $m->exists = true;
        return $m;
    }

    // ---------- reading ----------
    public static function query(string $sql, array $params = []): array
    {
        $rows = self::db()->query($sql, $params)->fetchAll();
        return array_map(function ($row) {
            return static::hydrate($row);
        }, $rows);
    }

    public static function all(?string $orderBy = null): array
    {
        return self::query('SELECT * FROM `' . static::table() . '`' . self::orderSql($orderBy));
    }

    public static function where(array $conditions, ?string $orderBy = null, ?int $limit = null): array
    {
        list($where, $values) = self::whereSql($conditions);
        $sql = 'SELECT * FROM `' . static::table() . '`' . $where . self::orderSql($orderBy);
        if ($limit !== null) {
            $sql .= ' LIMIT ' . (int) $limit;
        }
        return self::query($sql, $values);
    }

    public static function first(array $conditions = [], ?string $orderBy = null)
    {
        $rows = self::where($conditions, $orderBy, 1);
        return $rows[0] ?? null;
    }

    public static function find($id)
    {
        return self::first([static::$primaryKey => $id]);
    }

    public static function findOrFail($id)
    {
        $m = self::find($id);
        if (!$m) {
            throw new HttpException(404);
        }
        return $m;
    }

    public static function count(array $conditions = []): int
    {
        list($where, $values) = self::whereSql($conditions);
        $st = self::db()->query('SELECT COUNT(*) AS c FROM `' . static::table() . '`' . $where, $values);
        return (int) $st->fetch()['c'];
    }

    public static function paginate(int $perPage = 10, int $page = 1, array $conditions = [], ?string $orderBy = null): array
    {
        $total = self::count($conditions);
        $pages = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $pages);
        list($where, $values) = self::whereSql($conditions);
        $sql = 'SELECT * FROM `' . static::table() . '`' . $where . self::orderSql($orderBy)
             . ' LIMIT ' . (int) $perPage . ' OFFSET ' . (int) (($page - 1) * $perPage);
        return ['data' => self::query($sql, $values), 'total' => $total, 'page' => $page, 'pages' => $pages];
    }

    // ---------- writing ----------
    public static function create(array $data)
    {
        $m = new static($data);
        $m->save();
        return $m;
    }

    public function fill(array $data): self
    {
        $fillable = static::$fillable;
        foreach ($data as $k => $v) {
            if ($fillable === [] || in_array($k, $fillable, true)) {
                $this->attributes[$k] = $v;
            }
        }
        return $this;
    }

    public function update(array $data): bool
    {
        $this->fill($data);
        return $this->save();
    }

    public function save(): bool
    {
        $db = self::db();
        $table = '`' . static::table() . '`';
        $pk = static::$primaryKey;
        $now = date('Y-m-d H:i:s');

        if ($this->exists) {
            if (static::$timestamps) {
                $this->attributes['updated_at'] = $now;
            }
            $sets = [];
            $vals = [];
            foreach ($this->attributes as $k => $v) {
                if ($k === $pk) continue;
                $sets[] = '`' . self::clean($k) . '` = ?';
                $vals[] = $v;
            }
            $vals[] = $this->attributes[$pk];
            $db->query("UPDATE $table SET " . implode(', ', $sets) . " WHERE `$pk` = ?", $vals);
            return true;
        }

        if (static::$timestamps) {
            $this->attributes['created_at'] = $now;
            $this->attributes['updated_at'] = $now;
        }
        $cols = [];
        $vals = [];
        foreach ($this->attributes as $k => $v) {
            $cols[] = '`' . self::clean($k) . '`';
            $vals[] = $v;
        }
        $marks = implode(', ', array_fill(0, count($cols), '?'));
        $db->query("INSERT INTO $table (" . implode(', ', $cols) . ") VALUES ($marks)", $vals);
        $this->attributes[$pk] = $db->lastInsertId();
        $this->exists = true;
        return true;
    }

    public function delete(): bool
    {
        if (!$this->exists) {
            return false;
        }
        $pk = static::$primaryKey;
        self::db()->query('DELETE FROM `' . static::table() . "` WHERE `$pk` = ?", [$this->attributes[$pk]]);
        $this->exists = false;
        return true;
    }

    // ---------- attribute access ----------
    public function __get($key)        { return $this->attributes[$key] ?? null; }
    public function __set($key, $val)  { $this->attributes[$key] = $val; }
    public function __isset($key)      { return isset($this->attributes[$key]); }
    public function toArray(): array   { return $this->attributes; }
    #[\ReturnTypeWillChange]
    public function jsonSerialize()    { return array_diff_key($this->attributes, array_flip(static::$hidden)); }
}
