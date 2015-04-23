<?php

class database extends mysqli {

  private $queries = array();
  private $transaction_started = false;
  private $old_rows = array();
  private $new_rows = array();
  private $descriptions = array();
  private $database_name = null;
  private $now = null;
  private $timestampdiffs = array();
  private $described = false;

  private function start_transaction() {
    if (!$this->transaction_started) {
      parent::query('start transaction');
      $this->transaction_started = true;
    }
  }
  private function ends_with($haystack, $needle) {
    return (($length = strlen($haystack) - strlen($needle)) >= 0) and
      (strpos($haystack, $needle, $length) !== false);
  }
  private function word_($str) {
    if (preg_match('/^[\d\w]+$/', $str)) {
      return '`' . $str . '`';
    } else {
      throw new Exception('invalid word');
    }
  }
  private function get_column_name($table, $column) {
    $description = $this->describe($table);
    $columns = array(
      'json_'  . $column,
      'compressed_'  . $column,
      'compressed_json_'  . $column,
    );
    foreach ($description['columns'] as &$description_column) {
      if (in_array($description_column['Field'], $columns)) {
        return $description_column['Field'];
      }
    }
    return $column;
  }
  private function word($table, $column = null) {
    if ($column) {
      return $this->word_($this->get_column_name($table, $column));
    } else {
      return $this->word_($table);
    }
  }
  private function escape($table, $column, $str) {
    if ($str === null) {
      return 'null';
    } else {
      $column = $this->get_column_name($table, $column);
      if (
        (strpos($column, 'json_') === 0) or
        (strpos($column, 'compressed_json_') === 0)
      ) {
        $str = json_encode($str);
      }
      if (
        (strpos($column, 'compressed_') === 0) and
        ($len = strlen($str))
      ) {
        $str = pack('V', $len) . gzcompress($str);
      }
      return '"' . $this->real_escape_string($str) . '"';
    }
  }
  private function column_equals_value($table, $column, $value) {
    return $this->word($table, $column) . '=' . $this->escape($table, $column, $value);
  }
  private function all_numeric_keys($attributes) {
    return ($attributes) and 
      (!(in_array(false, array_map('is_int', array_keys($attributes)), true)));
  }
  private function get_where_clause_from_attributes_array($table, $attributes_array) {
    $clauses = array();
    foreach ($attributes_array as &$id_or_ids_or_attributes) {
      if (
        (is_numeric($id_or_ids_or_attributes)) or
        ($this->all_numeric_keys($id_or_ids_or_attributes))
      ) {
        $attributes = array(
          $table . '_id' => $id_or_ids_or_attributes,
        );
      } else if ($id_or_ids_or_attributes === null) {
        $attributes = array();
      } else {
        $attributes = $id_or_ids_or_attributes;
      }
      foreach ($attributes as $column => &$value) {
        $clause = $this->word($table, $column);
        if (
          (is_array($value)) and
          ((!$value) or $this->all_numeric_keys($value))
        ) {
          if ($value) {
            $clause .= ' in (' . implode(',',
                array_map(
                  array($this, 'escape'), 
                  array_fill(0, count($value), $table), 
                  array_fill(0, count($value), $column), 
                  $value
                )
              ) . ')';
            if (in_array(null, $value, true)) {
              $clause = '('.$clause.' or '.$this->word($table, $column).' is null)';
            }
          } else {
            return null;
          }
        } else if ($value === null) {
          $clause .= ' is null';
        } else {
          $clause .= '=' . $this->escape($table, $column, $value);
        }
        $clauses[] = $clause;
      }
    }
    return $clauses ? 
      '(' . implode(') and (', $clauses) . ')' : 
      '1';
  }
  private function escape_attributes($table, $attributes) {
    return $attributes ? ('(' . implode(',', 
      array_map(
        array($this, 'escape'), 
        array_fill(0, count($attributes), $table),
        array_keys($attributes), 
        array_values($attributes)
      )
    ) . ')') : '()';
  }
  private function get_database_name() {
    if (isset($this->database_name)) {
      return $this->database_name;
    } else {
      return configuration::$database_name;
    }
  }
  private function update_description($table) {
    $database_name = $this->get_database_name();
    $description = $this->describe_($table);
    if (isset($this->descriptions[$database_name][$table])) {
      $ret = $this->update('yinaf', array(
        'yinaf_id' => $this->descriptions[$database_name][$table]['yinaf_id'],
        'columns' => $description,
        'updated_at' => $this->now(),
      ));
    } else {
      $ret = $this->create('yinaf', array(
        'table' => $table,
        'columns' => $description,
        'updated_at' => $this->now(),
      ));
    }
    $this->descriptions[$database_name][$table] = $ret;
  }
  private function describe_($table) {
    $this->described = true;
    $result = $this->query('describe '.$this->word($table).'');
    $columns = array();
    while ($row = $result->fetch_assoc()) {
      $columns[] = $row;
    }
    return $columns;
  }
  private function get_default_row($table) {
    $description = $this->describe($table);
    $row = array();
    foreach ($description['columns'] as &$column) {
      if ($column['Default'] === 'NULL') {
        $value = null;
      } else if ($column['Default'] === 'CURRENT_TIMESTAMP') {
        $value = $this->now();
      } else {
        $value = $column['Default'];
      }
      $row[str_replace(array('compressed_', 'json_'), '', $column['Field'])] = $value;
    }
    return $row;
  }
  private function stringify($table, $attributes) {
    foreach ($attributes as $column => &$value) {
      $column = $this->get_column_name($table, $column);
      if (
        ($value !== null) and
        (strpos($column, 'json_') !== 0) and
        (strpos($column, 'compressed_json_') !== 0)
      ) {
        $value = strval($value);
      }
    }
    return $attributes;
  }
  
