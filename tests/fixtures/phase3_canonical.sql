-- Canonical MySQL 8 fresh-install schema for the Phase 1 foundation.
-- Domain tables are intentionally deferred until their features are designed.

CREATE TABLE schema_migrations (
    migration VARCHAR(255) NOT NULL PRIMARY KEY,
    applied_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Independent installation record: no foreign key dependency or delete cascade.
CREATE TABLE installation_metadata (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
    schema_version VARCHAR(64) NOT NULL,
    installed_at_utc TIMESTAMP(6) NOT NULL,
    UNIQUE KEY uq_installation_schema_version (schema_version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE organisations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    legal_name VARCHAR(200) NOT NULL,
    trading_name VARCHAR(200) NOT NULL,
    trading_status VARCHAR(32) NOT NULL DEFAULT 'active',
    address_line_1 VARCHAR(200) NULL,
    address_line_2 VARCHAR(200) NULL,
    city VARCHAR(100) NULL,
    county VARCHAR(100) NULL,
    postcode VARCHAR(20) NULL,
    country_code CHAR(2) NOT NULL DEFAULT 'GB',
    telephone VARCHAR(40) NULL,
    email VARCHAR(254) NULL,
    website VARCHAR(255) NULL,
    compliance_identifiers JSON NULL,
    default_currency CHAR(3) NOT NULL DEFAULT 'GBP',
    timezone VARCHAR(64) NOT NULL DEFAULT 'Europe/London',
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_by_user_id BIGINT UNSIGNED NULL,
    updated_by_user_id BIGINT UNSIGNED NULL,
    created_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    CONSTRAINT chk_organisations_trading_status CHECK (trading_status IN ('active', 'inactive'))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE locations (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organisation_id BIGINT UNSIGNED NOT NULL,
    name VARCHAR(150) NOT NULL,
    internal_code VARCHAR(40) NOT NULL,
    address_line_1 VARCHAR(200) NULL,
    address_line_2 VARCHAR(200) NULL,
    city VARCHAR(100) NULL,
    county VARCHAR(100) NULL,
    postcode VARCHAR(20) NULL,
    country_code CHAR(2) NOT NULL DEFAULT 'GB',
    telephone VARCHAR(40) NULL,
    email VARCHAR(254) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_locations_org_code (organisation_id, internal_code),
    CONSTRAINT fk_locations_organisation FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE users (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organisation_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(254) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    agent_code VARCHAR(40) NOT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    last_login_at_utc TIMESTAMP(6) NULL,
    created_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email),
    UNIQUE KEY uq_users_org_agent_code (organisation_id, agent_code),
    KEY idx_users_location (location_id),
    CONSTRAINT fk_users_organisation FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_users_location FOREIGN KEY (location_id) REFERENCES locations (id) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

ALTER TABLE organisations
    ADD KEY idx_organisations_created_by (created_by_user_id),
    ADD KEY idx_organisations_updated_by (updated_by_user_id),
    ADD CONSTRAINT fk_organisations_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE RESTRICT,
    ADD CONSTRAINT fk_organisations_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE RESTRICT;

CREATE TABLE permissions (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    permission_key VARCHAR(100) NOT NULL,
    description VARCHAR(255) NOT NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_permissions_key (permission_key)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE roles (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organisation_id BIGINT UNSIGNED NULL,
    name VARCHAR(100) NOT NULL,
    authority_level SMALLINT UNSIGNED NOT NULL,
    is_system BOOLEAN NOT NULL DEFAULT FALSE,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_roles_org_name (organisation_id, name),
    KEY idx_roles_organisation (organisation_id),
    CONSTRAINT fk_roles_organisation FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE role_permissions (
    role_id BIGINT UNSIGNED NOT NULL,
    permission_id BIGINT UNSIGNED NOT NULL,
    PRIMARY KEY (role_id, permission_id),
    KEY idx_role_permissions_permission (permission_id),
    CONSTRAINT fk_role_permissions_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT fk_role_permissions_permission FOREIGN KEY (permission_id) REFERENCES permissions (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE user_roles (
    user_id BIGINT UNSIGNED NOT NULL,
    role_id BIGINT UNSIGNED NOT NULL,
    assigned_by_user_id BIGINT UNSIGNED NULL,
    assigned_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (user_id, role_id),
    KEY idx_user_roles_role (role_id),
    KEY idx_user_roles_assigned_by (assigned_by_user_id),
    CONSTRAINT fk_user_roles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE RESTRICT,
    CONSTRAINT fk_user_roles_role FOREIGN KEY (role_id) REFERENCES roles (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_user_roles_assigned_by FOREIGN KEY (assigned_by_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE consultant_profiles (
    user_id BIGINT UNSIGNED NOT NULL,
    display_name VARCHAR(150) NOT NULL,
    title VARCHAR(120) NULL,
    biography TEXT NULL,
    media_reference VARCHAR(255) NULL,
    phone VARCHAR(40) NULL,
    email VARCHAR(254) NULL,
    is_public BOOLEAN NOT NULL DEFAULT FALSE,
    specialist_areas JSON NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (user_id),
    CONSTRAINT fk_consultant_profiles_user FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE customers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organisation_id BIGINT UNSIGNED NOT NULL,
    owning_location_id BIGINT UNSIGNED NULL,
    owning_agent_id BIGINT UNSIGNED NULL,
    title VARCHAR(30) NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    email VARCHAR(254) NULL,
    phone VARCHAR(40) NULL,
    alternate_phone VARCHAR(40) NULL,
    address_line_1 VARCHAR(200) NULL,
    address_line_2 VARCHAR(200) NULL,
    city VARCHAR(100) NULL,
    county VARCHAR(100) NULL,
    postcode VARCHAR(20) NULL,
    country_code CHAR(2) NOT NULL DEFAULT 'GB',
    date_of_birth DATE NULL,
    preferred_contact_method VARCHAR(20) NULL,
    marketing_consent BOOLEAN NOT NULL DEFAULT FALSE,
    marketing_consent_at_utc TIMESTAMP(6) NULL,
    notes TEXT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    archived_at_utc TIMESTAMP(6) NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    updated_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_customers_scope (organisation_id, owning_location_id, owning_agent_id, is_active),
    KEY idx_customers_email (organisation_id, email),
    KEY idx_customers_phone (organisation_id, phone),
    KEY idx_customers_name (organisation_id, last_name, first_name),
    KEY idx_customers_agent (owning_agent_id),
    KEY idx_customers_created_by (created_by_user_id),
    KEY idx_customers_updated_by (updated_by_user_id),
    CONSTRAINT fk_customers_organisation FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_customers_location FOREIGN KEY (owning_location_id) REFERENCES locations (id) ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT fk_customers_agent FOREIGN KEY (owning_agent_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT fk_customers_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_customers_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE travellers (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    customer_id BIGINT UNSIGNED NOT NULL,
    title VARCHAR(30) NULL,
    first_name VARCHAR(100) NOT NULL,
    last_name VARCHAR(100) NOT NULL,
    date_of_birth DATE NULL,
    relationship_label VARCHAR(80) NULL,
    accessibility_notes TEXT NULL,
    dietary_notes TEXT NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    archived_at_utc TIMESTAMP(6) NULL,
    created_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_travellers_customer (customer_id, is_active),
    CONSTRAINT fk_travellers_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE enquiries (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    reference VARCHAR(32) NOT NULL,
    organisation_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    assigned_location_id BIGINT UNSIGNED NULL,
    assigned_agent_id BIGINT UNSIGNED NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'New',
    product_type VARCHAR(80) NOT NULL,
    departure_point VARCHAR(150) NULL,
    destinations JSON NOT NULL,
    preferred_start_date DATE NULL,
    preferred_end_date DATE NULL,
    flexibility VARCHAR(120) NULL,
    duration_nights SMALLINT UNSIGNED NULL,
    adults SMALLINT UNSIGNED NOT NULL DEFAULT 1,
    children SMALLINT UNSIGNED NOT NULL DEFAULT 0,
    children_ages JSON NULL,
    budget_amount DECIMAL(13,2) NULL,
    budget_currency CHAR(3) NOT NULL DEFAULT 'GBP',
    must_haves TEXT NULL,
    accessibility_requirements TEXT NULL,
    special_occasions TEXT NULL,
    preferences TEXT NULL,
    additional_notes TEXT NULL,
    preferred_contact_method VARCHAR(20) NULL,
    lead_source VARCHAR(100) NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    updated_by_user_id BIGINT UNSIGNED NOT NULL,
    created_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    UNIQUE KEY uq_enquiries_reference (reference),
    KEY idx_enquiries_workspace (organisation_id, status, assigned_location_id, assigned_agent_id),
    KEY idx_enquiries_customer (customer_id),
    KEY idx_enquiries_agent (assigned_agent_id),
    KEY idx_enquiries_created_by (created_by_user_id),
    KEY idx_enquiries_updated_by (updated_by_user_id),
    CONSTRAINT chk_enquiries_status CHECK (status IN ('New', 'Contacted', 'Quoted', 'Booked', 'Lost', 'Closed')),
    CONSTRAINT chk_enquiries_party CHECK (adults >= 1 AND children >= 0),
    CONSTRAINT fk_enquiries_organisation FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_enquiries_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_enquiries_location FOREIGN KEY (assigned_location_id) REFERENCES locations (id) ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT fk_enquiries_agent FOREIGN KEY (assigned_agent_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT fk_enquiries_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_enquiries_updated_by FOREIGN KEY (updated_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE enquiry_assignment_history (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    enquiry_id BIGINT UNSIGNED NOT NULL,
    from_location_id BIGINT UNSIGNED NULL,
    to_location_id BIGINT UNSIGNED NULL,
    from_agent_id BIGINT UNSIGNED NULL,
    to_agent_id BIGINT UNSIGNED NULL,
    assigned_by_user_id BIGINT UNSIGNED NOT NULL,
    assigned_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_assignment_history_enquiry (enquiry_id, assigned_at_utc),
    KEY idx_assignment_history_from_location (from_location_id),
    KEY idx_assignment_history_to_location (to_location_id),
    KEY idx_assignment_history_from_agent (from_agent_id),
    KEY idx_assignment_history_to_agent (to_agent_id),
    KEY idx_assignment_history_actor (assigned_by_user_id),
    CONSTRAINT fk_assignment_history_enquiry FOREIGN KEY (enquiry_id) REFERENCES enquiries (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_assignment_history_from_location FOREIGN KEY (from_location_id) REFERENCES locations (id) ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT fk_assignment_history_to_location FOREIGN KEY (to_location_id) REFERENCES locations (id) ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT fk_assignment_history_from_agent FOREIGN KEY (from_agent_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT fk_assignment_history_to_agent FOREIGN KEY (to_agent_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT fk_assignment_history_actor FOREIGN KEY (assigned_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE tasks (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organisation_id BIGINT UNSIGNED NOT NULL,
    location_id BIGINT UNSIGNED NULL,
    assigned_user_id BIGINT UNSIGNED NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    related_entity_type VARCHAR(30) NULL,
    related_entity_id BIGINT UNSIGNED NULL,
    title VARCHAR(200) NOT NULL,
    description TEXT NULL,
    due_at_utc TIMESTAMP(6) NULL,
    priority VARCHAR(20) NOT NULL DEFAULT 'Normal',
    status VARCHAR(20) NOT NULL DEFAULT 'Open',
    completed_at_utc TIMESTAMP(6) NULL,
    created_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_tasks_worklist (organisation_id, status, due_at_utc, assigned_user_id),
    KEY idx_tasks_location (location_id),
    KEY idx_tasks_created_by (created_by_user_id),
    KEY idx_tasks_related (related_entity_type, related_entity_id),
    CONSTRAINT chk_tasks_status CHECK (status IN ('Open', 'In Progress', 'Completed', 'Cancelled')),
    CONSTRAINT chk_tasks_priority CHECK (priority IN ('Low', 'Normal', 'High', 'Urgent')),
    CONSTRAINT fk_tasks_organisation FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_tasks_location FOREIGN KEY (location_id) REFERENCES locations (id) ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT fk_tasks_assigned_user FOREIGN KEY (assigned_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT fk_tasks_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE communications (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organisation_id BIGINT UNSIGNED NOT NULL,
    customer_id BIGINT UNSIGNED NOT NULL,
    enquiry_id BIGINT UNSIGNED NULL,
    direction VARCHAR(20) NOT NULL,
    communication_type VARCHAR(20) NOT NULL,
    subject VARCHAR(200) NULL,
    body TEXT NOT NULL,
    occurred_at_utc TIMESTAMP(6) NOT NULL,
    created_by_user_id BIGINT UNSIGNED NOT NULL,
    is_internal BOOLEAN NOT NULL DEFAULT TRUE,
    created_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_communications_customer (customer_id, occurred_at_utc),
    KEY idx_communications_enquiry (enquiry_id, occurred_at_utc),
    KEY idx_communications_organisation (organisation_id),
    KEY idx_communications_created_by (created_by_user_id),
    CONSTRAINT chk_communications_direction CHECK (direction IN ('Inbound', 'Outbound', 'Internal')),
    CONSTRAINT chk_communications_type CHECK (communication_type IN ('Email', 'Phone', 'SMS', 'WhatsApp', 'In person', 'Other')),
    CONSTRAINT fk_communications_organisation FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_communications_customer FOREIGN KEY (customer_id) REFERENCES customers (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_communications_enquiry FOREIGN KEY (enquiry_id) REFERENCES enquiries (id) ON DELETE SET NULL ON UPDATE RESTRICT,
    CONSTRAINT fk_communications_created_by FOREIGN KEY (created_by_user_id) REFERENCES users (id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE audit_events (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    organisation_id BIGINT UNSIGNED NULL,
    actor_user_id BIGINT UNSIGNED NULL,
    environment VARCHAR(32) NOT NULL,
    action VARCHAR(100) NOT NULL,
    entity_type VARCHAR(80) NOT NULL,
    entity_id VARCHAR(64) NULL,
    request_id VARCHAR(64) NULL,
    before_summary JSON NULL,
    after_summary JSON NULL,
    occurred_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_audit_entity (organisation_id, entity_type, entity_id, occurred_at_utc),
    KEY idx_audit_actor (actor_user_id, occurred_at_utc),
    CONSTRAINT fk_audit_organisation FOREIGN KEY (organisation_id) REFERENCES organisations (id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_audit_actor FOREIGN KEY (actor_user_id) REFERENCES users (id) ON DELETE SET NULL ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

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
('organisation.view', 'View organisation settings'), ('organisation.manage', 'Manage organisation settings'),
('locations.view', 'View locations'), ('locations.manage', 'Manage locations'),
('users.view', 'View users'), ('users.manage', 'Manage users'),
('roles.view', 'View roles and permissions'), ('roles.manage', 'Manage roles and permissions'),
('customers.view', 'View customers'), ('customers.create', 'Create customers'),
('customers.edit', 'Edit customers'), ('customers.archive', 'Archive customers'),
('enquiries.view', 'View enquiries'), ('enquiries.create', 'Create enquiries'),
('enquiries.edit', 'Edit enquiries'), ('enquiries.assign', 'Assign enquiries'),
('enquiries.close', 'Close enquiries'), ('tasks.view', 'View tasks'),
('tasks.create', 'Create tasks'), ('tasks.edit', 'Edit tasks'),
('communications.view', 'View communications'), ('communications.create', 'Log communications'),
('audit.view', 'View audit events'),
('quotes.view', 'View quotes'), ('quotes.create', 'Create quotes'), ('quotes.edit', 'Edit draft quotes'),
('quotes.send', 'Generate and record sent proposals'), ('quotes.accept', 'Record quote acceptance'),
('quotes.decline', 'Record quote decline or expiry'), ('quotes.adjust', 'Apply governed price adjustments'),
('quotes.compliance', 'Perform quote compliance review'), ('quotes.convert_ready', 'Generate a booking handoff contract'),
('scope.own', 'Access records assigned to the current user'),
('scope.location', 'Access records in the current authorised location'),
('scope.organisation', 'Access records throughout the current organisation');

INSERT INTO roles (organisation_id, name, authority_level, is_system) VALUES
(NULL, 'System Administrator', 100, TRUE),
(NULL, 'Organisation Administrator', 80, TRUE),
(NULL, 'Manager', 60, TRUE),
(NULL, 'Agent', 40, TRUE),
(NULL, 'Read Only', 10, TRUE);

INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p WHERE r.name = 'System Administrator';
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'Organisation Administrator' AND p.permission_key <> 'roles.manage';
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'Manager' AND p.permission_key NOT IN ('organisation.manage', 'users.manage', 'roles.manage', 'scope.organisation');
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'Agent' AND p.permission_key IN ('customers.view', 'customers.create', 'customers.edit', 'enquiries.view', 'enquiries.create', 'enquiries.edit', 'tasks.view', 'tasks.create', 'tasks.edit', 'communications.view', 'communications.create', 'quotes.view', 'quotes.create', 'quotes.edit', 'quotes.send', 'quotes.accept', 'quotes.decline', 'quotes.adjust', 'quotes.compliance', 'quotes.convert_ready', 'scope.own');
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id, p.id FROM roles r CROSS JOIN permissions p
WHERE r.name = 'Read Only' AND p.permission_key IN ('organisation.view', 'locations.view', 'users.view', 'roles.view', 'customers.view', 'enquiries.view', 'tasks.view', 'communications.view', 'scope.location');

-- Fresh installs already contain the Phase 2 release shape; prevent replay.
INSERT INTO schema_migrations (migration) VALUES ('20260904_200000_phase2_core_crm.sql');
INSERT INTO schema_migrations (migration) VALUES ('20260905_120000_phase3_quote_engine.sql');
