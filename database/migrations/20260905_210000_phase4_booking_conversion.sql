ALTER TABLE bookings ADD UNIQUE KEY uq_bookings_handoff (quote_booking_handoff_id);
UPDATE installation_metadata SET schema_version='4.1.0-booking-core';
