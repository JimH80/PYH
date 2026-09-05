CREATE TABLE bookings (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_reference VARCHAR(32) NOT NULL,
    organisation_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NULL,
    assigned_user_id BIGINT UNSIGNED NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    quote_id BIGINT UNSIGNED NULL,
    quote_booking_handoff_id BIGINT UNSIGNED NULL,
    product_type VARCHAR(80) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Booked',
    supplier_name VARCHAR(200) NULL,
    supplier_reference VARCHAR(100) NULL,
    supplier_booking_reference VARCHAR(100) NULL,
    booked_date DATE NOT NULL,
    departure_date DATE NULL,
    return_date DATE NULL,
    currency CHAR(3) NOT NULL DEFAULT 'GBP',
    core_selling_price DECIMAL(13,2) NOT NULL DEFAULT 0.00,
    supplier_cost DECIMAL(13,2) NOT NULL DEFAULT 0.00,
    commission DECIMAL(13,2) NOT NULL DEFAULT 0.00,
    deposit DECIMAL(13,2) NOT NULL DEFAULT 0.00,
    final_balance_due DATE NULL,
    internal_notes TEXT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    updated_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_bookings_reference (booking_reference),
    KEY idx_bookings_scope (organisation_id, status, location_id, assigned_user_id),
    KEY idx_bookings_location (location_id),
    KEY idx_bookings_assigned_user (assigned_user_id),
    KEY idx_bookings_customer (customer_id),
    KEY idx_bookings_quote (quote_id),
    KEY idx_bookings_handoff (quote_booking_handoff_id),
    KEY idx_bookings_created_by (created_by_user_id),
    KEY idx_bookings_updated_by (updated_by_user_id),
    CONSTRAINT chk_bookings_status CHECK (status IN ('Booked','Amended','Cancelled','Travelled','Returned')),
    CONSTRAINT chk_bookings_money CHECK (core_selling_price >= 0 AND supplier_cost >= 0 AND commission >= 0 AND deposit >= 0),
    CONSTRAINT fk_bookings_org FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_bookings_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_bookings_assigned_user FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_bookings_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_bookings_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_bookings_handoff FOREIGN KEY (quote_booking_handoff_id) REFERENCES quote_booking_handoffs(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_bookings_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_bookings_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO permissions (permission_key, description) VALUES
('bookings.view', 'View bookings'), ('bookings.create', 'Create bookings'), ('bookings.edit', 'Edit bookings');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name IN ('System Administrator','Organisation Administrator','Manager','Agent')
AND p.permission_key IN ('bookings.view','bookings.create','bookings.edit');
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name='Read Only' AND p.permission_key='bookings.view';

UPDATE installation_metadata SET schema_version='4.0.0-bookings-foundation';
