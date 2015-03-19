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
          request::get_last_request()->get_requested(configuration::$database_root_column)
      );
    } else {
      return array();
    }
  }
  public function create($attributes) {
    return $this->database->create(
      $this->class_name,
      $this->get_root() + $attributes
    );
  }
  public function read($attributes) {
    $results = $this->database->read(
      $this->class_name,
      ($this->dictionary ? array() : $this->get_root()) + $attributes
    );
    foreach ($results as $id => &$row) {
      if ($row['deleted']) {
        unset($results[$id]);
      }
    }
    return $results;
  }
  public function update($attributes) {
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
      $id_or_attributes
    );
  }
}
