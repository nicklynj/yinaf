<?php

namespace yinaf;

class authenticated extends api {
  
  protected $session;
  
  private static $authenticated_session;
  
  public function __construct() {
    parent::__construct();
    if (!isset(self::$authenticated_session)) {
      self::$authenticated_session = $this->api('user', 'resume');
    }
    if (!($this->session = self::$authenticated_session)) {
      throw new Exception('unauthenticated');
    }
  }
  
}
