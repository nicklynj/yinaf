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
      parent::query('start transaction');
      $this->transaction_started = true;
    }
  }
  private function all_numeric_keys($attributes) {
    return ($attributes) and 
      (!(in_array(false, array_map('is_int', array_keys($attributes)), true)));
  }
  private function get_clause_from_attributes_array($table, $attributes_array) {
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
      foreach ($attributes as $key => &$value) {
        $clause = $this->word($key);
        if (is_array($value)) {
          if ($value) {
            $clause .= ' in (' . implode(',',
                array_map(array($this, 'escape'), $value)
              ) . ')';
            if (in_array(null, $value, true)) {
              $clause = '('.$clause.' or '.$this->word($key).' is null)';
            }
          } else {
            return null;
          }
        } else if ($value === null) {
          $clause .= ' is null';
        } else {
          $clause .= '=' . $this->escape($value);
        }
        $clauses[] = $clause;
      }
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
  private function escape_attributes($attributes) {
    return '(' . implode(',', array_map(array($this, 'escape'), array_values($attributes), array_keys($attributes))) . ')';
  }
  private function read_($table, $attributes_array, $created) {
    if (!($where_clause = $this->get_clause_from_attributes_array($table, $attributes_array))) {
      return array();
    }
    $result = $this->query('select * from ' . $this->word($table) . ' where ' . $where_clause);
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
        ($created) or
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
  
  public function __destruct() {
    $this->commit();
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
    $this->readback_disabled = false;
  }
  
  public function uuid() {
    $row = $this->query('select uuid()')->fetch_assoc();
    return $row['uuid()'];
  }
  public function now() {
    $row = $this->query('select now()')->fetch_assoc();
    return $row['now()'];
  }
  public function timestampdiff($from) {
    $row = $this->query('select timestampdiff(second, '.$this->escape($from).', now()) diff')->fetch_assoc();
    return $row['diff'];
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

  public function commit() {
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
      $this->query('commit');
      $this->transaction_started = false;
      return true;
    } else {
      return false;
    }
  }
 
  public function read($table/* , $var_args */) {
    return $this->read_($table, array_slice(func_get_args(), 1), false);
  }
  public function get($table/* , $var_args */) {
    foreach ($this->read_($table, array_slice(func_get_args(), 1), false) as $result) {
      return $result;
    }
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
    $ids = array();
    foreach ($attributes_array_indexed as $index => &$attributes_array_index) {
      $this->query('insert into ' . $this->word($table) . ' ' . 
        ' (' . implode(',', array_map(array($this, 'word'), array_keys($attributes_array_index[0]))) . ') ' .
          'values ' . implode(',', array_map(array($this, 'escape_attributes'), $attributes_array_index)));
      $last_insert_id = $this->insert_id;
      for ($i = 0, $len = count($attributes_array_index); $i < $len; ++$i) {
        $ids[] = $last_insert_id - configuration::$database_auto_increment_increment * $i;
      }
    }
    if ($this->readback_disabled) {
      if ($all_numeric_keys) {
        return $ids;
      } else {
        return $ids[0];
      }
    } else {
      $rows = $this->read_($table, array($ids), true);
      if ($all_numeric_keys) {
        return array_values($rows);
      } else {
        foreach ($rows as &$row) {
          return $row;
        }
      }
    }
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
    $ids = array();
    foreach ($attributes_array as &$attributes) {
      $ids[] = $attributes[$table . '_id'];
    }
    if (!$this->readback_disabled) {
      $this->read_($table, array($ids), false);
    } 
    foreach ($attributes_array as &$attributes) {
      $id = $attributes[$table . '_id'];
      unset($attributes[$table . '_id']);
      if ($attributes) {
        /* if (
          (isset($this->new_rows[$table])) and
          (isset($this->new_rows[$table][$id]))
        ) {
          $row = $this->new_rows[$table][$id];
        } else if (
          (isset($this->old_rows[$table])) and
          (isset($this->old_rows[$table][$id]))
        ) {
          $row = $this->old_rows[$table][$id];
        } else {
          $row = null;
        }
        if ($row) {
          $mismatch_found = false;
          foreach ($attributes as $key => &$value) {
            if (
              (strval($value) !== $row[$key]) or
              (!(($value === null) and ($row[$key] === null)))
            ) {
              $mismatch_found = true;
              break;
            }
          }
          if (!$mismatch_found) {
            continue;
          }
        } */
        $this->query('update ' . $this->word($table) . ' set ' . implode(',',
          array_map(
            array(
              $this,
              'column_equals_value'
            ),
            array_keys($attributes),
            array_values($attributes)
          )
        ) . ' where ' . $this->get_clause_from_attributes_array($table, array_merge(
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
    }
    if ($this->readback_disabled) {
      if ($all_numeric_keys) {
        return $ids;
      } else {
        return $ids[0];
      }
    } else {
      $indexed_rows = $this->read_($table, array($ids), false);
      if ($all_numeric_keys) {
        $ordered_rows = array();
        foreach ($ids as &$id) {
          $ordered_rows[] = $indexed_rows[$id];
        }
        return $ordered_rows;
      } else {
        foreach ($indexed_rows as &$row) {
          return $row;
        }
      }
    }
  }

}
