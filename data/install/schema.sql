CREATE TABLE `solr_core` (
    `id` INT AUTO_INCREMENT NOT NULL,
    `name` VARCHAR(190) NOT NULL,
    `settings` LONGTEXT NOT NULL COMMENT '(DC2Type:json)',
    PRIMARY KEY(`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
CREATE TABLE `solr_map` (
    `id` INT AUTO_INCREMENT NOT NULL,
    `solr_core_id` INT NOT NULL,
    `resource_name` VARCHAR(190) NOT NULL,
    `field_name` VARCHAR(190) NOT NULL,
    `source` VARCHAR(190) NOT NULL,
    `pool` LONGTEXT NOT NULL COMMENT '(DC2Type:json)',
    `settings` LONGTEXT NOT NULL COMMENT '(DC2Type:json)',
    INDEX IDX_39A565C527B35A19 (`solr_core_id`),
    INDEX IDX_39A565C527B35A195103DEBC (`solr_core_id`, `resource_name`),
    INDEX IDX_39A565C527B35A194DEF17BC (`solr_core_id`, `field_name`),
    INDEX IDX_39A565C527B35A195F8A7F73 (`solr_core_id`, `source`),
    PRIMARY KEY(`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
ALTER TABLE `solr_map` ADD CONSTRAINT FK_39A565C527B35A19 FOREIGN KEY (`solr_core_id`) REFERENCES `solr_core` (`id`) ON DELETE CASCADE;
