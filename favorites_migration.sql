CREATE TABLE IF NOT EXISTS tourist_favorites (
    favorite_id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    tourist_id INT NOT NULL,
    entity_type ENUM('hotel', 'package', 'guide', 'boat') NOT NULL,
    entity_id INT NOT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (favorite_id),
    UNIQUE KEY uq_tourist_favorite (tourist_id, entity_type, entity_id),
    KEY idx_favorites_tourist_created (tourist_id, created_at),
    KEY idx_favorites_entity (entity_type, entity_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
