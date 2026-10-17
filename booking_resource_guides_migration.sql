-- Apply manually before deploying multi-resource booking code. No historical rows are changed.
CREATE TABLE IF NOT EXISTS booking_resource_boats (
    booking_id INT NOT NULL, boat_id INT NOT NULL,
    PRIMARY KEY (booking_id, boat_id), INDEX idx_reserved_boat (boat_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS booking_resource_guides (
    booking_id INT NOT NULL, guide_id INT NOT NULL,
    PRIMARY KEY (booking_id, guide_id), INDEX idx_reserved_guide (guide_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
