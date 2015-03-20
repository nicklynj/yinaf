<?php

class api {
  private static $database_instance;
  protected $database;
  protected $class_name;
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
