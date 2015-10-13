CREATE TABLE `client` (
  `client_id` int(10) unsigned not null primary key AUTO_INCREMENT,
  `code` varchar(255) DEFAULT NULL,
  `name` varchar(1024),
  UNIQUE KEY (`code`),
  `deleted` tinyint(1) NOT NULL DEFAULT '0'
) ENGINE=InnoDB;

 CREATE TABLE `user` (
  `user_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `name_first` varchar(255) NOT NULL,
  `name_last` varchar(255) NOT NULL,
  `username` varchar(255) NOT NULL,
  `client_id` int(10) UNSIGNED NOT NULL,
  `uuid` varchar(36) NOT NULL,
  `password` varbinary(128) NOT NULL,
  failed_logins int unsigned not null default 0,
  json_client_ids varchar(4096) not null default '[]',
  `deleted` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`user_id`),
  UNIQUE KEY `username` (`username`, client_id),
  foreign key (client_id) references client (client_id)
) ENGINE=InnoDB;

CREATE TABLE `session` (
  `session_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  client_id int(10) unsigned not null,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `created_by` int(10) unsigned NOT NULL,
  `used_at` timestamp NOT NULL DEFAULT '0000-00-00 00:00:00',
  `used_by` int(10) unsigned NOT NULL DEFAULT '0',
  `deleted` tinyint(1) NOT NULL DEFAULT '0',
  `key` varbinary(128) NOT NULL,
  PRIMARY KEY (`session_id`),
  UNIQUE KEY `key` (`key`),
  FOREIGN KEY (`user_id`) references `user` (`user_id`),
  FOREIGN KEY (`client_id`) references `client` (`client_id`)
) ENGINE=InnoDB;

CREATE TABLE `yinaf` (
  `yinaf_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `updated_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `table` varchar(255) NOT NULL,
  `json_columns` varchar(65000) NOT NULL,
  PRIMARY KEY (`yinaf_id`),
  UNIQUE KEY `table` (`table`)
) ENGINE=InnoDB;

CREATE TABLE `audit_created` (
  `audit_created_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `table` varchar(255) NOT NULL,
  `id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`audit_created_id`),
  KEY `id` (`id`,`table`),
  FOREIGN KEY (`user_id`) references `user` (`user_id`),
  KEY `timestamp` (`timestamp`,`table`)
) ENGINE=InnoDB;

CREATE TABLE `audit_updated` (
  `audit_updated_id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `table` varchar(255) NOT NULL,
  `id` int(10) unsigned NOT NULL,
  `user_id` int(10) unsigned DEFAULT NULL,
  `timestamp` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `column` varchar(255) NOT NULL,
  `old_value` varchar(30000) DEFAULT NULL,
  `new_value` varchar(30000) DEFAULT NULL,
  PRIMARY KEY (`audit_updated_id`),
  FOREIGN KEY (`user_id`) references `user` (`user_id`),
  KEY `id` (`id`,`table`),
  KEY `timestamp` (`timestamp`,`table`)
) ENGINE=InnoDB;
