<?php

namespace yinaf;

abstract class configuration {
  
  public static $debug = false;
  
  public static $php_include_path = 'php';
  
  public static $database_username = 'user';
  public static $database_password = 'password';
  public static $database_host = 'example.com';
  public static $database_name = 'example';
  public static $database_auditing = false;
  
  public static $database_audit_class = null;
  public static $database_audit_function = null;
  
  public static $database_auto_increment_increment = 1;
  
  public static $database_root_user = true;
  public static $database_root_column = 'user_id';
  public static $database_user_client = false;
  public static $database_client_prefix = null;
  
  public static $user_additional_columns = array();
  
  public static $session_max_age = 7776000;
  public static $session_expires = 7776000;
    
  public static $user_updatable = true;
  public static $user_updatable_columns_white_list = array();
  public static $user_updatable_columns_black_list = array();
  public static $user_track_failed_logins = false;
  public static $user_max_failed_logins = null;
  public static $user_use_requested_session_key = true;
  public static $user_use_requested_user_id = false;
  public static $user_login_after_create = true;
  public static $user_anonymous_create = true;

}
