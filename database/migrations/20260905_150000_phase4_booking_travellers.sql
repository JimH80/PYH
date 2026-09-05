CREATE TABLE booking_travellers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id BIGINT UNSIGNED NOT NULL,
    traveller_id BIGINT UNSIGNED NULL,
    lead_traveller BOOLEAN NOT NULL DEFAULT FALSE,
    display_order SMALLINT UNSIGNED NOT NULL,
    title_snapshot VARCHAR(30) NULL,
    first_name_snapshot VARCHAR(100) NOT NULL,
    last_name_snapshot VARCHAR(100) NOT NULL,
    date_of_birth_snapshot DATE NULL,
    traveller_type VARCHAR(20) NOT NULL,
    relationship_label_snapshot VARCHAR(80) NULL,
    assistance_notes_snapshot TEXT NULL,
    dietary_notes_snapshot TEXT NULL,
    created_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    -- NULL for non-leads permits many companions; 1 permits at most one lead per booking.
    lead_slot TINYINT GENERATED ALWAYS AS (CASE WHEN lead_traveller = 1 THEN 1 ELSE NULL END) STORED,
    PRIMARY KEY (id),
    UNIQUE KEY uq_booking_travellers_lead (booking_id, lead_slot),
    KEY idx_booking_travellers_order (booking_id, display_order),
    KEY idx_booking_travellers_lead (booking_id, lead_traveller),
    KEY idx_booking_travellers_master (traveller_id),
    CONSTRAINT chk_booking_travellers_lead CHECK (lead_traveller IN (0,1)),
    CONSTRAINT chk_booking_travellers_order CHECK (display_order >= 0),
    CONSTRAINT chk_booking_travellers_type CHECK (traveller_type IN ('Adult','Child','Infant')),
    CONSTRAINT fk_booking_travellers_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_booking_travellers_master FOREIGN KEY (traveller_id) REFERENCES travellers(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

UPDATE installation_metadata SET schema_version='4.0.1-booking-travellers';
