<?php

namespace yinaf;

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
          $this->session[configuration::$database_root_column]
      );
    } else {
      return null;
    }
  }
  public function create($attributes = array()) {
    return $this->database->create(
      $this->class_name,
      (($root = $this->get_root()) ? $root : array()) + ($attributes ? $attributes : array())
    );
  }
  public function read($attributes = null, $deleted = false) {
    $results = $this->database->read(
      $this->class_name,
      $attributes,
      ($this->dictionary ? null : $this->get_root())
    );
    if (!$deleted) {
      foreach ($results as $id => &$row) {
        if ($row['deleted']) {
          unset($results[$id]);
        }
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
  public function get($id_or_attributes = null) {
    return $this->database->get(
      $this->class_name,
      $id_or_attributes,
      ($this->dictionary ? null : $this->get_root())
    );
  }
}
