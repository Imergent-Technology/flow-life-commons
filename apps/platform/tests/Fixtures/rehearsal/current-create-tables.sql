CREATE TABLE `account_invitations` (
  `id` char(26) NOT NULL,
  `account_id` char(26) NOT NULL,
  `token_hash` varchar(64) NOT NULL,
  `expires_at` datetime NOT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `invited_by_account_id` char(26) DEFAULT NULL,
  `channel` varchar(16) NOT NULL DEFAULT 'operator',
  PRIMARY KEY (`id`),
  UNIQUE KEY `account_invitations_token_hash_unique` (`token_hash`),
  KEY `account_invitations_account_id_index` (`account_id`),
  CONSTRAINT `account_invitations_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `account_recovery_codes` (
  `id` char(26) NOT NULL,
  `account_id` char(26) NOT NULL,
  `code_hash` varchar(64) NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `account_recovery_codes_account_id_code_hash_unique` (`account_id`,`code_hash`),
  CONSTRAINT `account_recovery_codes_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `account_totp_factors` (
  `id` char(26) NOT NULL,
  `account_id` char(26) NOT NULL,
  `secret_ciphertext` text DEFAULT NULL,
  `pending_secret_ciphertext` text DEFAULT NULL,
  `pending_started_at` datetime DEFAULT NULL,
  `enrolled_at` datetime DEFAULT NULL,
  `last_used_step` bigint(20) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `account_totp_factors_account_id_unique` (`account_id`),
  CONSTRAINT `account_totp_factors_account_id_foreign` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `accounts` (
  `id` char(26) NOT NULL,
  `person_id` char(26) NOT NULL,
  `email` varchar(254) NOT NULL,
  `email_canonical` varchar(254) NOT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `password_updated_at` datetime DEFAULT NULL,
  `status` varchar(16) NOT NULL,
  `security_generation` bigint(20) unsigned NOT NULL DEFAULT 1,
  `disabled_at` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `accounts_email_canonical_unique` (`email_canonical`),
  UNIQUE KEY `accounts_person_id_unique` (`person_id`),
  CONSTRAINT `accounts_person_id_foreign` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `cache` (
  `key` varchar(255) NOT NULL,
  `value` mediumtext NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `cache_locks` (
  `key` varchar(255) NOT NULL,
  `owner` varchar(255) NOT NULL,
  `expiration` bigint(20) NOT NULL,
  PRIMARY KEY (`key`),
  KEY `cache_locks_expiration_index` (`expiration`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `contact_interactions` (
  `id` char(26) NOT NULL,
  `person_id` char(26) NOT NULL,
  `kind` varchar(16) NOT NULL,
  `body` text NOT NULL,
  `occurred_at` datetime NOT NULL,
  `author_person_id` char(26) NOT NULL,
  `updated_by_person_id` char(26) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `contact_interactions_person_occurred_index` (`person_id`,`occurred_at`,`id`),
  CONSTRAINT `contact_interactions_person_id_foreign` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `contact_methods` (
  `id` char(26) NOT NULL,
  `person_id` char(26) NOT NULL,
  `kind` varchar(16) NOT NULL,
  `value` varchar(255) NOT NULL,
  `search_value` varchar(255) NOT NULL,
  `label` varchar(64) DEFAULT NULL,
  `primary_kind` varchar(16) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `updated_by_account_id` char(26) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contact_methods_person_kind_value_unique` (`person_id`,`kind`,`search_value`),
  UNIQUE KEY `contact_methods_one_primary_per_kind_unique` (`person_id`,`primary_kind`),
  KEY `contact_methods_kind_search_value_index` (`kind`,`search_value`),
  CONSTRAINT `contact_methods_person_id_foreign` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `contact_profiles` (
  `person_id` char(26) NOT NULL,
  `how_we_know` text DEFAULT NULL,
  `affiliation` varchar(255) DEFAULT NULL,
  `updated_by_account_id` char(26) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`person_id`),
  CONSTRAINT `contact_profiles_person_id_foreign` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `contact_tag_assignments` (
  `person_id` char(26) NOT NULL,
  `tag_id` char(26) NOT NULL,
  `assigned_by_account_id` char(26) DEFAULT NULL,
  `assigned_at` datetime NOT NULL,
  PRIMARY KEY (`person_id`,`tag_id`),
  KEY `contact_tag_assignments_tag_id_index` (`tag_id`),
  CONSTRAINT `contact_tag_assignments_person_id_foreign` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`),
  CONSTRAINT `contact_tag_assignments_tag_id_foreign` FOREIGN KEY (`tag_id`) REFERENCES `contact_tags` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `contact_tags` (
  `id` char(26) NOT NULL,
  `name` varchar(64) NOT NULL,
  `name_canonical` varchar(64) NOT NULL,
  `created_by_account_id` char(26) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `contact_tags_name_canonical_unique` (`name_canonical`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `discussion_messages` (
  `id` char(26) NOT NULL,
  `discussion_id` char(26) NOT NULL,
  `sequence` int(11) NOT NULL,
  `author_person_id` char(26) NOT NULL,
  `body` text DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `edited_at` datetime DEFAULT NULL,
  `edited_by_person_id` char(26) DEFAULT NULL,
  `removed_at` datetime DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `discussion_messages_sequence_unique` (`discussion_id`,`sequence`),
  CONSTRAINT `discussion_messages_discussion_id_foreign` FOREIGN KEY (`discussion_id`) REFERENCES `discussions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `discussions` (
  `id` char(26) NOT NULL,
  `title` varchar(200) NOT NULL,
  `state` varchar(16) NOT NULL,
  `message_count` int(11) NOT NULL,
  `last_activity_at` datetime NOT NULL,
  `resolved_at` datetime DEFAULT NULL,
  `resolved_by_person_id` char(26) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `discussions_activity_index` (`last_activity_at`,`id`),
  KEY `discussions_state_activity_index` (`state`,`last_activity_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `failed_jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `uuid` varchar(255) NOT NULL,
  `connection` varchar(255) NOT NULL,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `exception` longtext NOT NULL,
  `failed_at` timestamp NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `failed_jobs_uuid_unique` (`uuid`),
  KEY `failed_jobs_connection_queue_failed_at_index` (`connection`,`queue`,`failed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `job_batches` (
  `id` varchar(255) NOT NULL,
  `name` varchar(255) NOT NULL,
  `total_jobs` int(11) NOT NULL,
  `pending_jobs` int(11) NOT NULL,
  `failed_jobs` int(11) NOT NULL,
  `failed_job_ids` longtext NOT NULL,
  `options` mediumtext DEFAULT NULL,
  `cancelled_at` int(11) DEFAULT NULL,
  `created_at` int(11) NOT NULL,
  `finished_at` int(11) DEFAULT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `jobs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `queue` varchar(255) NOT NULL,
  `payload` longtext NOT NULL,
  `attempts` smallint(5) unsigned NOT NULL,
  `reserved_at` int(10) unsigned DEFAULT NULL,
  `available_at` int(10) unsigned NOT NULL,
  `created_at` int(10) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  KEY `jobs_queue_index` (`queue`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `membership_grants` (
  `id` char(26) NOT NULL,
  `person_id` char(26) NOT NULL,
  `starts_at` datetime NOT NULL,
  `ends_at` datetime DEFAULT NULL,
  `source` varchar(32) NOT NULL,
  `source_reference` varchar(191) DEFAULT NULL,
  `granted_by_account_id` char(26) DEFAULT NULL,
  `revoked_at` datetime DEFAULT NULL,
  `revoked_by_account_id` char(26) DEFAULT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `membership_grants_person_id_index` (`person_id`),
  CONSTRAINT `membership_grants_person_id_foreign` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `migrations` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `migration` varchar(255) NOT NULL,
  `batch` int(11) NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `password_reset_tokens` (
  `email` varchar(254) NOT NULL,
  `token` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `people` (
  `id` char(26) NOT NULL,
  `display_name` varchar(255) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `person_relationship_field_values` (
  `relationship_id` char(26) NOT NULL,
  `field_key` varchar(64) NOT NULL,
  `value` text NOT NULL,
  `updated_at` datetime NOT NULL,
  `updated_by` char(26) NOT NULL,
  PRIMARY KEY (`relationship_id`,`field_key`),
  CONSTRAINT `person_relationship_field_values_relationship_id_foreign` FOREIGN KEY (`relationship_id`) REFERENCES `person_relationships` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `person_relationship_role_provisions` (
  `relationship_id` char(26) NOT NULL,
  `role_key` varchar(64) NOT NULL,
  `decision` varchar(16) NOT NULL,
  `decided_at` datetime NOT NULL,
  `decided_by` char(26) NOT NULL,
  PRIMARY KEY (`relationship_id`,`role_key`),
  CONSTRAINT `person_relationship_role_provisions_relationship_id_foreign` FOREIGN KEY (`relationship_id`) REFERENCES `person_relationships` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `person_relationship_status_changes` (
  `id` char(26) NOT NULL,
  `relationship_id` char(26) NOT NULL,
  `from_status` varchar(16) DEFAULT NULL,
  `to_status` varchar(16) NOT NULL,
  `changed_at` datetime NOT NULL,
  `changed_by` char(26) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `person_relationship_status_changes_order_index` (`relationship_id`,`changed_at`,`id`),
  CONSTRAINT `person_relationship_status_changes_relationship_id_foreign` FOREIGN KEY (`relationship_id`) REFERENCES `person_relationships` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `person_relationships` (
  `id` char(26) NOT NULL,
  `person_id` char(26) NOT NULL,
  `relationship_type` varchar(32) NOT NULL,
  `status` varchar(16) NOT NULL,
  `revision` int(10) unsigned NOT NULL,
  `status_changed_at` datetime NOT NULL,
  `status_changed_by` char(26) NOT NULL,
  `created_at` datetime NOT NULL,
  `created_by` char(26) NOT NULL,
  `updated_at` datetime NOT NULL,
  `updated_by` char(26) NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `person_relationships_person_type_unique` (`person_id`,`relationship_type`),
  KEY `person_relationships_type_status_index` (`relationship_type`,`status`),
  CONSTRAINT `person_relationships_person_id_foreign` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `resource_assets` (
  `id` char(26) NOT NULL,
  `storage_key` varchar(64) NOT NULL,
  `original_filename` varchar(255) NOT NULL,
  `media_type` varchar(127) NOT NULL,
  `byte_size` bigint(20) NOT NULL,
  `sha256` char(64) NOT NULL,
  `uploaded_by_person_id` char(26) NOT NULL,
  `created_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `resource_assets_storage_key_unique` (`storage_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `resource_card_audiences` (
  `card_id` char(26) NOT NULL,
  `audience` varchar(32) NOT NULL,
  PRIMARY KEY (`card_id`,`audience`),
  CONSTRAINT `resource_card_audiences_card_id_foreign` FOREIGN KEY (`card_id`) REFERENCES `resource_cards` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `resource_cards` (
  `id` char(26) NOT NULL,
  `pack_id` char(26) NOT NULL,
  `position` int(11) NOT NULL,
  `type` varchar(32) NOT NULL,
  `title` varchar(200) NOT NULL,
  `summary_mode` varchar(16) NOT NULL,
  `summary_text` varchar(300) NOT NULL,
  `content_format` varchar(32) NOT NULL,
  `content_version` int(11) NOT NULL,
  `content_document` mediumtext NOT NULL,
  `external_uri` varchar(2048) DEFAULT NULL,
  `audience_mode` varchar(16) NOT NULL,
  `state` varchar(16) NOT NULL,
  `revision` int(11) NOT NULL,
  `created_by_person_id` char(26) NOT NULL,
  `updated_by_person_id` char(26) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  `asset_id` char(26) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `resource_cards_asset_id_unique` (`asset_id`),
  KEY `resource_cards_order_index` (`pack_id`,`position`,`id`),
  CONSTRAINT `resource_cards_asset_id_foreign` FOREIGN KEY (`asset_id`) REFERENCES `resource_assets` (`id`),
  CONSTRAINT `resource_cards_pack_id_foreign` FOREIGN KEY (`pack_id`) REFERENCES `resource_packs` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `resource_categories` (
  `id` char(26) NOT NULL,
  `name` varchar(80) NOT NULL,
  `name_canonical` varchar(80) NOT NULL,
  `position` int(11) NOT NULL,
  `created_by_person_id` char(26) NOT NULL,
  `updated_by_person_id` char(26) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `resource_categories_name_canonical_unique` (`name_canonical`),
  KEY `resource_categories_order_index` (`position`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `resource_pack_audiences` (
  `pack_id` char(26) NOT NULL,
  `audience` varchar(32) NOT NULL,
  PRIMARY KEY (`pack_id`,`audience`),
  KEY `resource_pack_audiences_audience_index` (`audience`),
  CONSTRAINT `resource_pack_audiences_pack_id_foreign` FOREIGN KEY (`pack_id`) REFERENCES `resource_packs` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `resource_packs` (
  `id` char(26) NOT NULL,
  `category_id` char(26) DEFAULT NULL,
  `position` int(11) NOT NULL,
  `title` varchar(200) NOT NULL,
  `summary` varchar(300) DEFAULT NULL,
  `is_series` tinyint(1) NOT NULL DEFAULT 0,
  `state` varchar(16) NOT NULL,
  `revision` int(11) NOT NULL,
  `created_by_person_id` char(26) NOT NULL,
  `updated_by_person_id` char(26) NOT NULL,
  `created_at` datetime NOT NULL,
  `updated_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  KEY `resource_packs_order_index` (`category_id`,`position`,`id`),
  KEY `resource_packs_state_index` (`state`),
  CONSTRAINT `resource_packs_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `resource_categories` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `role_assignments` (
  `id` char(26) NOT NULL,
  `person_id` char(26) NOT NULL,
  `role_key` varchar(64) NOT NULL,
  `granted_by_account_id` char(26) DEFAULT NULL,
  `granted_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `role_assignments_person_id_role_key_unique` (`person_id`,`role_key`),
  KEY `role_assignments_role_key_index` (`role_key`),
  CONSTRAINT `role_assignments_person_id_foreign` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `security_events` (
  `id` char(26) NOT NULL,
  `occurred_at` datetime NOT NULL,
  `type` varchar(64) NOT NULL,
  `actor_account_id` char(26) DEFAULT NULL,
  `actor_client_id` char(26) DEFAULT NULL,
  `subject_person_id` char(26) DEFAULT NULL,
  `subject_account_id` char(26) DEFAULT NULL,
  `ip` varchar(45) DEFAULT NULL,
  `user_agent` varchar(512) DEFAULT NULL,
  `outcome` varchar(16) NOT NULL,
  `context` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`context`)),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `sessions` (
  `id` varchar(255) NOT NULL,
  `user_id` varchar(26) DEFAULT NULL,
  `ip_address` varchar(45) DEFAULT NULL,
  `user_agent` text DEFAULT NULL,
  `payload` longtext NOT NULL,
  `last_activity` int(11) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `sessions_user_id_index` (`user_id`),
  KEY `sessions_last_activity_index` (`last_activity`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
CREATE TABLE `sourced_role_grants` (
  `id` char(26) NOT NULL,
  `person_id` char(26) NOT NULL,
  `role_key` varchar(64) NOT NULL,
  `source_type` varchar(32) NOT NULL,
  `source_id` char(26) NOT NULL,
  `granted_by_account_id` char(26) DEFAULT NULL,
  `granted_at` datetime NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sourced_role_grants_source_role_unique` (`source_type`,`source_id`,`role_key`),
  KEY `sourced_role_grants_person_id_index` (`person_id`),
  KEY `sourced_role_grants_role_key_index` (`role_key`),
  CONSTRAINT `sourced_role_grants_person_id_foreign` FOREIGN KEY (`person_id`) REFERENCES `people` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
