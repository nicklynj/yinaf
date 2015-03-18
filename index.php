<?php

set_include_path(
  get_include_path() . PATH_SEPARATOR . 
  __DIR__ . DIRECTORY_SEPARATOR . '..' . DIRECTORY_SEPARATOR . 'php'
);

spl_autoload_register('spl_autoload', false);

if (isset($_REQUEST['class']) and isset($_REQUEST['function']) and isset($_REQUEST['arguments'])) {
  new request_json($_REQUEST);
}
