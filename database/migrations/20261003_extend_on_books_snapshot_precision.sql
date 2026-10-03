-- Preserve existing integer capacity and historical digests while matching the
-- four-decimal snapshot content/readback contract used by demand planning.
ALTER TABLE `hotel_on_books_snapshots`
  MODIFY COLUMN `on_books_room_nights` DECIMAL(14,4) DEFAULT NULL,
  MODIFY COLUMN `on_books_room_revenue` DECIMAL(18,4) DEFAULT NULL,
  MODIFY COLUMN `cumulative_cancel_room_nights` DECIMAL(14,4) DEFAULT NULL,
  MODIFY COLUMN `gross_booking_room_nights` DECIMAL(14,4) DEFAULT NULL;
