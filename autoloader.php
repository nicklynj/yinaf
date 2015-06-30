<?php

namespace yinaf;

set_include_path(
  get_include_path() . PATH_SEPARATOR . 
  __DIR__ . DIRECTORY_SEPARATOR . '..'
);

spl_autoload_register('spl_autoload', false);
