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
  
}
