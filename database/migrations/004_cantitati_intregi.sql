SET NAMES utf8mb4;
SET time_zone = '+00:00';

DELIMITER //

CREATE PROCEDURE verifica_cantitati_intregi()
BEGIN
    IF (
        (SELECT COUNT(*) FROM produse WHERE stoc_minim <> TRUNCATE(stoc_minim, 0))
        + (SELECT COUNT(*) FROM facturi_furnizor_linii WHERE cantitate <> TRUNCATE(cantitate, 0))
        + (SELECT COUNT(*) FROM receptii_linii WHERE cantitate <> TRUNCATE(cantitate, 0))
        + (SELECT COUNT(*) FROM miscari_stoc WHERE cantitate <> TRUNCATE(cantitate, 0))
        + (SELECT COUNT(*) FROM solduri_stoc WHERE cantitate_fizica <> TRUNCATE(cantitate_fizica, 0))
        + (SELECT COUNT(*) FROM solduri_stoc WHERE cantitate_rezervata <> TRUNCATE(cantitate_rezervata, 0))
        + (SELECT COUNT(*) FROM exporturi_fgo_stoc_linii WHERE cantitate <> TRUNCATE(cantitate, 0))
    ) > 0 THEN
        SIGNAL SQLSTATE '45000'
            SET MESSAGE_TEXT = 'Migrarea a fost oprita: exista cantitati cu zecimale.';
    END IF;
END//

DELIMITER ;

CALL verifica_cantitati_intregi();
DROP PROCEDURE verifica_cantitati_intregi;

ALTER TABLE produse
    MODIFY stoc_minim BIGINT NOT NULL DEFAULT 1;

ALTER TABLE facturi_furnizor_linii
    MODIFY cantitate BIGINT NOT NULL;

ALTER TABLE receptii_linii
    MODIFY cantitate BIGINT NOT NULL;

ALTER TABLE miscari_stoc
    MODIFY cantitate BIGINT NOT NULL;

ALTER TABLE solduri_stoc
    MODIFY cantitate_fizica BIGINT NOT NULL DEFAULT 0,
    MODIFY cantitate_rezervata BIGINT NOT NULL DEFAULT 0;

ALTER TABLE exporturi_fgo_stoc_linii
    MODIFY cantitate BIGINT NOT NULL;

INSERT INTO schema_migrations (versiune)
VALUES ('004_cantitati_intregi');
