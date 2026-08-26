-- Artifact rows start in a truthful pending state. The application changes
-- them to rendered_and_readback_verified only after exact bundle, manifest,
-- component-hash, spec-identity and scope readback succeeds in one transaction.

ALTER TABLE `ai_report_presentation_artifacts`
  MODIFY COLUMN `render_status` VARCHAR(40) NOT NULL
    DEFAULT 'rendered_pending_readback'
    COMMENT 'pending until exact transactional readback verification succeeds';
