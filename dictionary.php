<?php

class dictionary extends crud {
  public function select($attributes) {
    return $this->database->select(
      $this->class,
      $attributes +
      array('deleted' => 0)
    );
  }
  public function get($id_or_attributes) {
    return $this->database->get(
      $this->class,
      $id_or_attributes
    );
  }
}
