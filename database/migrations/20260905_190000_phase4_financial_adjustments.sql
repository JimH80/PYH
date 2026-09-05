CREATE TABLE booking_financial_adjustments (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    booking_id BIGINT UNSIGNED NOT NULL,
    adjustment_type VARCHAR(30) NOT NULL,
    amount DECIMAL(13,2) NOT NULL,
    direction VARCHAR(10) NOT NULL,
    reason TEXT NOT NULL,
    customer_visibility VARCHAR(20) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'Pending',
    submitted_by_user_id BIGINT UNSIGNED NOT NULL,
    submitted_at_utc TIMESTAMP(6) NOT NULL,
    current_decision_by_user_id BIGINT UNSIGNED NULL,
    current_decision_at_utc TIMESTAMP(6) NULL,
    created_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_adjustments_booking_status (booking_id, status),
    KEY idx_adjustments_type (adjustment_type),
    KEY idx_adjustments_submitter (submitted_by_user_id),
    KEY idx_adjustments_decider (current_decision_by_user_id),
    KEY idx_adjustments_submitted (submitted_at_utc),
    CONSTRAINT chk_financial_adjustment_type CHECK (adjustment_type IN ('Discount','Fee','Commission-Funded','Price Correction','Other')),
    CONSTRAINT chk_financial_adjustment_amount CHECK (amount > 0),
    CONSTRAINT chk_financial_adjustment_direction CHECK (direction IN ('Increase','Decrease') AND (adjustment_type NOT IN ('Discount','Commission-Funded') OR direction='Decrease') AND (adjustment_type <> 'Fee' OR direction='Increase')),
    CONSTRAINT chk_financial_adjustment_reason CHECK (CHAR_LENGTH(TRIM(reason)) > 0),
    CONSTRAINT chk_financial_adjustment_visibility CHECK (customer_visibility IN ('Customer Visible','Internal')),
    CONSTRAINT chk_financial_adjustment_status CHECK (status IN ('Pending','Approved','Rejected','Reversed')),
    CONSTRAINT chk_financial_adjustment_decision CHECK ((status='Pending' AND current_decision_by_user_id IS NULL AND current_decision_at_utc IS NULL) OR (status <> 'Pending' AND current_decision_by_user_id IS NOT NULL AND current_decision_at_utc IS NOT NULL)),
    CONSTRAINT fk_financial_adjustment_booking FOREIGN KEY (booking_id) REFERENCES bookings(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_financial_adjustment_submitter FOREIGN KEY (submitted_by_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_financial_adjustment_decider FOREIGN KEY (current_decision_by_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

CREATE TABLE booking_adjustment_approvals (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    adjustment_id BIGINT UNSIGNED NOT NULL,
    action VARCHAR(20) NOT NULL,
    actor_user_id BIGINT UNSIGNED NOT NULL,
    decision_note TEXT NULL,
    created_at_utc TIMESTAMP(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    PRIMARY KEY (id),
    KEY idx_adjustment_approvals_timeline (adjustment_id, created_at_utc),
    KEY idx_adjustment_approvals_actor (actor_user_id),
    KEY idx_adjustment_approvals_action (action),
    CONSTRAINT chk_adjustment_approval_action CHECK (action IN ('Submitted','Approved','Rejected','Reversed')),
    CONSTRAINT fk_adjustment_approval_adjustment FOREIGN KEY (adjustment_id) REFERENCES booking_financial_adjustments(id) ON DELETE RESTRICT ON UPDATE RESTRICT,
    CONSTRAINT fk_adjustment_approval_actor FOREIGN KEY (actor_user_id) REFERENCES users(id) ON DELETE RESTRICT ON UPDATE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_0900_ai_ci;

-- Append-only event evidence; administrative DDL remains outside normal writes.
CREATE TRIGGER trg_adjustment_approvals_no_update BEFORE UPDATE ON booking_adjustment_approvals FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Adjustment approval history is immutable';
CREATE TRIGGER trg_adjustment_approvals_no_delete BEFORE DELETE ON booking_adjustment_approvals FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT='Adjustment approval history is immutable';

INSERT INTO permissions (permission_key, description) VALUES
('bookings.adjust', 'Submit booking financial adjustments'),
('bookings.approve_adjustment', 'Decide booking financial adjustments');
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.name IN ('System Administrator','Organisation Administrator','Manager')
AND p.permission_key IN ('bookings.adjust','bookings.approve_adjustment');
INSERT INTO role_permissions (role_id, permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p
WHERE r.name='Agent' AND p.permission_key='bookings.adjust';

UPDATE installation_metadata SET schema_version='4.0.5-financial-adjustments';
