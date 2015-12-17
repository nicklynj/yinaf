<?php

namespace yinaf;

class request extends api {
  
  protected $request;
  private static $last_request;
  
  public function __construct($request) {
    $this->request = $request;
    self::$last_request = $this;
  }
  
  public static function get_request() {
    return self::$last_request;
  }
  
  public function handle($class, $function, $arguments) {
    try {
      parent::__construct();
      return $this->api($class, $function, $arguments);
    } catch (\Exception $e) {
      $this->error($e);
    }
  }
  
  public function get_requested($key) {
    return isset($this->request[$key]) ? $this->request[$key] : null;
  }
  
  protected function error($str_exception) {
    if (isset($this->database)) {
      $this->database->rollback_transaction();
    }
    if (is_string($str_exception)) {
      throw new Exception($str_exception);
    } else {
      throw $str_exception;
    }
  }
  
}
