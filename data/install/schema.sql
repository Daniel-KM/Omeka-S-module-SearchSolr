CREATE TABLE solr_node (
    id INT AUTO_INCREMENT NOT NULL,
    name VARCHAR(255) NOT NULL,
    settings LONGTEXT NOT NULL COMMENT '(DC2Type:json_array)',
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
CREATE TABLE solr_mapping (
    id INT AUTO_INCREMENT NOT NULL,
    solr_node_id INT NOT NULL,
    resource_name VARCHAR(255) NOT NULL,
    field_name VARCHAR(255) NOT NULL,
    source VARCHAR(255) NOT NULL,
    settings LONGTEXT NOT NULL COMMENT '(DC2Type:json_array)',
    INDEX IDX_A62FEAA6A9C459FB (solr_node_id),
    PRIMARY KEY(id)
) DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci ENGINE = InnoDB;
ALTER TABLE solr_mapping ADD CONSTRAINT FK_A62FEAA6A9C459FB FOREIGN KEY (solr_node_id) REFERENCES solr_node (id) ON DELETE CASCADE;
