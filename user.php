<?php

namespace yinaf;

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
          ) + array_intersect_key($arguments, array_flip(configuration::$user_additional_columns)));
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
    $request = request::get_request();
    if (configuration::$user_use_requested_session_key) {
      if (
        ($session = $this->database->get('session', array(
          'key' => $request->get_requested('key'),
          'deleted' => 0,
        ))) and
        ($this->database->timestampdiff($session['created_at']) < configuration::$session_max_age) and
        (($age = $this->database->timestampdiff(max($session['used_at'], $session['created_at']))) < configuration::$session_expires)
      ) {
        if ($age > 3600) {
          $session = $this->database->update('session', array(
            'used_at' => $this->database->now(),
            'used_by' => $this->inet_aton($_SERVER['REMOTE_ADDR']),
          ) + $session);
        }
        return $session;
      }  
    } else if (configuration::$user_use_requested_user_id_and_client_id) {
      return array(
        'user_id' => $request->get_requested('user_id'),
        'client_id' => $request->get_requested('client_id'),
      );
    }
  }
  public function resume() {
    if (
      (!isset(self::$session)) and
      ($session = $this->get_session())
    ) {
      if (
        (configuration::$database_root_user) or
        (configuration::$database_user_client) and
        (
          (
            (!($client_id = request::get_request()->get_requested('client_id')))
          ) or
          (
            ($user = $this->database->get('user', $session['user_id'])) and
            (in_array($client_id, $user['client_ids'])) and
            ($this->database->select_db(
              configuration::$database_client_prefix . '_' . $client_id
            ))
          )
        )
      ) {
        self::$session = $session;
      }
    }
    return self::$session;
  }
  public function get() {
    if (
      ($session = $this->resume()) and
      ($this->database->select_db(configuration::$database_name))
    ) {
      return array_diff_key(
        $this->database->get('user', $session['user_id']), 
        array_flip(array('uuid', 'password'))
      );
    }
  }
  public function update_password($arguments) {
    if (
      ($session = $this->resume()) and
      ($this->database->select_db(configuration::$database_name))
    ) {
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
    if (
      (
        configuration::$user_anonymous_create or 
        $this->resume()
      ) and
      ($this->database->select_db(configuration::$database_name)) and
      ($user = $this->database->create('user', array(
        'uuid' => $uuid = $this->database->uuid(),
        'username' => $arguments['username'],
        'password' => hash('sha512', $uuid . $arguments['password']), 
      ) + array_intersect_key($arguments, array_flip(configuration::$user_additional_columns))))
    ) {
      if (configuration::$user_login_after_create) {
        return $this->login($arguments);
      } else {
        return $user;
      }
    }
  }
  public function update($attributes) {
    if (
      (configuration::$user_updatable) and
      ($session = $this->resume()) and
      ($this->database->select_db(configuration::$database_name))
    ) {
      $attributes = array_diff_key($attributes, array_flip(configuration::$user_updatable_columns_black_list));
      if (configuration::$user_updatable_columns_white_list) {
        $attributes = array_intersect_key($attributes, array_flip(configuration::$user_updatable_columns_white_list));
      }
      return $this->database->update('user', array(
        'user_id' => $session['user_id'],
      ) + $attributes);
    }
  }
  public function update_client_id($client_id) {
    if (
      (configuration::$database_user_client) and
      ($session = $this->resume()) and
      ($this->database->select_db(configuration::$database_name)) and
      ($user = $this->database->get('user', $session['user_id'])) and
      (in_array($client_id, $user['client_ids']))
    ) {
      return $this->database->update('session', array(
        'client_id' => $client_id,
      ) + $session);
    }
  }
}
