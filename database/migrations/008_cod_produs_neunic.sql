SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE produse
    DROP INDEX uq_produse_cod_produs,
    ADD INDEX ix_produse_cod_produs (cod_produs);

INSERT INTO schema_migrations (versiune)
VALUES ('008_cod_produs_neunic');
