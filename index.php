<?php

set_include_path(
  get_include_path() . PATH_SEPARATOR . 
  __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'php'
);

spl_autoload_register('spl_autoload', false);

new request_json($_REQUEST);
