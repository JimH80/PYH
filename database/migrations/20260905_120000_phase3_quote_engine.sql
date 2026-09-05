CREATE TABLE quotes (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, reference VARCHAR(32) NOT NULL,
    organisation_id BIGINT UNSIGNED NOT NULL, location_id BIGINT UNSIGNED NULL, assigned_agent_id BIGINT UNSIGNED NULL,
    customer_id BIGINT UNSIGNED NOT NULL, enquiry_id BIGINT UNSIGNED NULL,
    product_type VARCHAR(80) NOT NULL, title VARCHAR(200) NOT NULL, status VARCHAR(20) NOT NULL DEFAULT 'Draft', currency CHAR(3) NOT NULL DEFAULT 'GBP',
    destination_summary VARCHAR(255) NULL, departure_date DATE NULL, return_date DATE NULL, duration_nights SMALLINT UNSIGNED NULL,
    departure_point VARCHAR(150) NULL, expires_at_utc TIMESTAMP(6) NULL, customer_introduction TEXT NULL, customer_notes TEXT NULL, internal_notes TEXT NULL,
    revision_number SMALLINT UNSIGNED NOT NULL DEFAULT 1, included_total DECIMAL(13,2) NOT NULL DEFAULT 0.00, adjustments_total DECIMAL(13,2) NOT NULL DEFAULT 0.00,
    customer_total DECIMAL(13,2) NOT NULL DEFAULT 0.00, optional_total DECIMAL(13,2) NOT NULL DEFAULT 0.00, deposit_amount DECIMAL(13,2) NOT NULL DEFAULT 0.00,
    balance_amount DECIMAL(13,2) NOT NULL DEFAULT 0.00, balance_due_date DATE NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL, updated_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), updated_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id), UNIQUE KEY uq_quotes_reference (reference), KEY idx_quotes_scope (organisation_id, status, location_id, assigned_agent_id),
    KEY idx_quotes_customer (customer_id), KEY idx_quotes_enquiry (enquiry_id), KEY idx_quotes_location (location_id), KEY idx_quotes_agent (assigned_agent_id),
    KEY idx_quotes_created_by (created_by_user_id), KEY idx_quotes_updated_by (updated_by_user_id),
    CONSTRAINT chk_quotes_status CHECK (status IN ('Draft','Ready','Sent','Accepted','Declined','Expired','Superseded','Converted')),
    CONSTRAINT chk_quotes_money CHECK (customer_total >= 0 AND deposit_amount >= 0 AND balance_amount >= 0),
    CONSTRAINT fk_quotes_org FOREIGN KEY (organisation_id) REFERENCES organisations(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_quotes_location FOREIGN KEY (location_id) REFERENCES locations(id) ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT fk_quotes_agent FOREIGN KEY (assigned_agent_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT fk_quotes_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_quotes_enquiry FOREIGN KEY (enquiry_id) REFERENCES enquiries(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_quotes_created_by FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_quotes_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE quote_travellers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, quote_id BIGINT UNSIGNED NOT NULL, traveller_id BIGINT UNSIGNED NOT NULL,
    display_order SMALLINT UNSIGNED NOT NULL, traveller_type VARCHAR(20) NOT NULL, is_lead BOOLEAN NOT NULL DEFAULT FALSE,
    title_snapshot VARCHAR(30) NULL, first_name_snapshot VARCHAR(100) NOT NULL, last_name_snapshot VARCHAR(100) NOT NULL, date_of_birth_snapshot DATE NULL, proposal_notes TEXT NULL,
    created_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), PRIMARY KEY(id), UNIQUE KEY uq_quote_traveller (quote_id, traveller_id),
    KEY idx_quote_travellers_traveller (traveller_id), CONSTRAINT chk_quote_traveller_type CHECK (traveller_type IN ('Adult','Child','Infant')),
    CONSTRAINT fk_quote_travellers_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT fk_quote_travellers_master FOREIGN KEY (traveller_id) REFERENCES travellers(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE quote_components (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, quote_id BIGINT UNSIGNED NOT NULL, component_type VARCHAR(30) NOT NULL, display_order SMALLINT UNSIGNED NOT NULL,
    title VARCHAR(200) NOT NULL, supplier VARCHAR(200) NULL, supplier_reference VARCHAR(100) NULL, start_at_utc DATETIME(6) NULL, end_at_utc DATETIME(6) NULL,
    origin VARCHAR(150) NULL, destination VARCHAR(150) NULL, customer_description TEXT NULL, customer_notes TEXT NULL, internal_notes TEXT NULL,
    inclusion_state VARCHAR(20) NOT NULL DEFAULT 'Included', is_selected BOOLEAN NOT NULL DEFAULT FALSE,
    selling_price DECIMAL(13,2) NOT NULL DEFAULT 0.00, supplier_cost DECIMAL(13,2) NOT NULL DEFAULT 0.00, commission_amount DECIMAL(13,2) NOT NULL DEFAULT 0.00,
    created_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), updated_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY(id), UNIQUE KEY uq_quote_component_order (quote_id, display_order), KEY idx_components_timeline (quote_id, start_at_utc),
    CONSTRAINT chk_component_inclusion CHECK (inclusion_state IN ('Included','Optional','Recommended')),
    CONSTRAINT chk_component_money CHECK (selling_price >= 0 AND supplier_cost >= 0 AND commission_amount >= 0),
    CONSTRAINT fk_components_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE quote_flight_sectors (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, component_id BIGINT UNSIGNED NOT NULL, journey_direction VARCHAR(20) NOT NULL, sector_order SMALLINT UNSIGNED NOT NULL,
    airline VARCHAR(150) NOT NULL, flight_number VARCHAR(20) NULL, departure_airport VARCHAR(100) NOT NULL, arrival_airport VARCHAR(100) NOT NULL,
    departure_at_utc DATETIME(6) NOT NULL, arrival_at_utc DATETIME(6) NOT NULL, cabin_class VARCHAR(80) NULL, baggage VARCHAR(150) NULL, connection_notes TEXT NULL, duration_minutes SMALLINT UNSIGNED NULL,
    PRIMARY KEY(id), UNIQUE KEY uq_flight_sector_order (component_id, journey_direction, sector_order),
    CONSTRAINT chk_flight_direction CHECK (journey_direction IN ('Outbound','Inbound','Other')),
    CONSTRAINT fk_flight_sector_component FOREIGN KEY (component_id) REFERENCES quote_components(id) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE quote_accommodation_details (
    component_id BIGINT UNSIGNED NOT NULL, property_name VARCHAR(200) NOT NULL, resort VARCHAR(150) NULL, check_in_date DATE NOT NULL, check_out_date DATE NOT NULL,
    nights SMALLINT UNSIGNED NOT NULL, room_type VARCHAR(120) NULL, board_basis VARCHAR(100) NULL, occupancy VARCHAR(100) NULL, room_count SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    included_features JSON NULL, PRIMARY KEY(component_id), CONSTRAINT fk_accommodation_component FOREIGN KEY (component_id) REFERENCES quote_components(id) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE quote_cruise_details (
    component_id BIGINT UNSIGNED NOT NULL, cruise_line VARCHAR(150) NOT NULL, ship VARCHAR(150) NOT NULL, sailing_date DATE NOT NULL, duration_nights SMALLINT UNSIGNED NOT NULL,
    embarkation_port VARCHAR(150) NOT NULL, disembarkation_port VARCHAR(150) NOT NULL, itinerary_summary TEXT NULL, cabin_category VARCHAR(120) NULL, cabin_type VARCHAR(120) NULL,
    experience_notes TEXT NULL, inclusions JSON NULL, PRIMARY KEY(component_id), CONSTRAINT fk_cruise_component FOREIGN KEY (component_id) REFERENCES quote_components(id) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE quote_adjustments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, quote_id BIGINT UNSIGNED NOT NULL, adjustment_type VARCHAR(30) NOT NULL, amount DECIMAL(13,2) NOT NULL,
    reason VARCHAR(500) NOT NULL, visibility VARCHAR(20) NOT NULL, created_by_user_id BIGINT UNSIGNED NOT NULL, created_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY(id), KEY idx_adjustments_quote (quote_id), KEY idx_adjustments_actor (created_by_user_id),
    CONSTRAINT chk_adjustment_type CHECK (adjustment_type IN ('Discount','Fee','Price Correction','Other')),
    CONSTRAINT chk_adjustment_visibility CHECK (visibility IN ('Customer','Internal')),
    CONSTRAINT fk_adjustments_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_adjustments_actor FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE quote_compliance_reviews (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, quote_id BIGINT UNSIGNED NOT NULL, result VARCHAR(10) NOT NULL, reasons JSON NOT NULL, evidence JSON NOT NULL,
    source VARCHAR(20) NOT NULL, reviewed_by_user_id BIGINT UNSIGNED NULL, reviewed_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), PRIMARY KEY(id),
    KEY idx_compliance_quote (quote_id, reviewed_at_utc), KEY idx_compliance_actor (reviewed_by_user_id),
    CONSTRAINT chk_compliance_result CHECK (result IN ('Pass','Warning','Block')),
    CONSTRAINT fk_compliance_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_compliance_actor FOREIGN KEY (reviewed_by_user_id) REFERENCES users(id) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE proposal_versions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, quote_id BIGINT UNSIGNED NOT NULL, version_number SMALLINT UNSIGNED NOT NULL, snapshot JSON NOT NULL, rendered_html LONGTEXT NOT NULL,
    snapshot_sha256 CHAR(64) NOT NULL, generated_by_user_id BIGINT UNSIGNED NOT NULL, generated_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), finalised_at_utc TIMESTAMP(6) NULL,
    PRIMARY KEY(id), UNIQUE KEY uq_proposal_version (quote_id, version_number), KEY idx_proposal_actor (generated_by_user_id),
    CONSTRAINT fk_proposal_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_proposal_actor FOREIGN KEY (generated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE proposal_deliveries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, proposal_version_id BIGINT UNSIGNED NOT NULL, method VARCHAR(30) NOT NULL, recipient_display VARCHAR(254) NULL,
    delivery_status VARCHAR(30) NOT NULL, delivery_note VARCHAR(500) NULL, sent_by_user_id BIGINT UNSIGNED NOT NULL, sent_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), PRIMARY KEY(id),
    KEY idx_delivery_proposal (proposal_version_id, sent_at_utc), KEY idx_delivery_actor (sent_by_user_id),
    CONSTRAINT chk_delivery_method CHECK (method IN ('Email','Customer Portal','In Person','Other')),
    CONSTRAINT fk_delivery_proposal FOREIGN KEY (proposal_version_id) REFERENCES proposal_versions(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_delivery_actor FOREIGN KEY (sent_by_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE quote_decisions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, quote_id BIGINT UNSIGNED NOT NULL, proposal_version_id BIGINT UNSIGNED NOT NULL, decision VARCHAR(20) NOT NULL,
    evidence_note VARCHAR(1000) NULL, source VARCHAR(30) NOT NULL, recorded_by_user_id BIGINT UNSIGNED NOT NULL, decided_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), PRIMARY KEY(id),
    KEY idx_decisions_quote (quote_id, decided_at_utc), KEY idx_decisions_proposal (proposal_version_id), KEY idx_decisions_actor (recorded_by_user_id),
    CONSTRAINT chk_quote_decision CHECK (decision IN ('Accepted','Declined','Expired')),
    CONSTRAINT fk_decision_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_decision_proposal FOREIGN KEY (proposal_version_id) REFERENCES proposal_versions(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_decision_actor FOREIGN KEY (recorded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE quote_booking_handoffs (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT, quote_id BIGINT UNSIGNED NOT NULL, accepted_proposal_version_id BIGINT UNSIGNED NOT NULL, readiness_status VARCHAR(20) NOT NULL,
    blocking_reasons JSON NOT NULL, snapshot JSON NOT NULL, snapshot_sha256 CHAR(64) NOT NULL, generated_by_user_id BIGINT UNSIGNED NOT NULL,
    generated_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6), PRIMARY KEY(id), UNIQUE KEY uq_handoff_quote_version (quote_id, accepted_proposal_version_id),
    KEY idx_handoff_proposal (accepted_proposal_version_id), KEY idx_handoff_actor (generated_by_user_id),
    CONSTRAINT chk_handoff_readiness CHECK (readiness_status IN ('Ready','Blocked')),
    CONSTRAINT fk_handoff_quote FOREIGN KEY (quote_id) REFERENCES quotes(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_handoff_proposal FOREIGN KEY (accepted_proposal_version_id) REFERENCES proposal_versions(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_handoff_actor FOREIGN KEY (generated_by_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO permissions (permission_key, description) VALUES
('quotes.view', 'View quotes'), ('quotes.create', 'Create quotes'), ('quotes.edit', 'Edit draft quotes'),
('quotes.send', 'Generate and record sent proposals'), ('quotes.accept', 'Record quote acceptance'),
('quotes.decline', 'Record quote decline or expiry'), ('quotes.adjust', 'Apply governed price adjustments'),
('quotes.compliance', 'Perform quote compliance review'), ('quotes.convert_ready', 'Generate a booking handoff contract');

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name IN ('System Administrator','Organisation Administrator','Manager') AND p.permission_key LIKE 'quotes.%';
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name='Agent' AND p.permission_key LIKE 'quotes.%';

UPDATE installation_metadata SET schema_version='3.0.0-quote-engine';
