<?php

class user extends api {
  function __construct() {
    parent::__construct();
    $this->database->disable_escaping();
  }
  function __destruct() {
    $this->database->enable_escaping();
  }
  private function verify_credentials($username, $password) {
    return $this->database->get('user', array(
      'username' => $this->database->escape($username),
      'password' => 'sha1(concat(uuid, ' . $this->database->escape($password) . '))',
    ));
  }
  public function login($arguments) {
    if ($user = $this->verify_credentials($arguments['username'], $arguments['password'])) {
      return $this->database->insert('session', array(
        'user_id' => $user['user_id'],
        'key' => 'sha1(concat(uuid(), now(), rand()))'
      ));
    }
  }
  public function logout() {
    if ($session = $this->resume()) {
      return $this->database->update('session', array(
        'destroyed' => 1,
      ) + $session);
    }
  }
  public function resume() {
    return $this->database->get('session', array(
      'key' => $this->database->escape($_COOKIE['key']),
      'destroyed' => 0,
    ));
  }
  public function update_password($arguments) {
    if ($session = $this->resume()) {
      $user = $this->database->get('user', $session['user_id']);
      if (
        ($arguments['new_password'] === $arguments['confirm_password']) and
        ($this->verify_credentials($user['username'], $arguments['old_password']))
      ) {
        $this->database->update('user', array(
          'user_id' => $user['user_id'],
          'password' => 'sha1(concat(uuid, ' . $this->database->escape($arguments['new_password']) . '))', 
        ));
        return true;
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
      $this->database->enable_escaping();
      $this->database->update('user', array(
        'user_id' => $session['user_id'],
      )) + $attributes;
      $this->database->disable_escaping();
      return true;
    }
  }
}
