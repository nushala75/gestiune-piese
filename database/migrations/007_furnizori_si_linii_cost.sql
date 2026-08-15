SET NAMES utf8mb4;
SET time_zone = '+00:00';

INSERT INTO furnizori (
    denumire, cod_fiscal, tara, moneda_implicita, configuratie_parser, activ, created_at, updated_at
)
VALUES
    ('Scootercraft S.O.O', 'PL6793242148', 'PL', 'EUR', NULL, 1, CURRENT_TIMESTAMP(6), CURRENT_TIMESTAMP(6)),
    ('RACING PLANET Vertrieb GmbH', 'DE297237364', 'DE', 'EUR', NULL, 1, CURRENT_TIMESTAMP(6), CURRENT_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE
    denumire = VALUES(denumire),
    tara = VALUES(tara),
    moneda_implicita = VALUES(moneda_implicita),
    activ = 1,
    updated_at = CURRENT_TIMESTAMP(6);

ALTER TABLE facturi_furnizor_linii
    ADD tip_linie VARCHAR(16) NOT NULL DEFAULT 'produs' AFTER numar_linie,
    ADD CONSTRAINT chk_facturi_furnizor_linii_tip
        CHECK (tip_linie IN ('produs', 'cost'));

INSERT INTO schema_migrations (versiune)
VALUES ('007_furnizori_si_linii_cost');
