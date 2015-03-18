<?php

class database extends mysqli {

  private $number_of_queries = 0;
  private $query_time = 0;
  private $transaction_started = false;
  private $escaping_disabled = false;
  private $commits_disabled = false;
  private $auditing_readback_disabled = false;
  private $old_rows = array();
  private $new_rows = array();
  private function word($str) {
    if (preg_match('/^[\d\w]+$/', $str)) {
      return '`' . $str . '`';
    } else {
      throw new Exception('invalid word');
    }
  }
  private function escape_if($str) {
    if ($this->escaping_disabled) {
      return $str;
    } else {
      return $this->escape($str);
    }
  }
  private function column_equals_value($column, $value) {
    return $this->word($column) . '=' . $this->escape_if($value);
  }
  private function start_transaction() {
    if (!$this->transaction_started) {
      $query = 'start transaction';
      $this->query_($query);
      $this->transaction_started = true;
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
        $this->disable_auditing_readback();
        new audit($this->old_rows, $this->new_rows);
        $this->enable_auditing_readback();
      }
      $this->old_rows = array();
      $this->new_rows = array();
      $query = 'commit';
      $this->query_($query);
      $this->transaction_started = false;
    }
  }
  private function query_($str) { var_dump($str);
    ++$this->number_of_queries;
    $this->query_time -= microtime(true);
    $result = parent::query($str);
    $this->query_time += microtime(true);
    if ($result) {
      return $result;
    } else {
      throw new Exception($this->error . ':' . $str);
    }
  }
  private function all_numeric_keys($attributes) {
    return (!(in_array(false, array_map('is_int', array_keys($attributes)), true)));
  }
  
  function __destruct() {
    $this->commit_transaction();
  }
  
  public function disable_escaping() {
    $this->escaping_disabled = true;
  }
  public function enable_escaping() {
    $this->escaping_disabled = false;
  }
  public function disable_commits() {
    $this->commits_disabled = true;
  }
  public function disable_auditing_readback() {
    $this->auditing_readback_disabled = true;
  }
  public function enable_auditing_readback() {
    $this->auditing_readback_disabled = false;
  }
  
  public function get_number_of_queries() {
    return $this->number_of_queries;
  }
  public function get_query_time() {
    return $this->query_time;
  }
  
  public function uuid() {
    $result = $this->query_('select uuid()');
    return $result['uuid()'];
  }
  
  public function escape($str) {
    if ($str === null) {
      return 'null';
    } else if (is_int($str)) {
      return $str;
    } else {
      return '"' . $this->real_escape_string($str) . '"';
    }
  }

  public function query($str) {
    throw new Exception('query disabled');
  }

  public function commit() {
    $this->commit_transaction();
  }
  
  public function create($table, $attributes) {
    $this->start_transaction();
    if ($all_numeric_keys = $this->all_numeric_keys($attributes)) {
      $inserts = $attributes;
    } else {
      $inserts = array($attributes);
    }
    $ids = array();
    foreach ($inserts as &$insert) {
      $query = 
        'insert into ' . $this->word($table) . ' ' . 
        ' (' . implode(',', array_map(array($this, 'word'), array_keys($insert))) . ') ' .
        'values ' .
        '(' . implode(',', array_map(array($this, 'escape_if'), $insert)) . ')';
      $this->query_($query);
      $ids[] = $this->insert_id;
    }
    if ($this->auditing_readback_disabled) {
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
        return $this->get($table, $ids);
      }
    }
  }
  public function read($table, $ids_or_attributes, $created = false) {
    $this->start_transaction();
    if ($this->all_numeric_keys($ids_or_attributes)) {
      $ids = $ids_or_attributes;
      if (!(
        array_diff($ids, 
          (isset($this->new_rows[$table]) ? array_keys($this->new_rows[$table]) : array()),
          (isset($this->old_rows[$table]) ? array_keys($this->old_rows[$table]) : array())
        )
      )) {
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
      $attributes = array(
        $table . '_id' => $ids,
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
              array_map(array($this, 'escape_if'), $value)
            ) . ')';
        } else {
          return array();
        }
      } else if ($value === null) {
        $clause .= ' is null';
      } else {
        $clause .= '=' . $this->escape_if($value);
      }
      $clauses[] = $clause;
    }
    $query =
      'select * from ' . $this->word($table) . ' ' .
      'where (' . implode(') and (', $clauses) . ')';
    $result = $this->query_($query);
    $results = array();
    while ($row = $result->fetch_assoc()) {
      $results[$row[$table . '_id']] = $row;
      if (!($this->auditing_readback_disabled)) {
        if (
          ($created) or
          (isset($this->old_rows[$table][$row[$table . '_id']]))
        ) {
          $this->new_rows[$table][$row[$table . '_id']] = $row;
        } else {
          $this->old_rows[$table][$row[$table . '_id']] = $row;
        }
      }
    }
    return $results;
  }
  public function update($table, $attributes) {
    $this->start_transaction();
    if ($all_numeric_keys = $this->all_numeric_keys($attributes)) {
      $updates = $attributes;
    } else {
      $updates = array($attributes);
    }
    $ids = array();
    foreach ($updates as &$update) {
      $ids[] = $update[$table . '_id'];
    }
    if (!($this->auditing_readback_disabled)) {
      $this->read($table, $ids);
    }
    foreach ($updates as &$update) {
      $id = $update[$table . '_id'];
      unset($update[$table . '_id']);
      if ($update) {
        $query = 'update ' . $this->word($table) . ' set ' . implode(',',
          array_map(
            array(
              $this,
              'column_equals_value'
            ),
            array_keys($update),
            array_values($update)
          )
        ) . ' where ' . $this->word($table . '_id') . ' = ' . intval($id) . '';
        $this->query_($query);
      }
    }
    if ($this->auditing_readback_disabled) {
      if ($all_numeric_keys) {
        return $ids;
      } else {
        foreach ($ids as $id) {
          return $id;
        }
      }
    } else {
      if ($all_numeric_keys) {
        $indexed_results = $this->read($table, $ids);
        $ordered_results = array();
        foreach ($ids as $id) {
          $ordered_results[] = $indexed_results[$id];
        }
        return $ordered_results;
      } else {
        return $this->get($table, $ids);
      }
    }
  }
  public function get($table, $id_or_attributes) {
    if (is_numeric($id_or_attributes)) {
      $attributes = array($id_or_attributes);
    } else {
      $attributes = $id_or_attributes;
    }
    foreach ($this->read($table, $attributes) as $result) {
      return $result;
    }
  }

}
