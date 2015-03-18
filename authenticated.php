<?php

class authenticated extends api {
  protected $session;
  public function __construct() {
    parent::__construct();
    if (!($this->session = $this->api('user', 'resume'))) {
      throw new Exception('unauthenticated');
    }
    if (configuration::$database_root_database) {
      $this->database->select_db();
    }
  }
}
