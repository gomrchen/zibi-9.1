<?php

if (!defined('ABSPATH')) {
    exit;
}

if (!class_exists('ZibDBResult')) {
    class ZibDBResult
    {
        protected $data = array();

        public function __construct($data = array())
        {
            $this->data = $data;
        }

        public function toArray()
        {
            if ($this->data === null || $this->data === false) {
                return array();
            }

            if (is_array($this->data)) {
                $arr = array();
                foreach ($this->data as $k => $v) {
                    $arr[$k] = is_object($v) ? (array) $v : $v;
                }
                return $arr;
            }

            return is_object($this->data) ? (array) $this->data : $this->data;
        }

        /**
         * 获取结果集的第一条记录
         * 兼容调用链：select()->find()->toArray()
         * @return ZibDBResult 新的结果对象，包含第一条数据
         */
        public function find()
        {
            $data = $this->data;
            if (is_array($data) && !empty($data)) {
                // 数字索引数组 => 多条记录，取第一条
                if (array_keys($data) === range(0, count($data) - 1)) {
                    $first = reset($data);
                    return new self($first);
                }
                // 关联数组 => 单条记录，直接包装
                return new self($data);
            }
            // 非数组数据（对象、标量、null）也包装返回
            return new self($data);
        }

        public function toArrayMap($callback = null)
        {
            $rows = $this->toArray();
            if (!$callback || !is_callable($callback)) {
                return $rows;
            }

            return array_map($callback, $rows);
        }

        public function result()
        {
            return $this->data;
        }
    }
}

