-- WebConnect schema
-- Web-Based Chat, Voice & Video Communication System
-- Character set: utf8mb4 / utf8mb4_unicode_ci
-- Re-importing this file DROPS and recreates all WebConnect tables.

CREATE DATABASE IF NOT EXISTS webconnect
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE webconnect;

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS voice_call_signals;
DROP TABLE IF EXISTS voice_call_sessions;
DROP TABLE IF EXISTS group_message_reads;
DROP TABLE IF EXISTS group_message_reactions;
DROP TABLE IF EXISTS group_messages;
DROP TABLE IF EXISTS group_members;
DROP TABLE IF EXISTS group_chats;
DROP TABLE IF EXISTS message_read_receipts;
DROP TABLE IF EXISTS message_reactions;
DROP TABLE IF EXISTS messages;
DROP TABLE IF EXISTS typing_indicators;
DROP TABLE IF EXISTS notifications;
DROP TABLE IF EXISTS post_shares;
DROP TABLE IF EXISTS post_likes;
DROP TABLE IF EXISTS post_comments;
DROP TABLE IF EXISTS forum_posts;
DROP TABLE IF EXISTS forums;
DROP TABLE IF EXISTS reports;
DROP TABLE IF EXISTS user_blocks;
DROP TABLE IF EXISTS friendships;
DROP TABLE IF EXISTS friend_requests;
DROP TABLE IF EXISTS rate_limits;
DROP TABLE IF EXISTS users;
DROP TABLE IF EXISTS admins;

SET FOREIGN_KEY_CHECKS = 1;

