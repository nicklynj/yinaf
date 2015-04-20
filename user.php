<?php

class user extends api {
  private static $session;
  private function verify_password($user, $password) {
    if ($user['password'] === hash('sha512', $user['uuid'] . $password)) {
      return $user;
    }
  }
  private function get_user($arguments) {
    if (
      $user = $this->database->get('user', array(
        'username' => $arguments['username'],
        'deleted' => 0,
      ) + array_intersect_key($arguments, array_flip(configuration::$user_additional_columns)))
    ) {
      return $user;
    }
  }
  private function inet_aton($ip_address) {
    $parts = explode('.', $ip_address, 4);
    return isset($parts[3]) ?
      (16777216 * $parts[0] +
      65536 * $parts[1] +
      256 * $parts[2] +
      1 * $parts[3]) :
      0;
  }
  public function login($arguments) {
    if ($user = $this->get_user($arguments)) {
      if ($this->verify_password($user, $arguments['password'])) {
        if (
          (!(configuration::$user_track_failed_logins)) or
          ($user['failed_logins'] < configuration::$user_max_failed_logins) and
          ($this->database->update('user', array('failed_logins' => 0) + $user))
        ) {
          return self::$session = $this->database->create('session', array(
            'user_id' => $user['user_id'],
            'created_by' => $this->inet_aton($_SERVER['REMOTE_ADDR']),
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
        'deleted' => 1,
      ) + $session);
    }
  }
  private function get_session() {
    if (!isset(self::$session)) {
      $request = request::get_last_request();
      if ($request->get_class_name() === 'request_json') {
        if (
          ($session = $this->database->get('session', array(
            'key' => $request->get_requested('key'),
            'deleted' => 0,
          ))) and
          ($this->database->timestampdiff($session['created_at']) < configuration::$session_max_age) and
          ($this->database->timestampdiff(max($session['used_at'], $session['created_at'])) < configuration::$session_expires)
        ) {
          return $this->database->update('session', array(
            'used_at' => $this->database->now(),
            'used_by' => $this->inet_aton($_SERVER['REMOTE_ADDR']),
          ) + $session);
        }  
      } else {
        return array(
          'user_id' => $request->get_requested('user_id'),
        );
      }
    }
  }
  public function resume() {
    if (!isset(self::$session)) {
      if ($session = $this->get_session()) {
        if (configuration::$database_root_user) {
          if ($session['user_id'] == request::get_last_request()->get_requested('user_id')) {
            self::$session = $session;
          }
        } else if (configuration::$database_user_client) {
          $client_id = request::get_last_request()->get_requested('client_id');
          if ($this->database->get('user_client', array(
            'user_id' => $session['user_id'],
            'client_id' => $client_id,
            'deleted' => 0,
          ))) {
            $this->database->select_db(
              configuration::$database_client_prefix . '_' . $client_id
            );
            self::$session = $session;
          }
        } else {
          self::$session = $session;
        }
      }
    }
    return self::$session;
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
    if ($this->database->create('user', array(
      'uuid' => $uuid,
      'username' => $arguments['username'],
      'password' => hash('sha512', $uuid . $arguments['password']), 
    ) + array_intersect_key($arguments, array_flip(configuration::$user_additional_columns)))) {
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
