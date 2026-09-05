CREATE TABLE booking_documents (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id BIGINT UNSIGNED NOT NULL, booking_element_id BIGINT UNSIGNED NULL,
    document_type VARCHAR(80) NOT NULL, original_filename VARCHAR(255) NOT NULL,
    storage_reference VARCHAR(255) NOT NULL, mime_type VARCHAR(150) NOT NULL,
    visibility VARCHAR(20) NOT NULL DEFAULT 'Agent only', notes TEXT NULL,
    uploaded_by_user_id BIGINT UNSIGNED NOT NULL, uploaded_at_utc TIMESTAMP(6) NOT NULL,
    created_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6), PRIMARY KEY (id),
    KEY idx_documents_element_scope (booking_id, booking_element_id),
    KEY idx_documents_element (booking_element_id), KEY idx_documents_visibility (booking_id, visibility),
    KEY idx_documents_type (document_type), KEY idx_documents_uploader (uploaded_by_user_id), KEY idx_documents_uploaded (uploaded_at_utc),
    CONSTRAINT chk_documents_visibility CHECK (visibility IN ('Agent only','Customer visible')),
    CONSTRAINT chk_documents_storage CHECK (CHAR_LENGTH(TRIM(storage_reference)) > 0),
    CONSTRAINT fk_documents_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_documents_element FOREIGN KEY (booking_id, booking_element_id) REFERENCES booking_elements(booking_id, id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_documents_uploader FOREIGN KEY (uploaded_by_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE booking_checklist_templates (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    template_code VARCHAR(80) NOT NULL, template_name VARCHAR(200) NOT NULL,
    booking_status_context VARCHAR(20) NULL, active BOOLEAN NOT NULL DEFAULT TRUE,
    created_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6), PRIMARY KEY (id), UNIQUE KEY uq_checklist_template_code (template_code),
    CONSTRAINT chk_checklist_template_active CHECK (active IN (0,1))
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE booking_checklist_template_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    template_id BIGINT UNSIGNED NOT NULL, item_code VARCHAR(80) NOT NULL,
    title VARCHAR(200) NOT NULL, category VARCHAR(80) NOT NULL,
    default_due_offset_days SMALLINT NULL, display_order SMALLINT UNSIGNED NOT NULL,
    active BOOLEAN NOT NULL DEFAULT TRUE, created_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6), PRIMARY KEY (id),
    UNIQUE KEY uq_checklist_template_item (template_id,item_code), KEY idx_template_items_order (template_id,display_order),
    CONSTRAINT chk_template_item_order CHECK (display_order >= 0), CONSTRAINT chk_template_item_active CHECK (active IN (0,1)),
    CONSTRAINT fk_template_item_template FOREIGN KEY (template_id) REFERENCES booking_checklist_templates(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE booking_checklist_items (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id BIGINT UNSIGNED NOT NULL, template_item_id BIGINT UNSIGNED NULL,
    item_code VARCHAR(80) NOT NULL, title VARCHAR(200) NOT NULL, category VARCHAR(80) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Open', due_at_utc TIMESTAMP(6) NULL,
    assigned_user_id BIGINT UNSIGNED NULL, notes TEXT NULL,
    completed_at_utc TIMESTAMP(6) NULL, completed_by_user_id BIGINT UNSIGNED NULL,
    created_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6), PRIMARY KEY (id), UNIQUE KEY uq_booking_checklist_code (booking_id,item_code),
    KEY idx_checklist_status (booking_id,status), KEY idx_checklist_due (due_at_utc),
    KEY idx_checklist_assignee (assigned_user_id), KEY idx_checklist_template (template_item_id), KEY idx_checklist_completer (completed_by_user_id),
    CONSTRAINT chk_checklist_status CHECK (status IN ('Open','In Progress','Completed','Cancelled')),
    CONSTRAINT chk_checklist_completion CHECK (status <> 'Completed' OR (completed_at_utc IS NOT NULL AND completed_by_user_id IS NOT NULL)),
    CONSTRAINT fk_checklist_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_checklist_template FOREIGN KEY (template_item_id) REFERENCES booking_checklist_template_items(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_checklist_assignee FOREIGN KEY (assigned_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_checklist_completer FOREIGN KEY (completed_by_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE booking_amendments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id BIGINT UNSIGNED NOT NULL, amendment_sequence INT UNSIGNED NOT NULL,
    reason TEXT NOT NULL, description TEXT NOT NULL, before_summary JSON NOT NULL, after_summary JSON NOT NULL,
    financial_effect_amount DECIMAL(13,2) NOT NULL DEFAULT 0.00,
    financial_effect_direction VARCHAR(10) NOT NULL DEFAULT 'None',
    customer_effect_summary TEXT NULL, supplier_reference VARCHAR(100) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Draft', created_by_user_id BIGINT UNSIGNED NOT NULL,
    approved_by_user_id BIGINT UNSIGNED NULL, approved_at_utc TIMESTAMP(6) NULL,
    created_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6), PRIMARY KEY (id), UNIQUE KEY uq_booking_amendment_sequence (booking_id,amendment_sequence),
    KEY idx_amendment_status (booking_id,status), KEY idx_amendment_creator (created_by_user_id), KEY idx_amendment_approver (approved_by_user_id),
    CONSTRAINT chk_amendment_sequence CHECK (amendment_sequence > 0),
    CONSTRAINT chk_amendment_money CHECK ((financial_effect_amount=0 AND financial_effect_direction='None') OR (financial_effect_amount>0 AND financial_effect_direction IN ('Increase','Decrease'))),
    CONSTRAINT chk_amendment_status CHECK (status IN ('Draft','Pending','Approved','Rejected','Applied')),
    CONSTRAINT chk_amendment_decision CHECK (status NOT IN ('Approved','Rejected','Applied') OR (approved_by_user_id IS NOT NULL AND approved_at_utc IS NOT NULL)),
    CONSTRAINT fk_amendment_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_amendment_creator FOREIGN KEY (created_by_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_amendment_approver FOREIGN KEY (approved_by_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

INSERT INTO booking_checklist_templates (template_code,template_name,booking_status_context) VALUES ('BOOKED_DEFAULT','Default booked checklist','Booked');
INSERT INTO booking_checklist_template_items (template_id,item_code,title,category,display_order)
SELECT id,'SUPPLIER_CONFIRMATION','Supplier confirmation checked','Supplier',0 FROM booking_checklist_templates WHERE template_code='BOOKED_DEFAULT';
INSERT INTO booking_checklist_template_items (template_id,item_code,title,category,display_order)
SELECT id,'CUSTOMER_DOCUMENTS','Customer confirmation/documents sent','Documents',1 FROM booking_checklist_templates WHERE template_code='BOOKED_DEFAULT';
INSERT INTO booking_checklist_template_items (template_id,item_code,title,category,display_order)
SELECT id,'PAYMENT_SCHEDULE','Payment schedule checked','Finance',2 FROM booking_checklist_templates WHERE template_code='BOOKED_DEFAULT';
INSERT INTO booking_checklist_template_items (template_id,item_code,title,category,display_order)
SELECT id,'FINAL_BALANCE','Final balance due date checked','Finance',3 FROM booking_checklist_templates WHERE template_code='BOOKED_DEFAULT';
INSERT INTO permissions (permission_key,description) VALUES ('bookings.documents','Manage booking document metadata'),('bookings.checklist','Manage booking checklists'),('bookings.amend','Manage booking amendments');
INSERT INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.name IN ('System Administrator','Organisation Administrator','Manager','Agent') AND p.permission_key IN ('bookings.documents','bookings.checklist','bookings.amend');

UPDATE installation_metadata SET schema_version='4.0.6-booking-operations';
