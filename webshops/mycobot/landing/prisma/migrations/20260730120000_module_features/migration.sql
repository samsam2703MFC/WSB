-- One row per documented function of a module. Copy lives in tfb_translations
-- (entity_type = 'feature', fields name + description).
CREATE TABLE `tfb_module_features` (
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `module_id` INTEGER NOT NULL,
    `key` VARCHAR(80) NOT NULL,
    `icon` VARCHAR(60) NULL,
    `is_active` BOOLEAN NOT NULL DEFAULT true,
    `sort_order` INTEGER NOT NULL DEFAULT 0,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    UNIQUE INDEX `uq_module_feature_key`(`module_id`, `key`),
    INDEX `tfb_module_features_module_id_idx`(`module_id`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- A screenshot may illustrate one function; NULL keeps it a general module shot.
ALTER TABLE `tfb_module_screenshots` ADD COLUMN `feature_id` INTEGER NULL;

CREATE INDEX `tfb_module_screenshots_feature_id_idx` ON `tfb_module_screenshots`(`feature_id`);

ALTER TABLE `tfb_module_features` ADD CONSTRAINT `tfb_module_features_module_id_fkey` FOREIGN KEY (`module_id`) REFERENCES `tfb_modules`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

ALTER TABLE `tfb_module_screenshots` ADD CONSTRAINT `tfb_module_screenshots_feature_id_fkey` FOREIGN KEY (`feature_id`) REFERENCES `tfb_module_features`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;