if (!class_exists('ZibDBQuery')) {
    class ZibDBQuery
    {
        protected $table = '';
        protected $alias = '';
        protected $fields = '*';
        protected $where = array();
        protected $where_values = array();
        protected $group = '';
        protected $having = '';
        protected $custom_prefix = '';
        protected $order = '';
        protected $limit = '';
        protected $distinct = false;
        protected $meta_table = '';
        protected $joins = array();
        protected $or_conditions = array();
        protected $exp_updates = array();

        public $insert_id = 0;

        public function __construct($table)
        {
            $this->table = $this->normalize_table($table);
        }

        // 判断字符串是否为原始 SQL 表达式（不应被转义）
        protected function is_raw_expression($value)
        {
            if (!is_string($value)) {
                return false;
            }
            // 包含运算符、空格、括号、逗号、等号、函数调用等视为原始表达式
            return preg_match('/[+\-*\/\(\)\s,=!<>]/', $value) === 1;
        }

        /**
         * 规范化一个值，防止对象被错误传入 SQL 预处理
         * @param mixed $value
         * @param string $context 上下文描述（用于调试）
         * @return string|int|float|null 规范化后的标量值
         */
        protected function normalize_value($value, $context = '')
        {
            if (is_scalar($value) || is_null($value)) {
                return $value;
            }

            if (is_object($value)) {
                // 触发开发者警告，告知传入了对象
                $message = sprintf(
                    'ZibDBQuery 传入了无效的对象类型（%s）作为查询值，位置：%s。已自动转换为字符串，请检查代码。',
                    get_class($value),
                    $context
                );
                _doing_it_wrong('ZibDBQuery::normalize_value', $message, '4.8.2');

                // 尝试获取字符串表示
                if (method_exists($value, '__toString')) {
                    return (string) $value;
                }
                // 对于 ZibDBQuery 对象，可能期望的是子查询，但这里错误使用了值位置，返回空字符串避免 SQL 错误
                if ($value instanceof ZibDBQuery) {
                    return '';
                }
                // 其他对象返回 JSON 表示（调试用）
                return wp_json_encode($value);
            }

            if (is_array($value)) {
                // 递归处理数组中的每个元素（例如 IN 条件）
                return array_map([$this, 'normalize_value'], $value);
            }

            // 其他资源类型等，返回空字符串
            return '';
        }

        public function distinct($distinct = true)
        {
            $this->distinct = (bool) $distinct;
            return $this;
        }

        public function alias($alias)
        {
            $alias = preg_replace('/[^A-Za-z0-9_]/', '', (string) $alias);
            if ($alias !== '') {
                $this->alias = $alias;
            }
            return $this;
        }

        public function join($table, $on, $type = 'INNER')
        {
            $type_sql = strtoupper(trim((string) ($type ?: 'INNER')));
            if (!in_array($type_sql, array('INNER', 'LEFT', 'RIGHT', 'FULL', 'CROSS'), true)) {
                $type_sql = 'INNER';
            }

            $alias = '';
            $tbl_sql = '';

            if (is_array($table) && isset($table[0])) {
                $first = $table[0];
                $alias = isset($table[1]) ? preg_replace('/[^A-Za-z0-9_]/', '', (string) $table[1]) : '';

                if ($first instanceof ZibDBQuery) {
                    $sub_sql = $first->getSql();
                    $tbl_sql = '(' . $sub_sql . ')';
                } else {
                    $tbl_sql = $this->normalize_table($first);
                }
            } else {
                $tbl_sql = $this->normalize_table($table);
            }

            $alias_sql = $alias !== '' ? ' AS `' . $alias . '`' : '';
            $on_sql = (string) $on;
            $this->joins[] = "{$type_sql} JOIN {$tbl_sql}{$alias_sql} ON {$on_sql}";
            return $this;
        }

        public function sum($field)
        {
            return $this->aggregate('SUM', $field);
        }

        public function avg($field)
        {
            return $this->aggregate('AVG', $field);
        }

        public function max($field)
        {
            return $this->aggregate('MAX', $field);
        }

        public function min($field)
        {
            return $this->aggregate('MIN', $field);
        }

        protected function aggregate($func, $field)
        {
            global $wpdb;

            $func = strtoupper(trim((string) $func));
            if (!in_array($func, array('SUM', 'AVG', 'MAX', 'MIN'), true)) {
                $func = 'SUM';
            }

            $field_sql = $this->format_field_expression($field);
            $sql = $this->build_select_sql(false, $func . '(' . $field_sql . ')');
            return $wpdb->get_var($sql);
        }

        public function field($fields)
        {
            $formatted = $this->format_fields($fields);
            if ($formatted !== '') {
                $this->fields = $formatted;
            }
            return $this;
        }

        public function where($field, $operator = null, $value = null)
        {
            if (func_num_args() === 1 && !is_array($field)) {
                if (is_numeric($field)) {
                    $this->add_where('id', '=', (int) $field);
                    return $this;
                }

                if (is_string($field) && trim($field) !== '') {
                    $this->where[] = trim($field);
                    return $this;
                }
            }

            if (is_array($field) && func_num_args() === 1) {
                foreach ($field as $key => $item) {
                    if (is_int($key) && is_array($item) && isset($item[0], $item[1])) {
                        $this->where($item[0], $item[1], $item[2] ?? null);
                        continue;
                    }

                    $this->where($key, $item);
                }
                return $this;
            }

            if (func_num_args() === 2) {
                $value = $operator;
                $operator = '=';
            }

            $this->add_where($field, $operator, $value);
            return $this;
        }

        public function whereTime($field, $range, $value = null)
        {
            $field_sql = $this->escape_identifier($field);
            $range = is_string($range) ? strtolower(trim($range)) : $range;

            if ($range === 'all' || $range === '' || $range === null) {
                return $this;
            }

            if ($range === 'between' && is_array($value) && isset($value[0], $value[1])) {
                $this->add_prepared_where("{$field_sql} BETWEEN %s AND %s", array($value[0], $value[1]));
                return $this;
            }

            if (is_array($range) && isset($range[0], $range[1])) {
                $this->add_prepared_where("{$field_sql} BETWEEN %s AND %s", array($range[0], $range[1]));
                return $this;
            }

            $time_range = $this->resolve_time_range((string) $range);
            if (!$time_range) {
                return $this;
            }

            if (!empty($time_range['operator'])) {
                $this->add_prepared_where("{$field_sql} {$time_range['operator']} %s", array($time_range['value']));
                return $this;
            }

            $this->add_prepared_where("{$field_sql} BETWEEN %s AND %s", array($time_range[0], $time_range[1]));
            return $this;
        }

        public function group($group)
        {
            $this->group = $this->format_group_or_order($group);
            return $this;
        }

        /** 对齐加密 ZibDB::having() */
        public function having($having)
        {
            $this->having = trim((string) $having);
            return $this;
        }

        /** 对齐加密 ZibDB::prefix() — 覆盖表前缀（不含后缀下划线时自动补全） */
        public function prefix($prefix)
        {
            $prefix = (string) $prefix;
            if ($prefix !== '' && substr($prefix, -1) !== '_') {
                $prefix .= '_';
            }
            $this->custom_prefix = $prefix;
            return $this;
        }

        /** 对齐加密 ZibDB::name() 链式短名 */
        public function name($name)
        {
            global $wpdb;
            $name = ltrim((string) $name, '_');
            $prefix = $this->custom_prefix !== '' ? $this->custom_prefix : $wpdb->prefix;
            $this->table = $prefix . $name;
            return $this;
        }

        /** 执行原生 SQL 或当前构建的 SELECT（对齐加密 ZibDB::query） */
        public function query($sql = null)
        {
            global $wpdb;
            if ($sql === null || $sql === '') {
                $sql = $this->build_select_sql(false);
            }
            return $wpdb->query($sql);
        }

        public function order($field, $direction = 'ASC')
        {
            if (!$field) {
                return $this;
            }

            if (func_num_args() === 1) {
                $this->order = $this->format_group_or_order($field);
                return $this;
            }

            $dir = strtoupper((string) $direction);
            $dir = in_array($dir, array('ASC', 'DESC'), true) ? $dir : 'ASC';

            // 原始表达式直接使用，不转义标识符
            if ($this->is_raw_expression($field)) {
                $this->order = "{$field} {$dir}";
            } else {
                $field_sql = $this->escape_identifier($field);
                $this->order = "{$field_sql} {$dir}";
            }
            return $this;
        }

        public function limit($limit, $offset = 0)
        {
            $limit = max(0, (int) $limit);
            $offset = max(0, (int) $offset);

            if (func_num_args() > 1) {
                $this->limit = $offset . ',' . $limit;
                return $this;
            }

            $this->limit = (string) $limit;
            return $this;
        }

        public function page($page, $list_rows = 20)
        {
            $page = max(1, (int) $page);
            $list_rows = max(1, (int) $list_rows);
            return $this->limit($list_rows, ($page - 1) * $list_rows);
        }

        public function getSql()
        {
            return $this->build_select_sql(false);
        }

        public function metaName($table)
        {
            $this->meta_table = $this->normalize_table($table);
            return $this;
        }

        public function metaTable($table)
        {
            return $this->metaName($table);
        }

        public function metaQuery($meta_query, $join_keys = array('id', 'order_id'))
        {
            if (!$this->meta_table || !is_array($meta_query)) {
                return $this;
            }

            $main_key = $join_keys[0];
            $meta_fk = $join_keys[1];

            foreach ($meta_query as $index => $query) {
                if (empty($query['key'])) {
                    continue;
                }

                $alias = !empty($query['alias']) ? $query['alias'] : '_meta_' . $index;
                $meta_key_esc = esc_sql($query['key']);

                $on_clause = "{$this->table}.`{$main_key}` = `{$alias}`.`{$meta_fk}` AND `{$alias}`.`meta_key` = '{$meta_key_esc}'";

                $join_type = 'INNER';
                if (isset($query['value'])) {
                    $compare = isset($query['compare']) ? strtoupper(trim($query['compare'])) : '=';
                    $value = $query['value'];

                    if (($compare === 'IN' || $compare === 'NOT IN') && is_array($value)) {
                        $escaped = array_map('esc_sql', $value);
                        $in_list = "'" . implode("','", $escaped) . "'";
                        $on_clause .= " AND `{$alias}`.`meta_value` {$compare} ({$in_list})";
                    } elseif ($compare === 'BETWEEN' && is_array($value) && isset($value[0], $value[1])) {
                        $on_clause .= " AND `{$alias}`.`meta_value` BETWEEN '" . esc_sql($value[0]) . "' AND '" . esc_sql($value[1]) . "'";
                    } elseif (in_array($compare, array('=', '!=', '<>', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE'), true)) {
                        $on_clause .= " AND `{$alias}`.`meta_value` {$compare} '" . esc_sql($value) . "'";
                    }
                } else {
                    $join_type = 'LEFT';
                }

                $this->joins[] = "{$join_type} JOIN {$this->meta_table} AS `{$alias}` ON {$on_clause}";
            }

            return $this;
        }

        // 支持 whereOr 多种调用形态
        public function whereOr($field, $operator = null, $value = null)
        {
            $argc = func_num_args();

            if ($argc === 1 && is_array($field)) {
                // 判断单维数组 [field, op, v?]
                $is_numeric_seq = true;
                $i = 0;
                foreach ($field as $k => $_) {
                    if ($k !== $i) {
                        $is_numeric_seq = false;
                        break;
                    }
                    $i++;
                }
                $count = count($field);

                if ($is_numeric_seq && $count >= 2 && $count <= 3 && isset($field[0]) && (is_string($field[0]) || is_numeric($field[0]))) {
                    $first = $field[0];
                    if (!is_array($first)) {
                        $f = $first;
                        $o = isset($field[1]) ? $field[1] : '=';
                        $v = isset($field[2]) ? $field[2] : null;
                        $this->add_or_condition($f, $o, $v);
                        return $this;
                    }
                }

                foreach ($field as $key => $item) {
                    if (is_int($key)) {
                        if (is_array($item) && isset($item[0])) {
                            $f = $item[0];
                            $o = isset($item[1]) ? $item[1] : '=';
                            $v = isset($item[2]) ? $item[2] : null;
                            $this->add_or_condition($f, $o, $v);
                        }
                        continue;
                    }
                    $this->add_or_condition($key, '=', $item);
                }
                return $this;
            }

            if ($argc === 2) {
                $value = $operator;
                $operator = '=';
            }

            $this->add_or_condition($field, $operator, $value);
            return $this;
        }

        protected function add_or_condition($field, $operator, $value)
        {
            if (is_numeric($field)) {
                $field_sql = (string) $field;
            } elseif (!is_string($field) || trim($field) === '') {
                return;
            } else {
                $field_sql = $this->escape_identifier($field);
            }

            $op = strtoupper(trim((string) $operator));
            $op = $op ?: '=';

            if ($op === 'IS NULL' || $op === 'IS NOT NULL') {
                $this->or_conditions[] = "{$field_sql} {$op}";
                return;
            }

            // 规范化值，防止对象传入
            $value = $this->normalize_value($value, 'add_or_condition');

            if (is_null($value)) {
                if (in_array($op, array('!=', '<>', 'IS NOT'), true)) {
                    $this->or_conditions[] = "{$field_sql} IS NOT NULL";
                } else {
                    $this->or_conditions[] = "{$field_sql} IS NULL";
                }
                return;
            }

            if (($op === 'IN' || $op === 'NOT IN') && is_array($value)) {
                if (empty($value)) {
                    $this->or_conditions[] = '1 = 0';
                } else {
                    $escaped = array_map('esc_sql', $value);
                    $in_list = "'" . implode("','", $escaped) . "'";
                    $this->or_conditions[] = "{$field_sql} {$op} ({$in_list})";
                }
                return;
            }

            if (!in_array($op, array('=', '!=', '<>', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE'), true)) {
                $op = '=';
            }

            $this->or_conditions[] = "{$field_sql} {$op} '" . esc_sql($value) . "'";
        }

        public function whereLike($fields, $search_text)
        {
            if (!$search_text || !$fields) {
                return $this;
            }

            global $wpdb;
            $fields = (array) $fields;
            $escaped_like = $wpdb->esc_like($search_text);
            $like_parts = array();

            foreach ($fields as $field) {
                if (strpos($field, '.') !== false) {
                    $parts = explode('.', $field, 2);
                    $qualified = '`' . esc_sql($parts[0]) . '`.`' . esc_sql($parts[1]) . '`';
                    $like_parts[] = "{$qualified} LIKE '%%" . esc_sql($escaped_like) . "%%'";
                } else {
                    $field_sql = $this->escape_identifier($field);
                    $like_parts[] = "{$field_sql} LIKE '%%" . esc_sql($escaped_like) . "%%'";
                }
            }

            if ($like_parts) {
                $this->or_conditions = array_merge($this->or_conditions, $like_parts);
            }

            return $this;
        }

        public function find()
        {
            global $wpdb;

            $sql  = $this->build_select_sql(true);
            $data = $wpdb->get_row($sql);

            return new ZibDBResult($data ?: null);
        }

        public function select()
        {
            global $wpdb;

            $sql  = $this->build_select_sql(false);
            $data = $wpdb->get_results($sql);

            return new ZibDBResult($data ?: array());
        }

        /**
         * 统计记录总数
         * @param string $field 统计字段，默认为 '*'
         * @return int
         */
        public function count($field = '*')
        {
            // 如果传入的是纯数字字符串（如 '1'），自动转为 '*'
            if (is_string($field) && preg_match('/^\d+$/', $field)) {
                $field = '*';
            }
            global $wpdb;

            $field = $field ?: '*';
            $field_sql = $field === '*' ? '*' : $this->format_field_expression($field);
            $sql = $this->build_select_sql(false, 'COUNT(' . $field_sql . ')');

            return (int) $wpdb->get_var($sql);
        }

        /** 对齐加密 ZibDB::is_exists() */
        public function is_exists()
        {
            return $this->count() > 0;
        }

        public function value($field)
        {
            global $wpdb;

            $field_sql = $this->format_field_expression($field);
            $sql = $this->build_select_sql(true, $field_sql);

            return $wpdb->get_var($sql);
        }

        public function column($field, $key = '')
        {
            $rows = $this->field($field)->select()->toArray();
            if (!$rows) {
                return array();
            }

            $result = array();
            foreach ($rows as $row) {
                $row = (array) $row;
                $value = reset($row);

                if ($key && isset($row[$key])) {
                    $result[$row[$key]] = $value;
                } else {
                    $result[] = $value;
                }
            }

            return $result;
        }

        public function insert($data)
        {
            global $wpdb;

            $data = $this->normalize_data_array($data);
            if (!$data) {
                return false;
            }

            if (false === $wpdb->insert($this->table, $data)) {
                return false;
            }

            $this->insert_id = (int) $wpdb->insert_id;
            return true;
        }

        public function insertGetId($data)
        {
            global $wpdb;

            $data = $this->normalize_data_array($data);
            if (!$data) {
                return false;
            }

            if (false === $wpdb->insert($this->table, $data)) {
                return false;
            }

            return (int) $wpdb->insert_id;
        }

        public function exp($field, $expression = null)
        {
            if ($expression === null && is_array($field)) {
                $this->exp_updates = array_merge($this->exp_updates, $field);
                return $this;
            }

            if (is_string($field) && is_string($expression)) {
                $this->exp_updates[$field] = $expression;
            }

            return $this;
        }

        public function inc($field, $value = 1)
        {
            $field_sql = $this->escape_identifier($field);
            return $this->exp($field, $field_sql . ' + ' . (int) $value);
        }

        public function dec($field, $value = 1)
        {
            $field_sql = $this->escape_identifier($field);
            return $this->exp($field, $field_sql . ' - ' . (int) $value);
        }

        public function update($data = array())
        {
            global $wpdb;

            $data = $this->normalize_data_array($data);
            if (!$data && !$this->exp_updates) {
                return false;
            }

            $set_parts = array();
            $values = array();

            foreach ($this->exp_updates as $field => $expression) {
                $set_parts[] = $this->escape_identifier($field) . ' = ' . str_replace('%', '%%', (string) $expression);
            }

            foreach ($data as $field => $value) {
                $value = $this->normalize_value($value, 'update');
                $set_parts[] = $this->escape_identifier($field) . ' = ' . (is_numeric($value) ? '%d' : '%s');
                $values[] = $value;
            }

            $sql = "UPDATE {$this->table} SET " . implode(', ', $set_parts);
            if ($this->where) {
                $sql .= ' WHERE ' . implode(' AND ', $this->where);
                $values = array_merge($values, $this->where_values);
            }

            if ($values) {
                $sql = $wpdb->prepare($sql, $values);
            } elseif (strpos($sql, '%%') !== false) {
                $sql = str_replace('%%', '%', $sql);
            }

            return false !== $wpdb->query($sql);
        }

        public function delete()
        {
            global $wpdb;

            $sql = "DELETE FROM {$this->table}";
            if ($this->where) {
                $sql .= ' WHERE ' . implode(' AND ', $this->where);
                $sql = $wpdb->prepare($sql, $this->where_values);
            }

            return false !== $wpdb->query($sql);
        }

        protected function add_where($field, $operator, $value)
        {
            if (!is_string($field) || trim($field) === '') {
                return;
            }

            $field_sql = $this->escape_identifier($field);
            $operator = strtoupper(trim((string) $operator));
            $operator = $operator ?: '=';

            // 规范化值，防止对象传入
            $value = $this->normalize_value($value, 'add_where');

            if (is_null($value)) {
                if (in_array($operator, array('!=', '<>', 'IS NOT'), true)) {
                    $this->where[] = "{$field_sql} IS NOT NULL";
                } else {
                    $this->where[] = "{$field_sql} IS NULL";
                }
                return;
            }

            if ($operator === 'BETWEEN' && is_array($value) && isset($value[0], $value[1])) {
                // BETWEEN 的值也要规范化
                $value0 = $this->normalize_value($value[0], 'add_where_between');
                $value1 = $this->normalize_value($value[1], 'add_where_between');
                $this->add_prepared_where("{$field_sql} BETWEEN %s AND %s", array($value0, $value1));
                return;
            }

            if (is_array($value)) {
                if (!$value) {
                    $this->where[] = in_array($operator, array('NOT IN', 'NOT BETWEEN'), true) ? '1 = 1' : '1 = 0';
                    return;
                }

                if (!in_array($operator, array('IN', 'NOT IN'), true)) {
                    $operator = 'IN';
                }

                $placeholders = array();
                foreach ($value as $item) {
                    $item = $this->normalize_value($item, 'add_where_array_item');
                    $placeholders[] = is_numeric($item) ? '%d' : '%s';
                    $this->where_values[] = $item;
                }

                $this->where[] = "{$field_sql} {$operator} (" . implode(',', $placeholders) . ')';
                return;
            }

            if (!in_array($operator, array('=', '!=', '<>', '>', '>=', '<', '<=', 'LIKE', 'NOT LIKE'), true)) {
                $operator = '=';
            }

            $this->where[] = "{$field_sql} {$operator} " . (is_numeric($value) ? '%d' : '%s');
            $this->where_values[] = $value;
        }

        protected function add_prepared_where($condition, $values = array())
        {
            $this->where[] = $condition;
            foreach ((array) $values as $value) {
                $this->where_values[] = $this->normalize_value($value, 'add_prepared_where');
            }
        }

        /**
         * 构建 SELECT 查询 SQL
         * 修复：将单引号字符串内的 % 双写，避免 date_format 等函数中的 %d/%s 被 prepare 误认为占位符
         */
        protected function build_select_sql($find = false, $fields = null)
        {
            global $wpdb;

            $select = $fields ?: $this->fields;
            $distinct = $this->distinct ? 'DISTINCT ' : '';
            $alias_sql = $this->alias !== '' ? ' AS `' . $this->alias . '`' : '';
            $sql = "SELECT {$distinct}{$select} FROM {$this->table}{$alias_sql}";

            if ($this->joins) {
                $sql .= ' ' . implode(' ', $this->joins);
            }

            $all_where = $this->where;
            if ($this->or_conditions) {
                $all_where[] = '(' . implode(' OR ', $this->or_conditions) . ')';
            }

            if ($all_where) {
                $sql .= ' WHERE ' . implode(' AND ', $all_where);
            }

            if ($this->group) {
                $sql .= ' GROUP BY ' . $this->group;
            }

            if ($this->having !== '') {
                $sql .= ' HAVING ' . $this->having;
            }

            if ($this->order) {
                $sql .= ' ORDER BY ' . $this->order;
            }

            if ($find) {
                $sql .= ' LIMIT 1';
            } elseif ($this->limit !== '') {
                $sql .= ' LIMIT ' . $this->limit;
            }

            // 修复：将 SQL 中所有单引号字符串内的 % 双写为 %%
            // 避免 date_format(create_time, '%Y-%m-%d') 中的 %d 被 wpdb::prepare 误认为占位符
            $sql = preg_replace_callback("/'[^']*'/", function($matches) {
                return str_replace('%', '%%', $matches[0]);
            }, $sql);

            if ($this->where_values) {
                // 最终再规范化一次 where_values，确保没有任何对象残留
                $this->where_values = array_map(function($val) {
                    return $this->normalize_value($val, 'build_select_sql_final');
                }, $this->where_values);
                $sql = $wpdb->prepare($sql, $this->where_values);
            } elseif (strpos($sql, '%%') !== false) {
                // 无参数时还原 %% 为 %（例如仅在 select 中使用了 date_format）
                $sql = str_replace('%%', '%', $sql);
            }

            return $sql;
        }

        protected function format_fields($fields)
        {
            if (is_string($fields)) {
                return trim($fields);
            }

            if (!is_array($fields) || !$fields) {
                return '';
            }

            $result = array();
            foreach ($fields as $field => $alias) {
                if (is_int($field)) {
                    $result[] = $this->format_field_expression($alias);
                    continue;
                }

                $field_sql = $this->format_field_expression($field);
                $alias_sql = $this->escape_alias($alias);
                $result[] = $alias_sql ? "{$field_sql} AS {$alias_sql}" : $field_sql;
            }

            return implode(', ', array_filter($result));
        }

        protected function format_field_expression($field)
        {
            $field = trim((string) $field);
            if ($field === '' || $field === '*') {
                return $field;
            }

            if ($this->is_raw_expression($field)) {
                return $field;
            }

            return $this->escape_identifier($field);
        }

        protected function format_group_or_order($value)
        {
            if (is_array($value)) {
                $parts = array();
                foreach ($value as $item) {
                    $parts[] = $this->format_group_or_order($item);
                }
                return implode(', ', array_filter($parts));
            }

            $value = trim((string) $value);
            if ($value === '') {
                return '';
            }

            if ($this->is_raw_expression($value)) {
                return $value;
            }

            if (preg_match('/[`()\\s,\'"]/', $value)) {
                return $value;
            }

            return $this->escape_identifier($value);
        }

        protected function resolve_time_range($range)
        {
            $timestamp = current_time('timestamp');

            switch ($range) {
                case 'last_10_minutes':
                    return array(
                        'operator' => '>=',
                        'value'    => date('Y-m-d H:i:s', $timestamp - 10 * MINUTE_IN_SECONDS),
                    );

                case 'last_1_day':
                    return array(
                        'operator' => '>=',
                        'value'    => date('Y-m-d H:i:s', $timestamp - DAY_IN_SECONDS),
                    );

                case 'today':
                    return array(
                        date('Y-m-d 00:00:00', $timestamp),
                        date('Y-m-d 23:59:59', $timestamp),
                    );

                case 'yester':
                case 'yesterday':
                    $yesterday = strtotime('-1 day', $timestamp);
                    return array(
                        date('Y-m-d 00:00:00', $yesterday),
                        date('Y-m-d 23:59:59', $yesterday),
                    );

                case 'thismonth':
                    return array(
                        date('Y-m-01 00:00:00', $timestamp),
                        date('Y-m-t 23:59:59', $timestamp),
                    );

                case 'lastmonth':
                    $last_month = strtotime(date('Y-m-01 00:00:00', $timestamp) . ' -1 day');
                    return array(
                        date('Y-m-01 00:00:00', $last_month),
                        date('Y-m-t 23:59:59', $last_month),
                    );

                case 'thisyear':
                    return array(
                        date('Y-01-01 00:00:00', $timestamp),
                        date('Y-12-31 23:59:59', $timestamp),
                    );
            }

            return null;
        }

        protected function normalize_data_array($data)
        {
            if (!is_array($data)) {
                return array();
            }

            $normalized = array();
            foreach ($data as $field => $value) {
                if (!is_string($field) || $field === '') {
                    continue;
                }
                $normalized[$field] = $this->normalize_value($value, 'normalize_data_array');
            }

            return $normalized;
        }

        protected function normalize_table($table)
        {
            global $wpdb;

            $table = trim((string) $table);
            if ($table === '') {
                return $table;
            }

            if (isset($wpdb->$table) && is_string($wpdb->$table) && $wpdb->$table !== '') {
                return $wpdb->$table;
            }

            if (strpos($table, $wpdb->prefix) === 0 || strpos($table, '.') !== false) {
                return $table;
            }

            return $wpdb->prefix . ltrim($table, '_');
        }

        protected function escape_identifier($identifier)
        {
            $identifier = trim((string) $identifier);
            if ($identifier === '' || $identifier === '*') {
                return $identifier;
            }

            if (strpos($identifier, '`') !== false || $this->is_raw_expression($identifier)) {
                return $identifier;
            }

            $parts = explode('.', $identifier);
            $parts = array_map(function ($part) {
                $part = preg_replace('/[^A-Za-z0-9_]/', '', $part);
                return $part === '' ? '' : '`' . $part . '`';
            }, $parts);

            return implode('.', array_filter($parts, 'strlen'));
        }

        protected function escape_alias($alias)
        {
            $alias = preg_replace('/[^A-Za-z0-9_]/', '', (string) $alias);
            return $alias === '' ? '' : '`' . $alias . '`';
        }
    }
}

if (!class_exists('ZibDB')) {
    class ZibDB
    {
        public static function name($table)
        {
            return new ZibDBQuery($table);
        }

        public static function table($table)
        {
            return new ZibDBQuery($table);
        }
    }
}