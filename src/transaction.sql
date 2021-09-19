CREATE TABLE `__transaction__` (
  `transaction_id` varchar(48) NOT NULL COMMENT '事务ID',
  `transaction_req` varchar(32) NOT NULL DEFAULT '0' COMMENT '请求序号(用于区分同事务中请求同一个服务的同一个方法且相同参数）',
  `transaction_type` char(7) NOT NULL COMMENT '事务类型',
  `service_class` varchar(128) NOT NULL COMMENT '服务类名',
  `service_func` varchar(32) NOT NULL COMMENT '服务方法名',
  `service_params` text COMMENT '服务参数',
  `service_result` text COMMENT '服务返回结果',
  `is_local` tinyint(1) NOT NULL DEFAULT '0' COMMENT '是否本机事务服务',
  `status` tinyint(1) unsigned NOT NULL DEFAULT '0' COMMENT '事务状态0待执行1失败2成功(外调成功的删除)',
  `execute_count` tinyint(2) unsigned NOT NULL DEFAULT '0' COMMENT '已执行次数',
  `max_retry` tinyint(2) NOT NULL DEFAULT '0' COMMENT '最大重试次数',
  `ct` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `mt` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY `uni_idx` (`transaction_id`,`transaction_req`,`service_class`,`service_func`,`transaction_type`) USING BTREE,
  KEY `sm_idx` (`status`,`is_local`,`mt`,`transaction_type`) USING BTREE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

