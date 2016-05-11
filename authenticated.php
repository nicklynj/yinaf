<?php

namespace yinaf;

class authenticated extends api {
  
  protected $session;
  
  private static $authenticated_session;
  
  public function __construct() {
    parent::__construct();
    if (!(isset(self::$authenticated_session))) {
      self::$authenticated_session = $this->api('user', 'resume');
    }
    if (!($this->session = self::$authenticated_session)) {
      if (configuration::$debug) {
        throw new Exception('unauthenticated in:"'.$this->class_name.'"');
      } else {
        throw new Exception('unauthenticated');
      }
    }
  }
  
}