  public function __destruct() {
    $this->commit_transaction();
  }
  
  public function create($table, $attributes_or_array) {
    if ($all_numeric_keys = $this->all_numeric_keys($attributes_or_array)) {
      $attributes_array = $attributes_or_array;
    } else {
      $attributes_array = array($attributes_or_array);
    }
    $attributes_array_indexed = array();
    foreach ($attributes_array as &$attributes) {
      $attributes_array_indexed[json_encode(array_keys($attributes))][] = &$attributes;
    }
    $rows = array();
    $default_row = $this->get_default_row($table);
    foreach ($attributes_array_indexed as $index => &$attributes_array_index) {
      $this->query('insert into ' . $this->word($table) . ' ' . 
        ' (' . ($attributes_array_index[0] ? implode(',', array_map(
            array($this, 'word'), 
            array_fill(0, count($attributes_array_index[0]), $table),
            array_keys($attributes_array_index[0])
        )) : '') . ') values ' .
        implode(',', array_map(
          array($this, 'escape_attributes'), 
          array_fill(0, count($attributes_array_index), $table),
          $attributes_array_index
        ))
      );
      $last_insert_id = $this->insert_id;
      for ($i = 0; isset($attributes_array_index[$i]); ++$i) {
        $id = $this->insert_id - (count($attributes_array_index) - $i - 1) * configuration::$database_auto_increment_increment;
        $rows[$id] = $this->stringify($table, 
          $attributes_array_index[$i] + 
          array($table . '_id' => $id) +
          $default_row
        );
      }
    }
    if ($all_numeric_keys) {
      return $rows;
    } else {
      foreach ($rows as &$row) {
        return $row;
      }
    }
  }
  public function read($table/* , $var_args */) {
    if (!($where_clause = $this->get_where_clause_from_attributes_array($table, array_slice(func_get_args(), 1)))) {
      return array();
    }
    $result = $this->query('select * from ' . $this->word($table) . ' where ' . $where_clause);
    $results = array();
    while ($row = $result->fetch_assoc()) {
      $result_row = array();
      foreach ($row as $column => $value) {
        if ($value) {
          if (strpos($column, 'compressed_') === 0) {
            $value = gzuncompress(substr($value, 4));
            $column = substr($column, 11);
          }
          if (strpos($column, 'json_') === 0) {
            $value = json_decode($value, true);
            $column = substr($column, 5);
          }
        }
        $result_row[$column] = $value;
      }
      $results[$row[$table . '_id']] = $result_row;
    }
    return $results;
  }
  public function update($table, $attributes_or_array/* , $var_args */) {
    if (!$attributes_or_array) {
      return array();
    }
    if ($all_numeric_keys = $this->all_numeric_keys($attributes_or_array)) {
      $attributes_array = $attributes_or_array;
    } else {
      $attributes_array = array($attributes_or_array);
    }
    $rows = $this->read($table, array_column($attributes_array, $table . '_id'));
    foreach ($attributes_array as &$attributes) {
      $id = $attributes[$table . '_id'];
      unset($attributes[$table . '_id']);
      if ($attributes) {
        $this->query('update ' . $this->word($table) . ' set ' . implode(',',
          array_map(
            array($this, 'column_equals_value'),
            array_fill(0, count($attributes), $table),
            array_keys($attributes),
            array_values($attributes)
          )
        ) . ' where ' . $this->get_where_clause_from_attributes_array($table, array_merge(
          array($table . '_id' => $id),
          array_slice(func_get_args(), 2)
        )));
        if (
          (!preg_match('/matched\: (\d+)/', $this->info, $match)) or
          (!$match[1])
        ) {
          throw new Exception('match not found on table:"' . $table . '", id:"' . $id . '"');
        }
      }
      $rows[$id] = $this->stringify($table, $attributes) + $rows[$id];
    }
    if ($all_numeric_keys) {
      return $rows;
    } else {
      foreach ($rows as &$row) {
        return $row;
      }
    }
  }
  public function get($table/* , $var_args */) {
    $results = call_user_func_array(array($this, 'read'), func_get_args());
    foreach ($results as &$result) {
      return $result;
    }
  }

