CREATE TABLE `solr_map` (
    `id` INT AUTO_INCREMENT NOT NULL,
    `engine_id` INT NOT NULL,
    `resource_name` VARCHAR(190) NOT NULL,
    `field_name` VARCHAR(190) NOT NULL,
    `source` VARCHAR(190) NOT NULL,
    `alias` VARCHAR(190) DEFAULT NULL,
    `pool` LONGTEXT NOT NULL COMMENT '(DC2Type:json)',
    `settings` LONGTEXT NOT NULL COMMENT '(DC2Type:json)',
    INDEX IDX_39A565C5E78C9C0A (`engine_id`),
    INDEX IDX_39A565C5E78C9C0A5103DEBC (`engine_id`, `resource_name`),
    INDEX IDX_39A565C5E78C9C0A4DEF17BC (`engine_id`, `field_name`),
    INDEX IDX_39A565C5E78C9C0AE16C6B94 (`engine_id`, `alias`),
    INDEX IDX_39A565C5E78C9C0A5F8A7F73 (`engine_id`, `source`),
    PRIMARY KEY(`id`)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;

ALTER TABLE `solr_map` ADD CONSTRAINT FK_39A565C5E78C9C0A FOREIGN KEY (`engine_id`) REFERENCES `search_engine` (`id`) ON DELETE CASCADE;
