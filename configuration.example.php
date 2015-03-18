<?php

abstract class configuration {
  
  public static $database_username = 'user';
  public static $database_password = 'password';
  public static $database_host = 'example.com';
  public static $database_name = 'example';
  
  public static $database_root_column = 'user';
  public static $database_root_database = false;
  
  public static $additional_login_columns = array('client_id');
  
  public static $session_max_life = 86400 * 90;
    
  public static $user_updatable = true;
  public static $user_updatable_columns_white_list = array();
  public static $user_updatable_columns_black_list = array();
  public static $user_track_failed_logins = false;
  public static $user_max_failed_logins = null;

}
