<?php

class request extends api {
  
  function __construct() {
    
    error_reporting(0);

    ob_start('ob_gzhandler');

    header('Content-Type: application/json');

    set_error_handler(array($this, 'error_handler'));
    
    set_exception_handler(array($this, 'exception_handler'));

    register_shutdown_function(array($this, 'shutdown_function'));
    
    parent::__construct();
    
    try {
      
      if ($_POST['class'] == 'database') {
        throw new Exception('database not allowed');
      }
      
      die(json_encode(array(
        'success' => true,
        'error' => null,
        'result' => $this->call(
          $_POST['class'],
          $_POST['function'],
          json_decode($_POST['arguments'], true)
        ),
      )));
    } catch (Exception $e) {
      $this->exception_handler($e);
    }
  }
  
  private function error($str) {
    $this->database->disable_commits();
    http_response_code(200);
    die(json_encode(array(
      'success' => false,
      'error' => htmlspecialchars($str),
      'result' => null,
    )));
  }
  
  public function error_handler($errno, $errstr, $errfile, $errline) {
    $this->error($errstr . ' in ' . $errfile . ' on line ' . $errline);
  }
  
  public function exception_handler($exception) {
    $this->error($exception->getMessage());
  }
  
  public function shutdown_function() {
    if ($error = error_get_last()) {
      $this->error($error['message'] . ' in ' . $error['file'] . ' on line ' . $error['line']);
    }
  }
  
}
