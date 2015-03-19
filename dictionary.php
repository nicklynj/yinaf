<?php

class dictionary extends authenticated {
  public function read($attributes) {
    $results = $this->database->read(
      $this->class_name,
      $attributes
    );
    foreach ($results as $id => &$row) {
      if ($row['deleted']) {
        unset($results[$id]);
      }
    }
    return $results;
  }
  public function get($id_or_attributes) {
    return $this->database->get(
      $this->class_name,
      $id_or_attributes
    );
  }
}