  public function query($str) {
    $this->start_transaction();
    if (configuration::$debug) {
      $start = microtime(true);
      $result = parent::query($str);
      $this->queries[] = array(
        'time' => round(microtime(true) - $start, 4),
        'string' => preg_replace('/[^\x09\x0A\x0D(\x20-\x7F)]+/', '?', $str),
        'num_rows' => isset($result->num_rows) ? $result->num_rows : null,
        'info' => $this->info,
        'affected_rows' => $this->affected_rows,
      );
      if ($result) {
        return $result;
      } else {
        throw new Exception($this->error . ':' . $str);
      }
    } else {
      if ($result = parent::query($str)) {
        return $result;
      } else {
        throw new Exception($this->error . ':' . $str);
      }
    }
  }

  public function describe($table) {
    if ($table === 'yinaf') {
      return array(
        'yinaf_id' => '0',
        'updated_at' => '9999-12-31 23:59:59',
        'name' => 'yinaf',
        'columns' => array(
          array(
            'Field' => 'yinaf_id',
            'Type' => 'int(10) unsigned',
            'Null' => 'NO',
            'Key' => 'PRI',
            'Default' => 'NULL',
            'Extra' => 'auto_increment',
          ),
          array(
            'Field' => 'table',
            'Type' => 'varchar(255)',
            'Null' => 'NO',
            'Key' => 'UNI',
            'Default' => 'NULL',
            'Extra' => '',
          ),
          array(
            'Field' => 'json_columns',
            'Type' => 'varchar(65000)',
            'Null' => 'NO',
            'Key' => '',
            'Default' => 'NULL',
            'Extra' => '',
          ),
          array(
            'Field' => 'updated_at',
            'Type' => 'timestamp',
            'Null' => 'NO',
            'Key' => '',
            'Default' => 'CURRENT_TIMESTAMP',
            'Extra' => '',
          ),
        ),
      );
    }
    $database_name = $this->get_database_name();
    if (
      (isset($this->descriptions[$database_name])) and
      (isset($this->descriptions[$database_name][$table]))
    ) {
      return $this->descriptions[$database_name][$table];
    } else {
      foreach ($this->read('yinaf') as $row) {
        $this->descriptions[$database_name][$row['table']] = $row;
      }
      if (isset($this->descriptions[$database_name][$table])) {
        if (
          (!$this->described) and
          ($this->timestampdiff($this->descriptions[$database_name][$table]['updated_at']) > 3600)
        ) {
          $this->update_description($table);
        }
        return $this->descriptions[$database_name][$table];
      } else {
        $result = $this->query('show tables');
        while ($row = $result->fetch_array()) {
          $this->update_description($row[0]);
        }
        if (isset($this->descriptions[$database_name][$table])) {
          return $this->descriptions[$database_name][$table];
        } else {
          throw new Exception('table does not exist');
        }
      }
    }
  }
  public function select_db($database_name) {
    return parent::select_db($this->database_name = $database_name);
  }
  public function uuid() {
    $row = $this->query('select uuid()')->fetch_assoc();
    return $row['uuid()'];
  }
  public function now() {
    if ($this->now) {
      $row = $this->query('select now()')->fetch_assoc();
      $this->now = $row['now()'];
    }
    return $this->now;
  }
  public function timestampdiff($from) {
    if (!isset($this->timestampdiffs[$from])) {
      $row = $this->query('select timestampdiff(second, "'.$this->real_escape_string($from).'", now()) `diff`')->fetch_assoc();
      $this->timestampdiffs[$from] = $row['diff'];
    }
    return $this->timestampdiffs[$from];
  }
  public function get_queries() {
    return $this->queries;
  }

  public function rollback_transaction() {
    if ($this->transaction_started) {
      $this->old_rows = array();
      $this->new_rows = array();
      $this->now = null;
      $this->timestampdiffs = array();
      $this->query('rollback');
      $this->transaction_started = false;
      $this->described = false;
    }
  }
  public function commit_transaction() {
    if ($this->transaction_started) {
      // if (class_exists('audit')) {
        // foreach ($this->old_rows as $table => &$rows) {
          // if (isset($this->new_rows[$table])) {
            // foreach ($rows as $id => &$row) {
              // if (isset($this->new_rows[$table][$id])) {
                // $this->new_rows[$table][$id] = array_diff_assoc(
                  // $this->new_rows[$table][$id],
                  // $row
                // );
                // $row = array_intersect_key(
                  // $row,
                  // $this->new_rows[$table][$id]
                // );
              // } else {
                // unset($this->old_rows[$table][$id]);
              // }
              // unset($row);
            // }
          // } else {
            // unset($this->old_rows[$table]);
          // }
          // unset($rows);
        // }
        // new audit($this->old_rows, $this->new_rows);
      // }
      $this->old_rows = array();
      $this->new_rows = array();
      $this->now = null;
      $this->timestampdiffs = array();
      $this->query('commit');
      $this->transaction_started = false;
      $this->described = false;
    }
  }  

}
