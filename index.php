<?php

namespace yinaf;

require 'autoloader.php';
require 'exception.php';

new request_json($_GET + $_POST + $_COOKIE);
