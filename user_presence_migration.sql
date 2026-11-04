-- Run once on an existing Bio-Sync database to enable multi-session presence.
CREATE TABLE IF NOT EXISTS `user_presence` (
  `session_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `user_id` int(11) NOT NULL,
  `last_seen_at` datetime NOT NULL,
  PRIMARY KEY (`session_hash`),
  KEY `idx_user_presence_user_seen` (`user_id`, `last_seen_at`),
  CONSTRAINT `user_presence_ibfk_1` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
