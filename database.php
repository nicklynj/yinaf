<?php

class database extends mysqli {

  private $number_of_queries = 0;
  private $query_time = 0;
  private $transactions_disabled = false;
  private $transaction_started = false;
  private $escape_values = true;
  private $commits_disabled = false;
  private function word($str) {
    if (preg_match('/^[\d\w]+$/', $str)) {
      return '`' . $str . '`';
    } else {
      throw new Exception('invalid word');
    }
  }
  private function escape_if($str) {
    if ($this->escape_values) {
      return $this->escape($str);
    } else {
      return $str;
    }
  }
  private function column_equals_value($column, $value) {
    return $this->word($column) . '=' . $this->escape_if($value);
  }
  private function start_transaction() {
    if (
      (!$this->transaction_started) and
      (!$this->transactions_disabled)
    ) {
      $query = 'start transaction';
      $this->query($query);
      $this->transaction_started = true;
    }
  }
  private function commit_transaction() {
    if ($this->transaction_started) {
      $query = 'commit';
      $this->query($query);
    }
  }
  
  function __destruct() {
    if (
      ($this->transaction_started) and
      (!$this->commits_disabled)
    ) {
      $this->commit_transaction();
    }
  }
  
  public function disable_escaping() {
    $this->escape_values = false;
  }
  public function enable_escaping() {
    $this->escape_values = true;
  }
  public function disable_transactions() {
    if (!$this->transaction_started) {
      $this->transactions_disabled = true;
    }
  }
  public function disable_commits() {
    $this->commits_disabled = true;
  }
  
  public function get_number_of_queries() {
    return $this->number_of_queries;
  }
  public function get_query_time() {
    return $this->query_time;
  }
  
  public function uuid() {
    $result = $this->query('select uuid()');
    return $result['uuid()'];
  }
  
  public function escape($str) {
    if ($str === null) {
      return 'null';
    } else {
      return '"' . $this->real_escape_string($str) . '"';
    }
  }

  public function query($str) {
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
  
  public function select($table, $ids_or_attributes) {
    $clauses = array();
    if ($numeric_keys = array_flip(array_filter(array_keys($ids_or_attributes), 'is_int'))) {
      $ids_or_attributes[$table . '_id'] = array_intersect_key($ids_or_attributes, $numeric_keys);
      $attributes = array_diff_key($ids_or_attributes, $numeric_keys);
    }
    if (in_array(false, array_map('is_int', array_keys($ids_or_attributes)), true) === false) {
      $attributes = array(
        $table . '_id' => $attributes,
      );
    }
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
      } else {
        $clause .= '=' . $this->escape_if($value);
      }
      $clauses[] = $clause;
    }
    $query =
      'select * from ' . $this->word($table) . ' ' .
      'where (' . implode(') and (', $clauses) . ')';
    $result = $this->query($query);
    $results = array();
    while ($row = $result->fetch_assoc()) {
      $results[$row[$table . '_id']] = $row;
    }
    return $results;
  }
  public function get($table, $id_or_attributes) {
    if (is_numeric($id_or_attributes)) {
      $attributes = array($id_or_attributes);
    } else {
      $attributes = $id_or_attributes;
    }
    foreach ($this->select($table, $attributes) as $result) {
      return $result;
    }
  }
  public function insert($table, $attributes) {
    $this->start_transaction();
    $query = 
      'insert into ' . $this->word($table) . ' ' . 
      ' (' . implode(',', array_map(array($this, 'word'), array_keys($attributes))) . ') ' .
      'values ' .
      '(' . implode(',', array_map(array($this, 'escape_if'), $attributes)) . ')';
    $this->query($query);
    return $this->get($table, $this->insert_id);
  }
  public function update($table, $attributes) {
    $id = $attributes[$table . '_id'];
    unset($attributes[$table . '_id']);
    if ($attributes) {
      $this->start_transaction();
      $query = 'update ' . $this->word($table) . ' set ' . implode(',',
        array_map(
          array(
            $this,
            'column_equals_value'
          ),
          array_keys($attributes),
          array_values($attributes)
        )
      ) . ' where ' . $this->word($table . '_id') . ' = ' . intval($id) . '';
      $this->query($query);
    }
    return $this->get($table, $id);
  }

}
