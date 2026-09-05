-- Supporting candidate key for the same-booking element foreign key.
ALTER TABLE booking_elements ADD UNIQUE KEY uq_booking_elements_booking_id (booking_id, id);

CREATE TABLE booking_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id BIGINT UNSIGNED NOT NULL,
    transaction_type VARCHAR(20) NOT NULL,
    amount DECIMAL(13,2) NOT NULL,
    payment_method VARCHAR(80) NULL,
    transaction_reference VARCHAR(100) NULL,
    processed_at_utc TIMESTAMP(6) NOT NULL,
    notes TEXT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_booking_payments_type (booking_id, transaction_type),
    KEY idx_booking_payments_processed (processed_at_utc),
    KEY idx_booking_payments_reference (transaction_reference),
    KEY idx_booking_payments_creator (created_by_user_id),
    CONSTRAINT chk_booking_payments_type CHECK (transaction_type IN ('Payment','Refund')),
    CONSTRAINT chk_booking_payments_amount CHECK (amount > 0),
    CONSTRAINT fk_booking_payments_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_booking_payments_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE booking_supplier_payments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id BIGINT UNSIGNED NOT NULL,
    booking_element_id BIGINT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Not Due',
    amount DECIMAL(13,2) NOT NULL,
    due_date DATE NULL,
    paid_date DATE NULL,
    payment_reference VARCHAR(100) NULL,
    notes TEXT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    updated_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_supplier_payments_booking_element (booking_id, booking_element_id),
    KEY idx_supplier_payments_element (booking_element_id),
    KEY idx_supplier_payments_due (status, due_date),
    KEY idx_supplier_payments_reference (payment_reference),
    KEY idx_supplier_payments_creator (created_by_user_id),
    KEY idx_supplier_payments_updater (updated_by_user_id),
    CONSTRAINT chk_supplier_payments_status CHECK (status IN ('Not Due','Due','Paid')),
    CONSTRAINT chk_supplier_payments_amount CHECK (amount >= 0),
    CONSTRAINT chk_supplier_payments_paid_date CHECK (paid_date IS NULL OR status = 'Paid'),
    CONSTRAINT fk_supplier_payments_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_supplier_payments_element FOREIGN KEY (booking_id, booking_element_id) REFERENCES booking_elements(booking_id, id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_supplier_payments_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_supplier_payments_updater FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

UPDATE installation_metadata SET schema_version='4.0.3-booking-payments';
