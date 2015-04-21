 CREATE TABLE `user` (
  `user_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `username` varchar(255) COLLATE latin1_general_ci NOT NULL,
  `uuid` varchar(36) COLLATE latin1_general_ci NOT NULL,
  `password` varchar(128) COLLATE latin1_general_ci NOT NULL,
  `deleted` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`)
) ENGINE=InnoDB;

CREATE TABLE `session` (
  `session_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int(10) unsigned NOT NULL,
  `used_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `used_by` int(10) unsigned NOT NULL DEFAULT '0',
  `deleted` tinyint(1) NOT NULL DEFAULT '0',
  `key` varchar(128) COLLATE latin1_general_ci NOT NULL,
  PRIMARY KEY (`session_id`),
  UNIQUE KEY `key` (`key`),
  FOREIGN KEY (`user_id`) references `user` (`user_id`)
) ENGINE=InnoDB;

CREATE TABLE `yinaf` (
  `yinaf_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `table` varchar(255) NOT NULL,
  `json_columns` varchar(65000) NOT NULL,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`yinaf_id`)
) ENGINE=InnoDB;
