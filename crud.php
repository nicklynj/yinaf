<?php

class crud extends authenticated {
  protected $dictionary = false;
  public function __construct($opt_class = null) {
    parent::__construct();
    if ($opt_class) {
      $this->class_name = $opt_class;
    }
  }
  private function get_root() {
    if (configuration::$database_root_column) {
      return array(
        configuration::$database_root_column => 
          request::get_request()->get_requested(configuration::$database_root_column)
      );
    } else {
      return array();
    }
  }
  public function create($attributes = array()) {
    return $this->database->create(
      $this->class_name,
      $this->get_root() + $attributes
    );
  }
  public function read($attributes = array()) {
    $results = $this->database->read(
      $this->class_name,
      $attributes,
      ($this->dictionary ? array() : $this->get_root())
    );
    foreach ($results as $id => &$row) {
      if ($row['deleted']) {
        unset($results[$id]);
      }
    }
    return $results;
  }
  public function update($attributes = array()) {
    return $this->database->update(
      $this->class_name,
      $attributes,
      $this->get_root()
    );
  }
  public function delete($id) {
    return $this->update(array(
      $this->class_name . '_id' => $id,
      'deleted' => 1,
    ));
  }
  public function get($id_or_attributes) {
    return $this->database->get(
      $this->class_name,
      $id_or_attributes,
      ($this->dictionary ? array() : $this->get_root())
    );
  }

  public function search($str) { // [todo:remove this]
    
    $queries = array();
    
    $queries[] = 'select * from `'.$this->class_name.'` where code like "%'.$this->database->real_escape_string($str).'%"';
    $queries[] = 'select * from `'.$this->class_name.'` where name like "%'.$this->database->real_escape_string($str).'%"';
    
    $results = array();
    
    foreach ($queries as $query) {
      $result = $this->database->query($query . ' limit 100');
      while ($row = $result->fetch_assoc()) {
        $results[] = $row;
      }
      $results = array_values(array_intersect_key($results, array_unique(array_map('serialize', $results))));
      if (count($results) >= 100) {
        break;
      }
    }
    
    array_multisort(
      array_column($results, 'code'),
      SORT_ASC,
      array_column($results, 'name'),
      SORT_ASC,
      $results
    );
    
    return $results;
    
  }
  
}
