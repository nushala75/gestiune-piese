SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE facturi_furnizor
    ADD tip_document VARCHAR(16) NOT NULL DEFAULT 'factura' AFTER taxare_inversa,
    ADD CONSTRAINT chk_facturi_furnizor_tip_document
        CHECK (tip_document IN ('factura', 'storno'));

INSERT INTO schema_migrations (versiune)
VALUES ('005_tip_document_storno');
