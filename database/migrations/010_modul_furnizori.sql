SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE furnizori
    ADD adresa VARCHAR(500) NULL AFTER tara;

INSERT INTO furnizori (
    denumire, cod_fiscal, tara, adresa, moneda_implicita, configuratie_parser, activ, created_at, updated_at
)
VALUES
    ('MOTO TREND S.A', 'EL094496688', 'GR', NULL, 'EUR', JSON_OBJECT('format', 'moto_trend_pdf_v1'), 1, CURRENT_TIMESTAMP(6), CURRENT_TIMESTAMP(6)),
    ('Scootercraft S.O.O', 'PL6793242148', 'PL', NULL, 'EUR', NULL, 1, CURRENT_TIMESTAMP(6), CURRENT_TIMESTAMP(6)),
    ('RACING PLANET Vertrieb GmbH', 'DE297237364', 'DE', NULL, 'EUR', NULL, 1, CURRENT_TIMESTAMP(6), CURRENT_TIMESTAMP(6)),
    ('MICHALIS GEORGIOU MOTOSPEED LTD', '10089694', 'CY', 'Paralimniou 54, Sotira Paralimni Road, 5390 Cipru', 'EUR', NULL, 1, CURRENT_TIMESTAMP(6), CURRENT_TIMESTAMP(6))
ON DUPLICATE KEY UPDATE
    denumire = VALUES(denumire),
    tara = VALUES(tara),
    adresa = COALESCE(VALUES(adresa), adresa),
    moneda_implicita = VALUES(moneda_implicita),
    activ = 1,
    updated_at = CURRENT_TIMESTAMP(6);

INSERT INTO schema_migrations (versiune)
VALUES ('010_modul_furnizori');
