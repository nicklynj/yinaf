<?php

namespace yinaf;

class api {
  private static $database_instance;
  protected $database;
  protected $class_name;
  private static $calls = array();
  private static $call_stack = array();
  public function __construct() {
    if (!isset(self::$database_instance)) {
      self::$database_instance = new database(
        configuration::$database_host,
        configuration::$database_username,
        configuration::$database_password,
        configuration::$database_name
      );
    }
    $this->class_name = strpos(get_class($this), '\\') ? 
      substr(get_class($this), strrpos(get_class($this), '\\') + 1) : 
      get_class($this);
    $this->database = self::$database_instance;
  }
  public function get_class_name() {
    return $this->class_name;
  }
  public static function get_calls() {
    return self::$calls;
  }
  public static function commit_transaction() {
    if (isset(self::$database_instance)) {
      if (configuration::$debug) {
        $start = microtime(true);
        $call = array(
          'class' => 'api',
          'function' => 'commit_transaction',
        );
        self::$calls[] = &$call;
      }
      self::$database_instance->commit_transaction();
      if (configuration::$debug) {
        $call += array(
          'total_time' => round(microtime(true) - $start, 4),
          'own_time' => 0,
        );
      }
    }
  }
  protected function api($class, $function/* , $var_args */) {
    if (configuration::$debug) {
      $start = microtime(true);
      $call = array(
        'class' => $class,
        'function' => $function,
      );
      if ($len = count(self::$call_stack)) {
        self::$call_stack[$len - 1]['calls'][] = &$call;
      } else {
        self::$calls[] = &$call;
      }
      self::$call_stack[] = &$call;
      $queries = count($this->database->get_queries());
    }
    $result = call_user_func_array(
      array($this->api_get_object($class), $function),
      array_slice(func_get_args(), 2)
    );
    if (configuration::$debug) {
      array_pop(self::$call_stack);
      $time = microtime(true) - $start;
      $call += array(
        'time' => round($time, 4),
        'own_time' => round($time - $this->get_time($call), 4),
        'queries' => array_slice($this->database->get_queries(), $queries),
        'own_queries' => array_slice($this->database->get_queries(), $queries + $this->get_queries($call)),
      );
    }
    return $result;
  }
  private function get_queries(&$call) {
    $queries = 0;
    for ($i = 0; isset($call['calls'][$i]); ++$i) {
      $queries += count($call['calls'][$i]['queries']) + $this->get_time($call['calls'][$i]);
    }
    return $queries;
  }
  private function get_time(&$call) {
    $time = 0;
    for ($i = 0; isset($call['calls'][$i]); ++$i) {
      $time += $call['calls'][$i]['own_time'] + $this->get_time($call['calls'][$i]);
    }
    return $time;
  }
  private function api_get_object($class) {
    $namespaces = array();
    if (strpos($class, '\\') !== false) {
      $namespaces[] = '';
    }
    $class_name = explode('\\', get_class($this));
    while ($class_name) {
      array_pop($class_name);
      if ($class_name) {
        $namespaces[] = implode('\\', $class_name);
      }
    }
    $namespaces[] = configuration::$php_include_path;
    $namespaces[] = 'yinaf';
    foreach ($namespaces as $namespace) {
      if (class_exists($namespaced_class = $namespace . '\\' . $class)) {
        return new $namespaced_class();
      }
    }
    return new crud($class);
  }
  public function multiple($arguments) {
    $results = array();
    foreach ($arguments as $argument) {
      $results[isset($argument['alias']) ? $argument['alias'] : $argument['class']] =
        call_user_func_array(array($this, 'api'),
          array_merge(
            array($argument['class'], $argument['function']), 
            array_key_exists('arguments', $argument) ? array($this->replacer($argument['arguments'], $results)) : array()
          )
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
