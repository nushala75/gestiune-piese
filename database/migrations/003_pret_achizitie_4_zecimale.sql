SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE produse_furnizori
    MODIFY pret_achizitie_ultim DECIMAL(18,4) NULL;

INSERT INTO schema_migrations (versiune)
VALUES ('003_pret_achizitie_4_zecimale');
