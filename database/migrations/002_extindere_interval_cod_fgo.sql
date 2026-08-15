SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE secvente_cod_fgo
    DROP CONSTRAINT chk_secventa_fgo_interval;

UPDATE secvente_cod_fgo
SET cod_maxim = 8999999
WHERE id = 1;

ALTER TABLE secvente_cod_fgo
    ADD CONSTRAINT chk_secventa_fgo_interval CHECK (
        cod_minim = 1000000
        AND cod_maxim = 8999999
        AND urmatorul_cod BETWEEN cod_minim AND (cod_maxim + 1)
    );

INSERT INTO schema_migrations (versiune)
VALUES ('002_extindere_interval_cod_fgo');
