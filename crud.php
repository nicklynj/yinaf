<?php

class crud extends api {
  private $session;
  protected $class;
  public function __construct($opt_class = null) {
    parent::__construct();
    if (!($this->session = $this->call('user', 'resume'))) {
      throw new Exception('You are not logged in!');
    }
    if ($opt_class) {
      $this->class = $opt_class;
    } else {
      $this->class = get_class($this);
    }
  }
  public function select($attributes) {
    return $this->database->select(
      $this->class,
      array('user_id' => $this->session['user_id']) +
      $attributes +
      array('deleted' => 0)
    );
  }
  public function get($id_or_attributes) {
    return $this->database->get(
      $this->class,
      array('user_id' => $this->session['user_id']) +
      $id_or_attributes
    );
  }
  public function insert($attributes) {
    return $this->database->insert(
      $this->class,
      array('user_id' => $this->session['user_id']) +
      $attributes
    );
  }
  public function update($attributes) {
    $row = $this->get($attributes[$this->class . '_id']);
    if ($row['user_id'] == $this->session['user_id']) {
      return $this->database->update(
        $this->class,
        $attributes
      );
    }
  }
  public function delete($id) {
    return $this->update(array(
      $this->class . '_id' => $id,
      'deleted' => 1,
    ));
  }
}
