<?php

class api {
  private static $database_instance;
  protected $database;
  public function __construct() {
    if (!isset(self::$database_instance)) {
      self::$database_instance = new database(
        '10.10.0.22',
        'super',
        'password',
        'test'
      );
    }
    $this->database = self::$database_instance;
  }
  public static function commit() {
    if (isset(self::$database_instance)) {
      self::$database_instance->commit();
    }
  }
  protected function api($class, $function/* , $var_args */) {
    return call_user_func_array(
      array(class_exists($class) ? new $class() : new crud($class), $function),
      array_slice(func_get_args(), 2)
    );
  }
  public function multiple($arguments) {
    $results = array();
    foreach ($arguments as $argument) {
      $results[isset($argument['alias']) ? $argument['alias'] : $argument['class']] =
        $this->api(
          $argument['class'], 
          $argument['function'], 
          $this->replacer($argument['arguments'], $results)
        );
    }
    return $results;
  }
  private function replacer($arguments, $results) {
    if (is_array($arguments)) {
      foreach ($arguments as $key => $value) {
        if (substr($value, 0, 1) === '=') {
          $arguments[$this->get_replacement($key, $results)] =
            $this->get_replacement($key, $results);
        }
      }
    }
    return $arguments;
  }
  private function get_replacement($str, $results) {
    $columns = preg_split('/[\.\[]/', str_replace(']', '', substr($str, 1)));
    $replace = $results;
    for ($i = 0; isset($columns[$i]); ++$i) {
      if ($columns[$i]) {
        $replace = $replace[$columns[$i]];
      } else {
        $replace = array_column($replace, $columns[++$i]);
      }
    }
    return $replace;
  }
}