CREATE TABLE admins (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(32) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  display_name VARCHAR(80) NOT NULL,
  must_change_password TINYINT(1) NOT NULL DEFAULT 1,
  last_login DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admins_username (username),
  UNIQUE KEY uq_admins_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  username VARCHAR(32) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password_hash VARCHAR(255) NOT NULL,
  avatar_path VARCHAR(255) NULL,
  status_message VARCHAR(160) NOT NULL DEFAULT '',
  presence_mode ENUM('online', 'away', 'busy') NOT NULL DEFAULT 'online',
  last_seen DATETIME NULL,
  is_active TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  KEY idx_users_last_seen (last_seen),
  KEY idx_users_active (is_active)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE friend_requests (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sender_id INT UNSIGNED NOT NULL,
  receiver_id INT UNSIGNED NOT NULL,
  status ENUM('pending', 'accepted', 'rejected', 'cancelled') NOT NULL DEFAULT 'pending',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_friend_req_receiver (receiver_id, status),
  KEY idx_friend_req_sender (sender_id, status),
  CONSTRAINT fk_freq_sender FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_freq_receiver FOREIGN KEY (receiver_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE friendships (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_one INT UNSIGNED NOT NULL,
  user_two INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_friend_pair (user_one, user_two),
  KEY idx_friend_two (user_two),
  CONSTRAINT chk_friend_order CHECK (user_one < user_two),
  CONSTRAINT fk_friend_one FOREIGN KEY (user_one) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_friend_two FOREIGN KEY (user_two) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE user_blocks (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  blocker_id INT UNSIGNED NOT NULL,
  blocked_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_block_pair (blocker_id, blocked_id),
  KEY idx_blocked (blocked_id),
  CONSTRAINT chk_block_self CHECK (blocker_id <> blocked_id),
  CONSTRAINT fk_block_blocker FOREIGN KEY (blocker_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_block_blocked FOREIGN KEY (blocked_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE messages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  sender_id INT UNSIGNED NOT NULL,
  receiver_id INT UNSIGNED NOT NULL,
  message_type ENUM('text', 'image', 'file', 'voice', 'call') NOT NULL DEFAULT 'text',
  body TEXT NULL,
  file_path VARCHAR(255) NULL,
  file_name VARCHAR(255) NULL,
  mime_type VARCHAR(127) NULL,
  file_size INT UNSIGNED NULL,
  voice_duration INT UNSIGNED NULL,
  reply_to_id INT UNSIGNED NULL,
  delivery_status ENUM('sent', 'delivered', 'read') NOT NULL DEFAULT 'sent',
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  deleted_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_messages_sender (sender_id, receiver_id, id),
  KEY idx_messages_receiver (receiver_id, sender_id, id),
  KEY idx_messages_receiver_status (receiver_id, delivery_status),
  KEY idx_messages_reply (reply_to_id),
  CONSTRAINT fk_msg_sender FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_msg_receiver FOREIGN KEY (receiver_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_msg_reply FOREIGN KEY (reply_to_id) REFERENCES messages (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE message_reactions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  message_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  emoji VARCHAR(16) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_msg_reaction_user (message_id, user_id),
  KEY idx_msg_reaction_user (user_id),
  CONSTRAINT fk_msg_react_msg FOREIGN KEY (message_id) REFERENCES messages (id) ON DELETE CASCADE,
  CONSTRAINT fk_msg_react_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE message_read_receipts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  message_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_msg_read (message_id, user_id),
  KEY idx_msg_read_user (user_id),
  CONSTRAINT fk_msg_read_msg FOREIGN KEY (message_id) REFERENCES messages (id) ON DELETE CASCADE,
  CONSTRAINT fk_msg_read_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE private_message_hides (
  user_id INT UNSIGNED NOT NULL,
  message_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, message_id),
  KEY idx_private_hide_message (message_id),
  CONSTRAINT fk_pm_hide_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_pm_hide_message FOREIGN KEY (message_id) REFERENCES messages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE private_chat_clears (
  user_id INT UNSIGNED NOT NULL,
  peer_id INT UNSIGNED NOT NULL,
  cleared_before_id INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, peer_id),
  KEY idx_private_clear_peer (peer_id),
  CONSTRAINT fk_pc_clear_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_pc_clear_peer FOREIGN KEY (peer_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE typing_indicators (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  context_type ENUM('private', 'group') NOT NULL,
  target_id INT UNSIGNED NOT NULL,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_typing (user_id, context_type, target_id),
  KEY idx_typing_target (context_type, target_id, updated_at),
  CONSTRAINT fk_typing_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE group_chats (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  avatar_path VARCHAR(255) NULL,
  owner_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_group_owner (owner_id),
  CONSTRAINT fk_group_owner FOREIGN KEY (owner_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE group_members (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  role ENUM('owner', 'admin', 'member') NOT NULL DEFAULT 'member',
  joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_group_member (group_id, user_id),
  KEY idx_group_member_user (user_id),
  CONSTRAINT fk_gm_group FOREIGN KEY (group_id) REFERENCES group_chats (id) ON DELETE CASCADE,
  CONSTRAINT fk_gm_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE group_messages (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  group_id INT UNSIGNED NOT NULL,
  sender_id INT UNSIGNED NOT NULL,
  message_type ENUM('text', 'image', 'file', 'voice') NOT NULL DEFAULT 'text',
  body TEXT NULL,
  file_path VARCHAR(255) NULL,
  file_name VARCHAR(255) NULL,
  mime_type VARCHAR(127) NULL,
  file_size INT UNSIGNED NULL,
  voice_duration INT UNSIGNED NULL,
  reply_to_id INT UNSIGNED NULL,
  is_deleted TINYINT(1) NOT NULL DEFAULT 0,
  deleted_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_gmsg_group (group_id, id),
  KEY idx_gmsg_sender (sender_id),
  KEY idx_gmsg_reply (reply_to_id),
  CONSTRAINT fk_gmsg_group FOREIGN KEY (group_id) REFERENCES group_chats (id) ON DELETE CASCADE,
  CONSTRAINT fk_gmsg_sender FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_gmsg_reply FOREIGN KEY (reply_to_id) REFERENCES group_messages (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE group_message_hides (
  user_id INT UNSIGNED NOT NULL,
  message_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, message_id),
  KEY idx_group_hide_message (message_id),
  CONSTRAINT fk_gm_hide_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_gm_hide_message FOREIGN KEY (message_id) REFERENCES group_messages (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE group_chat_clears (
  user_id INT UNSIGNED NOT NULL,
  group_id INT UNSIGNED NOT NULL,
  cleared_before_id INT UNSIGNED NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (user_id, group_id),
  KEY idx_group_clear_group (group_id),
  CONSTRAINT fk_gc_clear_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_gc_clear_group FOREIGN KEY (group_id) REFERENCES group_chats (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE group_message_reactions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  message_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  emoji VARCHAR(16) NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gmsg_reaction_user (message_id, user_id),
  KEY idx_gmsg_reaction_user (user_id),
  CONSTRAINT fk_greact_msg FOREIGN KEY (message_id) REFERENCES group_messages (id) ON DELETE CASCADE,
  CONSTRAINT fk_greact_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE group_message_reads (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  message_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  read_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_gmsg_read (message_id, user_id),
  KEY idx_gmsg_read_user (user_id),
  CONSTRAINT fk_gread_msg FOREIGN KEY (message_id) REFERENCES group_messages (id) ON DELETE CASCADE,
  CONSTRAINT fk_gread_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE voice_call_sessions (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  caller_id INT UNSIGNED NOT NULL,
  callee_id INT UNSIGNED NOT NULL,
  call_type ENUM('voice', 'video') NOT NULL,
  status ENUM('ringing', 'accepted', 'rejected', 'ended', 'missed', 'cancelled') NOT NULL DEFAULT 'ringing',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  answered_at DATETIME NULL,
  ended_at DATETIME NULL,
  logged_message_id INT UNSIGNED NULL,
  PRIMARY KEY (id),
  KEY idx_call_callee (callee_id, status, id),
  KEY idx_call_caller (caller_id, status, id),
  CONSTRAINT fk_call_caller FOREIGN KEY (caller_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_call_callee FOREIGN KEY (callee_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE voice_call_signals (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  session_id INT UNSIGNED NOT NULL,
  sender_id INT UNSIGNED NOT NULL,
  signal_type ENUM('offer', 'answer', 'ice', 'hangup', 'control') NOT NULL,
  payload MEDIUMTEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_signal_session (session_id, id),
  CONSTRAINT fk_signal_session FOREIGN KEY (session_id) REFERENCES voice_call_sessions (id) ON DELETE CASCADE,
  CONSTRAINT fk_signal_sender FOREIGN KEY (sender_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  type VARCHAR(40) NOT NULL,
  title VARCHAR(140) NOT NULL,
  body VARCHAR(255) NOT NULL,
  link_url VARCHAR(255) NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  related_user_id INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notif_user (user_id, is_read, id),
  CONSTRAINT fk_notif_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE,
  CONSTRAINT fk_notif_related FOREIGN KEY (related_user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE forums (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(80) NOT NULL,
  slug VARCHAR(80) NOT NULL,
  description VARCHAR(255) NOT NULL DEFAULT '',
  sort_order INT NOT NULL DEFAULT 0,
  member_can_post TINYINT(1) NOT NULL DEFAULT 1,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_forum_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE forum_posts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  forum_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  title VARCHAR(160) NOT NULL,
  content TEXT NOT NULL,
  is_hidden TINYINT(1) NOT NULL DEFAULT 0,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_posts_forum (forum_id, created_at),
  KEY idx_posts_user (user_id),
  CONSTRAINT fk_post_forum FOREIGN KEY (forum_id) REFERENCES forums (id) ON DELETE CASCADE,
  CONSTRAINT fk_post_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE post_comments (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NULL,
  content TEXT NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_comments_post (post_id, id),
  CONSTRAINT fk_comment_post FOREIGN KEY (post_id) REFERENCES forum_posts (id) ON DELETE CASCADE,
  CONSTRAINT fk_comment_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE post_likes (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_post_like (post_id, user_id),
  KEY idx_like_user (user_id),
  CONSTRAINT fk_like_post FOREIGN KEY (post_id) REFERENCES forum_posts (id) ON DELETE CASCADE,
  CONSTRAINT fk_like_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE post_shares (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  post_id INT UNSIGNED NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_share_post (post_id),
  KEY idx_share_user (user_id),
  CONSTRAINT fk_share_post FOREIGN KEY (post_id) REFERENCES forum_posts (id) ON DELETE CASCADE,
  CONSTRAINT fk_share_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE reports (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  reporter_id INT UNSIGNED NULL,
  target_type ENUM('user', 'private_message', 'group_message', 'forum_post') NOT NULL,
  target_id INT UNSIGNED NOT NULL,
  reason VARCHAR(500) NOT NULL,
  status ENUM('open', 'reviewed', 'resolved') NOT NULL DEFAULT 'open',
  admin_note VARCHAR(500) NULL,
  reviewed_by INT UNSIGNED NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  reviewed_at DATETIME NULL,
  PRIMARY KEY (id),
  KEY idx_reports_status (status, id),
  KEY idx_reports_target (target_type, target_id),
  KEY idx_reports_reporter (reporter_id),
  CONSTRAINT fk_report_reporter FOREIGN KEY (reporter_id) REFERENCES users (id) ON DELETE SET NULL,
  CONSTRAINT fk_report_admin FOREIGN KEY (reviewed_by) REFERENCES admins (id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE rate_limits (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  action_key VARCHAR(64) NOT NULL,
  actor_key VARCHAR(64) NOT NULL,
  window_start DATETIME NOT NULL,
  hit_count INT UNSIGNED NOT NULL DEFAULT 1,
  PRIMARY KEY (id),
  UNIQUE KEY uq_rate_actor (action_key, actor_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- DEMO ONLY seed accounts (change or delete before a public deployment).
-- admin / admin@example.com / AdminDemo123!
-- demo_alex / alex@example.com / DemoPass123
-- demo_blair / blair@example.com / DemoPass123
-- demo_casey / casey@example.com / DemoPass123

INSERT INTO admins (username, email, password_hash, display_name, must_change_password)
VALUES (
  'admin',
  'admin@example.com',
  '$2y$10$oQfKpkLuEpKRD9ZYx70fmeR/NFHsP1vTcuFzLBcf2BJPjWO7XeN36',
  'Platform Admin',
  1
);

INSERT INTO users (username, email, password_hash, status_message, presence_mode, is_active)
VALUES
  ('demo_alex', 'alex@example.com', '$2y$10$wT2xXpDkJcP10KrgwRXgjuxH1hFz1tq/vh6OH00n5VxSBIljZ9mOu', 'Ready to chat', 'online', 1),
  ('demo_blair', 'blair@example.com', '$2y$10$wT2xXpDkJcP10KrgwRXgjuxH1hFz1tq/vh6OH00n5VxSBIljZ9mOu', 'In a study session', 'away', 1),
  ('demo_casey', 'casey@example.com', '$2y$10$wT2xXpDkJcP10KrgwRXgjuxH1hFz1tq/vh6OH00n5VxSBIljZ9mOu', 'Hello from WebConnect', 'online', 1);

INSERT INTO friendships (user_one, user_two) VALUES (1, 2);
INSERT INTO friend_requests (sender_id, receiver_id, status) VALUES (3, 1, 'pending');

INSERT INTO forums (name, slug, description, sort_order, member_can_post) VALUES
  ('General Discussion', 'general-discussion', 'Everyday conversations with the community.', 1, 1),
  ('Technology', 'technology', 'Tools, coding, and how things work.', 2, 1),
  ('Student Discussion', 'student-discussion', 'Classes, projects, and study plans.', 3, 1),
  ('Announcements', 'announcements', 'Official updates from the WebConnect team.', 4, 0);
