<?php

namespace yinaf;

class request_json extends request {
  
  public function __construct($request) {
    if (
      isset($request['class']) and
      isset($request['function']) and
      isset($request['arguments'])
    ) {
      parent::__construct($request);
      error_reporting(0);
      ob_start('ob_gzhandler');
      header('Content-Type: application/json');
      set_error_handler(array($this, 'error_handler'));
      register_shutdown_function(array($this, 'shutdown_function'));
      try {
        $result = json_encode(array(
          'success' => true,
          'result' => $this->handle(
            $request['class'], 
            $request['function'], 
            json_decode($request['arguments'], true)
          ),
        )+ (configuration::$debug ?
          array(
            'queries' => $this->database->get_queries(),
            'calls' => api::get_calls(),
          ) : 
          array()
        ));
        api::commit_transaction();
        die($result);
      } catch (Exception $e) {
        die(json_encode(array(
          'success' => false,
          'result' => array(
            'message' => $e->getMessage(),
            'code' => $e->getCode(),
            'file' => $e->getFile(),
            'line' => $e->getLine(),
          ) + (configuration::$debug ? array(
            'trace' => $this->clean_trace($e->getTrace()),
          ) : array()),
        )));
      }
    }
  }
  
  private function clean_trace($trace) {
    if (is_resource($trace)) {
      return (string)$trace . ' ('.get_resource_type($trace).')';
    } else if (is_array($trace)) {
      foreach ($trace as $key => &$value) {
        $trace[$key] = $this->clean_trace($value);
      }
    }
    return $trace;
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
