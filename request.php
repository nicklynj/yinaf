<?php

class request extends api {
  
  protected $request;
  
  public function __construct($request) {
    $this->request = $request;
  }
  
  public function handle($class, $function, $arguments) {
    try {
      parent::__construct();
      return $this->api($class, $function, $arguments);
    } catch (Exception $e) {
      $this->error($e);
    }
  }
  
  protected function error($str_exception) {
    if (isset($this->database)) {
      $this->database->disable_commits();
    }
    if (is_string($str_exception)) {
      throw new Exception($str_exception);
    } else {
      throw $str_exception;
    }
  }
  
}
