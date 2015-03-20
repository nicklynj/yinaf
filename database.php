<?php

class database extends mysqli {

  private $queries;
  private $transaction_started = false;
  private $commits_disabled = false;
  private $readback_disabled = false;
  private $old_rows = array();
  private $new_rows = array();
  private function word($str) {
    if (preg_match('/^[\d\w]+$/', $str)) {
      return '`' . $str . '`';
    } else {
      throw new Exception('invalid word');
    }
  }
  private function column_equals_value($column, $value) {
    return $this->word($column) . '=' . $this->escape($value, $column);
  }
  private function start_transaction() {
    if (!$this->transaction_started) {
      $query = 'start transaction';
      $this->query_($query);
      $this->transaction_started = true;
      $this->queries = array();
    }
  }
  private function commit_transaction() {
    if (
      ($this->transaction_started) and
      (!$this->commits_disabled)
    ) {
      if (class_exists('audit')) {
        foreach ($this->old_rows as $table => &$rows) {
          if (isset($this->new_rows[$table])) {
            foreach ($rows as $id => &$row) {
              if (isset($this->new_rows[$table][$id])) {
                $this->new_rows[$table][$id] = array_diff_assoc(
                  $this->new_rows[$table][$id],
                  $row
                );
                $row = array_intersect_key(
                  $row,
                  $this->new_rows[$table][$id]
                );
              } else {
                unset($this->old_rows[$table][$id]);
              }
              unset($row);
            }
          } else {
            unset($this->old_rows[$table]);
          }
          unset($rows);
        }
        $this->disable_readback();
        new audit($this->old_rows, $this->new_rows);
        $this->enable_readback();
      }
      $this->old_rows = array();
      $this->new_rows = array();
      $query = 'commit';
      $this->query_($query);
      $this->transaction_started = false;
      $this->queries = array();
    }
  }
  private function query_($str) {
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
  private function all_numeric_keys($attributes) {
    return ($attributes) and 
      (!(in_array(false, array_map('is_int', array_keys($attributes)), true)));
  }
  private function get_clause_from_attributes($table, $ids_or_attributes) {
    if ($this->all_numeric_keys($ids_or_attributes)) {
      $attributes = array(
        $table . '_id' => $ids_or_attributes,
      );
    } else {
      $attributes = $ids_or_attributes;
    }
    $clauses = array();
    foreach ($attributes as $key => $value) {
      $clause = $this->word($key);
      if (is_array($value)) {
        if ($value) {
          $clause .= ' in (' . implode(',',
              array_map(array($this, 'escape'), $value)
            ) . ')';
        } else {
          return array();
        }
      } else if ($value === null) {
        $clause .= ' is null';
      } else {
        $clause .= '=' . $this->escape($value);
      }
      $clauses[] = $clause;
    }
    return $clauses ? 
      '(' . implode(') and (', $clauses) . ')' : 
      '1';
  }
  private function escape($str, $column = '') {
    if ($str === null) {
      return 'null';
    } else {
      if (strpos($column, 'json') !== false) {
        $str = json_encode($str);
      }
      if (strpos($column, 'compressed') !== false) {
        $str = pack('H*', str_pad(dechex(strlen($str)), 8, '0')) . gzcompress($str);
      }
      return '"' . $this->real_escape_string($str) . '"';
    }
  }
  
  
  public function __destruct() {
    $this->commit_transaction();
  }
  public function __construct() {
    call_user_func_array(array($this, 'parent::__construct'), func_get_args());
    $this->start_transaction();
  }
  
  public function get_queries() {
    return $this->queries;
  }
  
  public function disable_commits() {
    $this->commits_disabled = true;
  }
  public function disable_readback() {
    $this->readback_disabled = true;
  }
  public function enable_readback() {
    $this->readback_enabled = true;
  }
  
  public function uuid() {
    $result = $this->query_('select uuid()');
    return $result['uuid()'];
  }
  
  public function query($str) {
    throw new Exception('query disabled');
  }

  public function commit() {
    $this->commit_transaction();
  }
  
  public function create($table, $attributes) {
    $this->start_transaction();
    if (!$attributes) {
      return array();
    }
    if (!($all_numeric_keys = $this->all_numeric_keys($attributes))) {
      $attributes = array($attributes);
    }
    $attributes_indexed = array();
    foreach ($attributes as &$attribute) {
      $attributes_indexed[json_encode(array_keys($attribute))][] = &$attribute;
    }
    $ids = array();
    foreach ($attributes_indexed as $index => &$attribute_indexed) {
      $columns = json_decode($index, true);
      $query = 
        'insert into ' . $this->word($table) . ' ' . 
        ' (' . implode(',', array_map(array($this, 'word'), array_keys($attribute_indexed[0]))) . ') ' .
          'values ';
      $inserts = array();
      for ($i = 0; isset($attribute_indexed[$i]); ++$i) {
        $inserts[] = '(' . implode(',', array_map(array($this, 'escape'), $attribute_indexed[$i], $columns)) . ')';
      }
      $this->query_($query . implode(',', $inserts));
      $last_insert_id = $this->insert_id;
      for ($j = 0; $j < $i; ++$j) {
        $ids[] = $last_insert_id - configuration::$database_auto_increment_increment * $j;
      }
    }
    if ($this->readback_disabled) {
      if ($all_numeric_keys) {
        return $ids;
      } else {
        foreach ($ids as $id) {
          return $id;
        }
      }
    } else {
      if ($all_numeric_keys) {
        return array_values($this->read($table, $ids, true));
      } else {
        return $this->get($table, $ids, true);
      }
    }
  }
  public function read($table, $ids_or_attributes, $updated = false) {
    $this->start_transaction();
    if ($this->all_numeric_keys($ids_or_attributes)) {
      $ids = $ids_or_attributes;
      if (
        (!$updated) and
        (!array_diff($ids, 
          (isset($this->new_rows[$table]) ? array_keys($this->new_rows[$table]) : array()),
          (isset($this->old_rows[$table]) ? array_keys($this->old_rows[$table]) : array())
        ))
      ) {
        $results = array();
        foreach ($ids as $id) {
          if (isset($this->new_rows[$table][$id])) {
            $results[$id] = $this->new_rows[$table][$id];
          } else {
            $results[$id] = $this->old_rows[$table][$id];
          }
        }
        return $results;
      }
    }
    $query =
      'select * from ' . $this->word($table) . ' ' .
      'where ' . $this->get_clause_from_attributes($table, $ids_or_attributes);
    $result = $this->query_($query);
    $results = array();
    while ($row = $result->fetch_assoc()) {
      $audit_row = $row;
      foreach ($row as $column => &$value) {
        if ($value) {
          if (strpos($column, 'compressed') !== false) {
            $value = gzuncompress(substr($value, 4));
            unset($audit_row[$column]);
          }
          if (strpos($column, 'json') !== false) {
            $value = json_decode($value, true);
            unset($audit_row[$column]);
          }
        }
      }
      if (
        ($updated) or
        (isset($this->old_rows[$table][$audit_row[$table . '_id']]))
      ) {
        $this->new_rows[$table][$audit_row[$table . '_id']] = $audit_row;
      } else {
        $this->old_rows[$table][$audit_row[$table . '_id']] = $audit_row;
      }
      $results[$row[$table . '_id']] = $row;
    }
    return $results;
  }
  public function update($table, $attributes, $where_clause = array()) {
    $this->start_transaction();
    if (!$attributes) {
      return array();
    }
    if ($all_numeric_keys = $this->all_numeric_keys($attributes)) {
      $updates = $attributes;
    } else {
      $updates = array($attributes);
    }
    $ids = array();
    foreach ($updates as &$update) {
      $ids[] = $update[$table . '_id'];
    }
    $this->read($table, $ids);
    foreach ($updates as &$update) {
      $id = $update[$table . '_id'];
      unset($update[$table . '_id']);
      if ($update) {
        $clauses = array();
        $query = 'update ' . $this->word($table) . ' set ' . implode(',',
          array_map(
            array(
              $this,
              'column_equals_value'
            ),
            array_keys($update),
            array_values($update)
          )
        ) . ' where ' . $this->get_clause_from_attributes($table, array(
          $table . '_id' => $id,
        ) + $where_clause);
        $this->query_($query);
      }
    }
    if ($this->readback_disabled) {
      if ($all_numeric_keys) {
        return $ids;
      } else {
        foreach ($ids as $id) {
          return $id;
        }
      }
    } else {
      if ($all_numeric_keys) {
        $indexed_results = $this->read($table, $ids, true);
        $ordered_results = array();
        foreach ($ids as $id) {
          $ordered_results[] = $indexed_results[$id];
        }
        return $ordered_results;
      } else {
        return $this->get($table, $ids, true);
      }
    }
  }
  public function get($table, $id_or_attributes, $updated = false) {
    if (is_numeric($id_or_attributes)) {
      $attributes = array($id_or_attributes);
    } else {
      $attributes = $id_or_attributes;
    }
    foreach ($this->read($table, $attributes, $updated) as $result) {
      return $result;
    }
  }

}
