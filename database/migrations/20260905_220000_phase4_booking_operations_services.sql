INSERT INTO permissions (permission_key,description) VALUES
('bookings.finance_view','View internal booking finance'),('bookings.finance_manage','Manage internal booking finance'),
('bookings.record_payment','Record Hays customer payment events'),('bookings.self_approve_adjustment','Approve own booking adjustment with approval capability');
INSERT INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.name IN ('System Administrator','Organisation Administrator','Manager') AND p.permission_key IN ('bookings.finance_view','bookings.finance_manage','bookings.record_payment');
INSERT INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.name='Agent' AND p.permission_key='bookings.record_payment';
INSERT INTO role_permissions (role_id,permission_id)
SELECT r.id,p.id FROM roles r CROSS JOIN permissions p WHERE r.name='System Administrator' AND p.permission_key='bookings.self_approve_adjustment';

UPDATE installation_metadata SET schema_version='4.2.0-booking-operations';
