<?php

class request_json extends request {
  
  public function __construct($request) {
    parent::__construct($request);
    error_reporting(0);
    ob_start('ob_gzhandler');
    header('Content-Type: application/json');
    set_error_handler(array($this, 'error_handler'));
    register_shutdown_function(array($this, 'shutdown_function'));
    try {
      die(json_encode(array(
        'success' => true,
        'result' => $this->handle(
          $request['class'], 
          $request['function'], 
          json_decode($request['arguments'], true)
        ),
      )));
    } catch (Exception $e) {
      die(json_encode(array(
        'success' => false,
        'result' => array(
          'message' => $e->getMessage(),
          'code' => $e->getCode(),
          'file' => $e->getFile(),
          'line' => $e->getLine(),
        ),
      )));
    }
  }
  
  private function error($str_exception) {
    if (isset($this->database)) {
      $this->database->disable_commits();
    }
    if (is_string($str_exception)) {
      throw new Exception($str_exception);
    } else {
      throw $str_exception;
    }
  }
  
  public function error_handler($errno, $errstr, $errfile, $errline) {
    $this->error($errstr . ' in ' . $errfile . ' on line ' . $errline);
  }
  
  public function shutdown_function() {
    if ($error = error_get_last()) {
      die(json_encode(array(
        'success' => false,
        'result' => array(
          'message' => $error['message'],
          'code' => $error['type'],
          'file' => $error['file'],
          'line' => $error['line'],
        ),
      )));
    }
  }
  
}
