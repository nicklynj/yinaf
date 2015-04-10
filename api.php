<?php

class api {
  private static $database_instance;
  protected $database;
  protected $class_name;
  private static $calls = array();
  public function __construct() {
    if (!isset(self::$database_instance)) {
      self::$database_instance = new database(
        configuration::$database_host,
        configuration::$database_username,
        configuration::$database_password,
        configuration::$database_name
      );
    }
    $this->class_name = get_class($this);
    $this->database = self::$database_instance;
  }
  public function get_class_name() {
    return $this->class_name;
  }
  public static function get_calls() {
    return self::$calls;
  }
  public static function commit() {
    if (isset(self::$database_instance)) {
      self::$database_instance->commit_transaction();
    }
  }
  protected function api($class, $function/* , $var_args */) {
    if (configuration::$debug) {
      $start = microtime(true);
      $call = array();
      self::$calls[] = &$call;
      $calls_length = count(self::$calls);
    }
    $result = call_user_func_array(
      array(class_exists($class) ? new $class() : new crud($class), $function),
      array_slice(func_get_args(), 2)
    );
    if (configuration::$debug) {
      $time = round(microtime(true) - $start, 4);
      $call += array(
        'class' => $class,
        'function' => $function,
        'total_time' => $time,
        'own_time' => isset(self::$calls[$calls_length]) ?
          $time - array_sum(array_column(array_slice(self::$calls, $calls_length), 'own_time')) :
          $time
      );
    }
    return $result;
  }
  public function multiple($arguments) {
    $results = array();
    foreach ($arguments as $argument) {
      $results[isset($argument['alias']) ? $argument['alias'] : $argument['class']] =
        $this->api(
          $argument['class'], 
          $argument['function'], 
          $this->replacer(isset($argument['arguments']) ? $argument['arguments'] : null, $results)
        );
    }
    return $results;
  }
  private function replacer($arguments, $results) {
    if (is_array($arguments)) {
      foreach ($arguments as $key => &$value) {
        if (
          (is_string($value)) and
          (substr($value, 0, 1) === '=')
        ) {
          $value = $this->get_replacement($value, $results);
        }
      }
    }
    return $arguments;
  }
  private function get_replacement($value, $results) {
    $columns = preg_split('/[\.\[]/', str_replace(']', '', substr($value, 1)));
    $replace = $results;
    for ($i = 0; isset($columns[$i]); ++$i) {
      if (strlen($columns[$i])) {
        $replace = $replace[$columns[$i]];
      } else {
        $replace = array_column($replace, $columns[++$i]);
      }
    }
    return $replace;
  }
}
