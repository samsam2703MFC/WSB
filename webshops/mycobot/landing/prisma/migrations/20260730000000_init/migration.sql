-- CreateTable
CREATE TABLE `tfb_languages` (
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `code` VARCHAR(5) NOT NULL,
    `name` VARCHAR(50) NOT NULL,
    `is_rtl` BOOLEAN NOT NULL DEFAULT false,
    `is_active` BOOLEAN NOT NULL DEFAULT true,
    `is_default` BOOLEAN NOT NULL DEFAULT false,
    `sort_order` INTEGER NOT NULL DEFAULT 0,

    UNIQUE INDEX `tfb_languages_code_key`(`code`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `tfb_translations` (
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `entity_type` VARCHAR(50) NOT NULL,
    `entity_id` INTEGER NOT NULL,
    `field` VARCHAR(50) NOT NULL,
    `language_code` VARCHAR(5) NOT NULL,
    `value` TEXT NOT NULL,

    INDEX `tfb_translations_entity_type_entity_id_idx`(`entity_type`, `entity_id`),
    INDEX `tfb_translations_language_code_idx`(`language_code`),
    UNIQUE INDEX `uq_translation_scope`(`entity_type`, `entity_id`, `field`, `language_code`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `tfb_brands` (
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(120) NOT NULL,
    `slug` VARCHAR(120) NOT NULL,
    `logo_path` VARCHAR(255) NULL,
    `website_url` VARCHAR(255) NULL,
    `is_active` BOOLEAN NOT NULL DEFAULT true,
    `sort_order` INTEGER NOT NULL DEFAULT 0,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    UNIQUE INDEX `tfb_brands_slug_key`(`slug`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `tfb_sections` (
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `key` VARCHAR(80) NOT NULL,
    `type` VARCHAR(50) NOT NULL,
    `brand_id` INTEGER NULL,
    `is_active` BOOLEAN NOT NULL DEFAULT true,
    `sort_order` INTEGER NOT NULL DEFAULT 0,
    `config` JSON NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    UNIQUE INDEX `tfb_sections_key_key`(`key`),
    INDEX `tfb_sections_brand_id_idx`(`brand_id`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `tfb_modules` (
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `key` VARCHAR(80) NOT NULL,
    `slug` VARCHAR(120) NOT NULL,
    `redirect_url` VARCHAR(255) NULL,
    `repo` VARCHAR(150) NULL,
    `module_group` VARCHAR(80) NULL,
    `icon` VARCHAR(60) NULL,
    `is_active` BOOLEAN NOT NULL DEFAULT true,
    `is_new` BOOLEAN NOT NULL DEFAULT true,
    `sort_order` INTEGER NOT NULL DEFAULT 0,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    UNIQUE INDEX `tfb_modules_key_key`(`key`),
    UNIQUE INDEX `tfb_modules_slug_key`(`slug`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `tfb_module_screenshots` (
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `module_id` INTEGER NOT NULL,
    `file_path` VARCHAR(255) NOT NULL,
    `sort_order` INTEGER NOT NULL DEFAULT 0,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    INDEX `tfb_module_screenshots_module_id_idx`(`module_id`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `tfb_plans` (
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `key` VARCHAR(80) NOT NULL,
    `stripe_price_id` VARCHAR(120) NULL,
    `amount` INTEGER NULL,
    `currency` VARCHAR(3) NOT NULL DEFAULT 'EUR',
    `interval` VARCHAR(20) NOT NULL,
    `is_active` BOOLEAN NOT NULL DEFAULT true,
    `is_featured` BOOLEAN NOT NULL DEFAULT false,
    `sort_order` INTEGER NOT NULL DEFAULT 0,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    UNIQUE INDEX `tfb_plans_key_key`(`key`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `tfb_subscriptions` (
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `brand_id` INTEGER NULL,
    `email` VARCHAR(150) NOT NULL,
    `stripe_customer_id` VARCHAR(120) NULL,
    `stripe_subscription_id` VARCHAR(120) NULL,
    `plan_id` INTEGER NOT NULL,
    `status` VARCHAR(30) NOT NULL,
    `current_period_end` DATETIME(3) NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    UNIQUE INDEX `tfb_subscriptions_stripe_subscription_id_key`(`stripe_subscription_id`),
    INDEX `tfb_subscriptions_brand_id_idx`(`brand_id`),
    INDEX `tfb_subscriptions_plan_id_idx`(`plan_id`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `tfb_contact_messages` (
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(120) NOT NULL,
    `email` VARCHAR(150) NOT NULL,
    `company` VARCHAR(150) NULL,
    `message` TEXT NOT NULL,
    `language_code` VARCHAR(5) NULL,
    `status` VARCHAR(20) NOT NULL DEFAULT 'new',
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),

    INDEX `tfb_contact_messages_status_idx`(`status`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `tfb_admin_users` (
    `id` INTEGER NOT NULL AUTO_INCREMENT,
    `email` VARCHAR(150) NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,
    `role` VARCHAR(30) NOT NULL DEFAULT 'admin',
    `is_active` BOOLEAN NOT NULL DEFAULT true,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL,

    UNIQUE INDEX `tfb_admin_users_email_key`(`email`),
    PRIMARY KEY (`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- CreateTable
CREATE TABLE `tfb_settings` (
    `key` VARCHAR(120) NOT NULL,
    `value` JSON NOT NULL,

    PRIMARY KEY (`key`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- AddForeignKey
ALTER TABLE `tfb_translations` ADD CONSTRAINT `tfb_translations_language_code_fkey` FOREIGN KEY (`language_code`) REFERENCES `tfb_languages`(`code`) ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `tfb_sections` ADD CONSTRAINT `tfb_sections_brand_id_fkey` FOREIGN KEY (`brand_id`) REFERENCES `tfb_brands`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `tfb_module_screenshots` ADD CONSTRAINT `tfb_module_screenshots_module_id_fkey` FOREIGN KEY (`module_id`) REFERENCES `tfb_modules`(`id`) ON DELETE CASCADE ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `tfb_subscriptions` ADD CONSTRAINT `tfb_subscriptions_brand_id_fkey` FOREIGN KEY (`brand_id`) REFERENCES `tfb_brands`(`id`) ON DELETE SET NULL ON UPDATE CASCADE;

-- AddForeignKey
ALTER TABLE `tfb_subscriptions` ADD CONSTRAINT `tfb_subscriptions_plan_id_fkey` FOREIGN KEY (`plan_id`) REFERENCES `tfb_plans`(`id`) ON DELETE RESTRICT ON UPDATE CASCADE;

