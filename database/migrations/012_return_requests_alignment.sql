-- Migration 012: Align return_requests columns, decision tracking, and status ENUMs
-- Adds: decision_reason, admin_notes, actual_refund, refund_payment_id, courier_partner, tracking_number, pickup_scheduled_date, pickup_time_window, resolution, symptoms, photos, pickup_address
-- Expands status ENUM to support standard return workflow:
-- 'pending', 'pickup_scheduled', 'quality_inspection', 'approved', 'rejected', 'refund_released', 'refund_issued', 'completed'

ALTER TABLE return_requests
  MODIFY COLUMN status ENUM(
    'pending',
    'pickup_scheduled',
    'quality_inspection',
    'approved',
    'rejected',
    'refund_released',
    'refund_issued',
    'completed'
  ) NOT NULL DEFAULT 'pending',
  ADD COLUMN IF NOT EXISTS actual_refund DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER estimated_refund,
  ADD COLUMN IF NOT EXISTS decision_reason VARCHAR(255) NULL AFTER actual_refund,
  ADD COLUMN IF NOT EXISTS admin_notes TEXT NULL AFTER decision_reason,
  ADD COLUMN IF NOT EXISTS refund_payment_id BIGINT UNSIGNED NULL AFTER admin_notes,
  ADD COLUMN IF NOT EXISTS resolution VARCHAR(50) NOT NULL DEFAULT 'original' AFTER reason,
  ADD COLUMN IF NOT EXISTS symptoms TEXT NULL AFTER resolution,
  ADD COLUMN IF NOT EXISTS photos LONGTEXT NULL AFTER symptoms,
  ADD COLUMN IF NOT EXISTS pickup_address LONGTEXT NULL AFTER photos,
  ADD COLUMN IF NOT EXISTS courier_partner VARCHAR(100) NULL AFTER pickup_address,
  ADD COLUMN IF NOT EXISTS tracking_number VARCHAR(100) NULL AFTER courier_partner,
  ADD COLUMN IF NOT EXISTS pickup_scheduled_date DATE NULL AFTER tracking_number,
  ADD COLUMN IF NOT EXISTS pickup_time_window VARCHAR(100) NULL AFTER pickup_scheduled_date;
