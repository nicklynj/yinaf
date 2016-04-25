<?php

namespace yinaf;

class database extends \mysqli {

  private $queries = array();
  private $transaction_started;
  private $old_rows;
  private $new_rows;
  private $descriptions;
  private $database_name;
  private $now;
  private $timestampdiffs;
  private $described;

  private function initialize() {
    $this->transaction_started = false;
    $this->old_rows = array();
    $this->new_rows = array();
    $this->descriptions = array();
    $this->database_name = null;
    $this->now = null;
    $this->timestampdiffs = array();
    $this->described = false;
  }
  private function start_transaction() {
    if (!$this->transaction_started) {
      $this->initialize();
      $this->query('start transaction');
      $this->transaction_started = true;
    }
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
        (
          ($table === 'audit_updated') and
          (is_array($str))
        ) or (
          (strpos($column, 'json_') === 0) or
          (strpos($column, 'compressed_json_') === 0)
         )
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
      } else if (
        is_array($id_or_ids_or_attributes) and 
        (!$id_or_ids_or_attributes)
      ) {
        return null;
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
            $contains_null = in_array(null, $value, true);
            $contains_zero = in_array('0', $value);
            $contains_empty_string = in_array('', $value, true);
            $value = array_filter(array_unique($value));
            if ($contains_zero) {
              $value[] = '0';
            }
            if ($contains_empty_string) {
              $value[] = '';
            }
            if ($value) {
              $clause .= ' in (' . implode(',',
                  array_map(
                    array($this, 'escape'), 
                    array_fill(0, count($value), $table), 
                    array_fill(0, count($value), $column), 
                    $value
                  )
                ) . ')';
              if ($contains_null) {
                $clause = '('.$clause.' or '.$this->word($table, $column).' is null)';
              }
            } else {
              $clause .= ' is null';
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
      if (
        (strpos($column['Field'], 'json_') === 0) or
        (strpos($column['Field'], 'compressedd_json_') === 0)
      ) {
        $value = json_decode($value, true);
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
        (strpos($column, 'compressed_json_') !== 0) and
        (!is_array($value))
      ) {
        $value = strval($value);
      }
    }
    return $attributes;
  }
  private function diff($new, $old) {
    $diffs = array();
    foreach ($new as $key => &$value) {
      if (!array_key_exists($key, $old)) {
        $diffs[$key] = $value;
      } else {
        if (is_array($value) or is_array($old[$key])) {
          if (json_encode($old[$key]) !== json_encode($value)) {
            $diffs[$key] = $value;
          }
        } else {
          if (strval($old[$key]) !== strval($value)) {
            $diffs[$key] = $value;
          }
        }
      }
    }
    return $diffs;
  }
  private function audit_get_diffs($prune_rows) {
    $audits = array('new' => array(), 'old' => array());
    foreach ($this->old_rows as $table => &$rows) {
      if ($table !== 'yinaf') {
        if (isset($this->new_rows[$table])) {
          foreach ($rows as $id => &$row) {
            if (
              (isset($this->new_rows[$table][$id])) and
              ($diff = $this->diff($this->new_rows[$table][$id], $row))
            ) {
              if ($prune_rows) {
                $audits['old'][$table][$id] = array_intersect_key(
                  $row, 
                  $audits['new'][$table][$id] = $diff
                );
              } else {
                $audits['old'][$table][$id] = $row;
                $audits['new'][$table][$id] = $this->new_rows[$table][$id];
              }
            }
          }
        }
      }
    }
    foreach ($this->new_rows as $table => &$rows) {
      if ($table !== 'yinaf') {
        foreach ($rows as $id => &$row) {
          if (
            (!isset($this->old_rows[$table])) or
            (!isset($this->old_rows[$table][$id]))
          ) {
            $audits['new'][$table][$id] = $row;
          }
        }
      }
    }
    return $audits;
  }
  private function audit_class() {
    call_user_func_array(
      array(new configuration::$database_audit_class, configuration::$database_audit_function),
      $this->audit_get_diffs(true)
    );
  }
  private function audit() {
    $audits = $this->audit_get_diffs(true);
    $creates = array();
    $updates = array();
    $user_id = null;
    foreach ($audits['new'] as $table => &$rows) {
      if (!in_array($table, configuration::$database_auditing_skip_tables)) {
        if (isset($audits['old'][$table])) {
          foreach ($rows as $id => &$row) {
            if (isset($audits['old'][$table][$id])) {
              foreach ($row as $column => &$value) {
                if (!$user_id) {
                  $user = new user();
                  $session = $user->resume();
                  $user_id = $session['user_id'];
                }
                $updates[] = array(
                  'table' => $table,
                  'id' => $id,
                  'column' => $column,
                  'old_value' => $audits['old'][$table][$id][$column],
                  'new_value' => $value,
                  'user_id' => $user_id,
                );
              }
            }
          }
        }
      }
    }
    foreach ($audits['new'] as $table => &$rows) {
      if (!in_array($table, configuration::$database_auditing_skip_tables)) {
        foreach ($rows as $id => &$row) {
          if (
            (!isset($audits['old'][$table])) or
            (!isset($audits['old'][$table][$id]))
          ) {
            if (!$user_id) {
              $user = new user();
              $session = $user->resume();
              $user_id = $session['user_id'];
            }
            $creates[] = array(
              'table' => $table,
              'id' => $id,
              'user_id' => $user_id,
            );
          }
        }
      }
    }
    if ($creates) {
      $this->create('audit_created', $creates);
    }
    if ($updates) {
      $this->create('audit_updated', $updates);
    }
  }
  private function get_warnings_() {
    $warnings = array();
    $result = $this->query('show warnings');
    while ($row = $result->fetch_assoc()) {
      $warnings[] = $row['Level'] . ' #' . $row['Code'] . ' - ' . $row['Message'];
    }
    return implode(', ', $warnings);
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
      if (!isset($this->new_rows[$table])) {
        $this->new_rows[$table] = array();
      }
      for ($i = 0; isset($attributes_array_index[$i]); ++$i) {
        $id = $this->insert_id + $i * configuration::$database_auto_increment_increment;
        $this->new_rows[$table][$id] = $rows[$id] = $this->stringify($table, 
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
    if ($row = $result->fetch_assoc()) {
      $contains_compressed_or_json = false;
      foreach ($row as $column => &$value) {
        if (
          (strpos($column, 'compressed_') === 0) or
          (strpos($column, 'json_') === 0)
        ) {
          $contains_compressed_or_json = true;
          break;
        }
      }
      $result->data_seek(0);
      while ($row = $result->fetch_assoc()) {
        if ($contains_compressed_or_json) {
          foreach ($row as $column => &$value) {
            if (strpos($column, 'compressed_') === 0) {
              if ($value) {
                $value = gzuncompress(substr($value, 4));
              }
              unset($row[$column]);
              $column = substr($column, 11);
            }
            if (strpos($column, 'json_') === 0) {
              if ($value) {
                if (
                  (!($value = json_decode($value, true))) and
                  (in_array(json_last_error(), array(
                    JSON_ERROR_SYNTAX,
                    JSON_ERROR_CTRL_CHAR,
                    JSON_ERROR_UTF8
                  )))
                ) {
                  throw new Exception('JSON_ERROR in:"'.$table.'#'.$row[$table . '_id'].'.'.$column);
                }
              }
              unset($row[$column]);
              $column = substr($column, 5);
            }
            $row[$column] = &$value;
          }
        }
        if (
          ($where_clause !== '1') and (
            (!isset($this->new_rows[$table])) or
            (!isset($this->new_rows[$table][$row[$table . '_id']]))
          )
        ) {
          if (!isset($this->old_rows[$table])) {
            $this->old_rows[$table] = array();
          }
          if (!isset($this->old_rows[$table][$row[$table . '_id']])) {
            $this->old_rows[$table][$row[$table . '_id']] = $row;
          }
        }
        $results[$row[$table . '_id']] = $row;
      }
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
      if (!isset($rows[$id])) {
        throw new Exception('id: "' . $id . '" not found in table: "' . $table . '"');
      }
      if ($attributes = $this->diff($this->stringify($table, $attributes), $rows[$id])) {
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
        if (!isset($this->new_rows[$table])) {
          $this->new_rows[$table] = array();
        }
        $this->new_rows[$table][$id] = $rows[$id] = $attributes + $rows[$id];
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
  public function get($table/* , $var_args */) {
    $results = call_user_func_array(array($this, 'read'), func_get_args());
    foreach ($results as &$result) {
      return $result;
    }
  }

  public function query($str) {
    if ($str !== 'start transaction') {
      $this->start_transaction();
    }
    if (configuration::$debug) {
      $start = microtime(true);
      $result = parent::query($str);
      $this->queries[] = array(
        'time' => round(microtime(true) - $start, 4),
        'string' => preg_replace('/[^\x09\x0A\x0D(\x20-\x7F)]+/', '?', $str),
        'num_rows' => isset($result->num_rows) ? $result->num_rows : null,
        'info' => $this->info,
        'affected_rows' => $this->affected_rows,
        'insert_id' => $this->insert_id,
      );
      if ($result) {
        if ($this->warning_count) {
          throw new Exception($this->get_warnings_());
        }
        return $result;
      } else {
        throw new Exception($this->error . ':' . $str);
      }
    } else {
      if ($result = parent::query($str)) {
        if ($this->warning_count) {
          throw new Exception($this->get_warnings_());
        }
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
      if (configuration::$debug) {
        try {
          $rows = $this->read('yinaf');
        } catch (Exception $e) {
          if (
            ($this->errno === 1146) and
            ($this->sqlstate === '42S02')
          ) {
            $this->query('
              CREATE TABLE `yinaf` (
                `yinaf_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
                `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
                `table` varchar(255) NOT NULL,
                `json_columns` varchar(65000) NOT NULL,
                PRIMARY KEY (`yinaf_id`),
                UNIQUE KEY `table` (`table`)
              ) ENGINE=InnoDB
            ');
            $rows = $this->read('yinaf');
          } else {
            throw $e;
          }
        }
      } else {
        $rows = $this->read('yinaf');
      }
      foreach ($rows as $row) {
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
          throw new Exception('table does not exist:"'.$this->get_database_name().'.'.$table.'"');
        }
      }
    }
  }
  public function select_db($database_name) {
    if ($database_name !== $this->get_database_name()) {
      return parent::select_db($this->database_name = $database_name);
    } else {
      return true;
    }
  }
  public function uuid() {
    $row = $this->query('select uuid()')->fetch_assoc();
    return $row['uuid()'];
  }
  public function now() {
    if (!$this->now) {
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
      $this->query('rollback');
      $this->initialize();
    }
  }
  public function commit_transaction() {
    if ($this->transaction_started) {
      if (configuration::$database_audit_class) {
        $this->audit_class();
      }
      if (configuration::$database_auditing) {
        $this->audit();
      }
      $this->query('commit');
      $this->initialize();
    }
  }  

}
