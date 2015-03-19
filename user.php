<?php

class user extends api {
  public function __construct() {
    parent::__construct();
    $this->database->disable_caching();
  }
  public function __destruct() {
    $this->database->enable_caching();
  }
  private function verify_password($user, $password) {
    if ($user['password'] === hash('sha512', $user['uuid'] . $password)) {
      return $user;
    }
  }
  private function get_user($arguments) {
    if (
      $user = $this->database->get('user', array(
        'username' => $arguments['username'],
      ) + array_intersect_key($arguments, array_flip(configuration::$login_additional_columns)))
    ) {
      return $user;
    }
  }
  public function login($arguments) {
    if ($user = $this->get_user($arguments)) {
      if ($this->verify_password($user, $arguments['password'])) {
        if (
          (configuration::$user_track_failed_logins) and
          ($user['failed_logins'])
        ) {
          $user['failed_logins'] = 0;
          $this->database->update('user', $user);
        }
        if (
          (!(configuration::$user_track_failed_logins)) or
          ($user['failed_logins'] < configuration::$user_max_failed_logins)
        ) {
          return $this->database->create('session', array(
            'user_id' => $user['user_id'],
            'key' => hash('sha512', $user['uuid'] . time() . mt_rand()),
          ));
        }
      } else {
        if (configuration::$user_track_failed_logins) {
          ++$user['failed_logins'];
          $this->database->update('user', $user);
        }
      }
    }
  }
  public function logout() {
    if ($session = $this->resume()) {
      return $this->database->update('session', array(
        'destroyed' => 1,
      ) + $session);
    }
  }
  private static $memo_session;
  private function get_session() {
    if (!isset(self::$memo_session)) {
      $request = request::get_last_request();
      if ($request->get_class_name() === 'request_json') {
        if (
          ($session = $this->database->get('session', array(
            'key' => $request->get_requested('key'),
            // 'destroyed' => 0,
          ))) and
          ((time() - strtotime($session['created_at'])) < configuration::$session_max_life)
        ) {
          self::$memo_session = $session;
        }  
      } else {
        self::$memo_session = array(
          'user_id' => $request->get_user_id(),
        );
      }
    }
    return self::$memo_session;
  }
  private static $memo_resume;
  public function resume() {
    if (!isset(self::$memo_resume)) {
      if ($session = $this->get_session()) {
        if (configuration::$database_user_client) {
          $client_id = request::get_last_request()->get_requested('client_id');
          if ($this->database->get('user_client', array(
            'user_id' => $session['user_id'],
            'client_id' => $client_id,
            'deleted' => 0,
          ))) {
            $this->database->select_db(
              configuration::$database_client_prefix . '_' . $client_id
            );
            self::$memo_resume = $session;
          }
        } else {
          self::$memo_resume = $session;
        }
      }
    }
    return self::$memo_resume;
  }
  public function update_password($arguments) {
    if ($session = $this->resume()) {
      $user = $this->database->get('user', $session['user_id']);
      if ($this->verify_password($user, $arguments['old_password'])) {
        return $this->database->update('user', array(
          'user_id' => $user['user_id'],
          'password' => hash('sha512', $user['uuid'] . $arguments['new_password']), 
        ));
      }
    }
  }
  public function create($arguments) {
    $uuid = $this->database->uuid();
    if ($this->database->insert('user', array(
      'uuid' => $this->database->escape($uuid),
      'username' => $this->database->escape($arguments['username']),
      'password' => 'sha1(concat(' .
        $this->database->escape($uuid) . ', ' . 
        $this->database->escape($arguments['password']) . 
      '))',
    ))) {
      return $this->login($arguments);
    }
  }
  public function update($attributes) {
    if ($session = $this->resume()) {
      foreach (configuration::$user_updatable_columns_black_list as $column) {
        unset($attributes[$column]);
      }
      if (configuration::$user_updatable_columns_white_list) {
        $attributes = array_intersect_key($attributes, array_flip(configuration::$user_updatable_columns_white_list));
      }
      return $this->database->update('user', array(
        'user_id' => $session['user_id'],
      ) + $attributes);
    }
  }
}
