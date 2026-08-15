SET NAMES utf8mb4;
SET time_zone = '+00:00';

CREATE TABLE schema_migrations (
    versiune VARCHAR(100) PRIMARY KEY,
    aplicata_la DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE firme (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    denumire VARCHAR(190) NOT NULL,
    cod_fiscal VARCHAR(32) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_firme_cod_fiscal (cod_fiscal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE gestiuni (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    firma_id BIGINT UNSIGNED NOT NULL,
    cod VARCHAR(32) NOT NULL,
    denumire VARCHAR(190) NOT NULL,
    activa TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_gestiuni_firma_cod (firma_id, cod),
    CONSTRAINT fk_gestiuni_firma FOREIGN KEY (firma_id) REFERENCES firme(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE categorii (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    denumire VARCHAR(190) NOT NULL,
    activa TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_categorii_denumire (denumire)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE unitati_masura (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cod VARCHAR(16) NOT NULL,
    denumire VARCHAR(64) NOT NULL,
    activa TINYINT(1) NOT NULL DEFAULT 1,
    UNIQUE KEY uq_unitati_masura_cod (cod)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE secvente_cod_fgo (
    id TINYINT UNSIGNED PRIMARY KEY,
    urmatorul_cod INT UNSIGNED NOT NULL,
    cod_minim INT UNSIGNED NOT NULL,
    cod_maxim INT UNSIGNED NOT NULL,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    CONSTRAINT chk_secventa_fgo_interval CHECK (
        cod_minim = 1000000 AND cod_maxim = 1999999
        AND urmatorul_cod BETWEEN cod_minim AND cod_maxim
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE produse (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cod_fgo CHAR(8) NULL,
    cod_produs VARCHAR(64) NOT NULL,
    denumire_engleza VARCHAR(255) NOT NULL,
    descriere_romana TEXT NULL,
    categorie_id BIGINT UNSIGNED NOT NULL,
    unitate_masura_id BIGINT UNSIGNED NOT NULL,
    marca VARCHAR(100) NULL,
    stoc_minim DECIMAL(18,3) NOT NULL DEFAULT 1.000,
    pret_vanzare_fara_tva DECIMAL(18,4) NULL,
    pret_vanzare_cu_tva DECIMAL(18,2) NULL,
    cota_tva DECIMAL(5,2) NOT NULL DEFAULT 21.00,
    greutate_kg DECIMAL(10,3) NULL,
    voluminos TINYINT(1) NOT NULL DEFAULT 0,
    lungime_cm DECIMAL(10,2) NULL,
    latime_cm DECIMAL(10,2) NULL,
    inaltime_cm DECIMAL(10,2) NULL,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    sursa VARCHAR(32) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_produse_cod_fgo (cod_fgo),
    UNIQUE KEY uq_produse_cod_produs (cod_produs),
    KEY ix_produse_categorie (categorie_id),
    CONSTRAINT fk_produse_categorie FOREIGN KEY (categorie_id) REFERENCES categorii(id),
    CONSTRAINT fk_produse_um FOREIGN KEY (unitate_masura_id) REFERENCES unitati_masura(id),
    CONSTRAINT chk_produse_cod_fgo CHECK (cod_fgo IS NULL OR cod_fgo REGEXP '^[0-9]{8}$'),
    CONSTRAINT chk_produse_dimensiuni CHECK (
        voluminos = 0 OR
        (lungime_cm IS NOT NULL AND latime_cm IS NOT NULL AND inaltime_cm IS NOT NULL)
    )
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE furnizori (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    denumire VARCHAR(190) NOT NULL,
    cod_fiscal VARCHAR(32) NULL,
    tara CHAR(2) NULL,
    moneda_implicita CHAR(3) NULL,
    configuratie_parser JSON NULL,
    activ TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_furnizori_cod_fiscal (cod_fiscal)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE produse_furnizori (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    produs_id BIGINT UNSIGNED NOT NULL,
    furnizor_id BIGINT UNSIGNED NOT NULL,
    cod_furnizor VARCHAR(100) NOT NULL,
    denumire_furnizor VARCHAR(255) NULL,
    pret_achizitie_ultim DECIMAL(18,8) NULL,
    moneda CHAR(3) NULL,
    data_ultimei_achizitii DATE NULL,
    confirmata_manual TINYINT(1) NOT NULL DEFAULT 0,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_produs_furnizor_cod (furnizor_id, cod_furnizor),
    KEY ix_produse_furnizori_produs (produs_id),
    CONSTRAINT fk_pf_produs FOREIGN KEY (produs_id) REFERENCES produse(id),
    CONSTRAINT fk_pf_furnizor FOREIGN KEY (furnizor_id) REFERENCES furnizori(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE importuri_fisiere (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tip VARCHAR(32) NOT NULL,
    nume_fisier VARCHAR(255) NOT NULL,
    hash_sha256 CHAR(64) NOT NULL,
    cale_stocare VARCHAR(500) NOT NULL,
    status VARCHAR(32) NOT NULL,
    rezultat JSON NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_importuri_hash_tip (hash_sha256, tip)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE facturi_furnizor (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    furnizor_id BIGINT UNSIGNED NOT NULL,
    import_fisier_id BIGINT UNSIGNED NULL,
    numar_original VARCHAR(100) NOT NULL,
    numar_normalizat VARCHAR(100) NOT NULL,
    data_factura DATE NOT NULL,
    data_scadenta DATE NULL,
    moneda CHAR(3) NOT NULL,
    total_fara_tva DECIMAL(18,2) NOT NULL,
    total_tva DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    total_factura DECIMAL(18,2) NOT NULL,
    taxare_inversa TINYINT(1) NOT NULL DEFAULT 0,
    status VARCHAR(32) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_facturi_furnizor_numar (furnizor_id, numar_original),
    CONSTRAINT fk_facturi_furnizor FOREIGN KEY (furnizor_id) REFERENCES furnizori(id),
    CONSTRAINT fk_facturi_import FOREIGN KEY (import_fisier_id) REFERENCES importuri_fisiere(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE facturi_furnizor_linii (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    factura_id BIGINT UNSIGNED NOT NULL,
    numar_linie INT UNSIGNED NOT NULL,
    produs_id BIGINT UNSIGNED NULL,
    cod_furnizor VARCHAR(100) NOT NULL,
    descriere_originala VARCHAR(500) NOT NULL,
    cantitate DECIMAL(18,3) NOT NULL,
    unitate_masura_originala VARCHAR(16) NULL,
    amount_sursa DECIMAL(18,2) NOT NULL,
    pret_unitar_calculat DECIMAL(24,12) NOT NULL,
    cota_tva DECIMAL(5,2) NOT NULL,
    valoare_tva DECIMAL(18,2) NOT NULL DEFAULT 0.00,
    status_mapare VARCHAR(32) NOT NULL,
    observatii TEXT NULL,
    UNIQUE KEY uq_factura_linie (factura_id, numar_linie),
    KEY ix_ffl_produs (produs_id),
    CONSTRAINT fk_ffl_factura FOREIGN KEY (factura_id) REFERENCES facturi_furnizor(id),
    CONSTRAINT fk_ffl_produs FOREIGN KEY (produs_id) REFERENCES produse(id),
    CONSTRAINT chk_ffl_cantitate CHECK (cantitate > 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE receptii (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    factura_id BIGINT UNSIGNED NOT NULL,
    gestiune_id BIGINT UNSIGNED NOT NULL,
    data_receptie DATETIME(6) NOT NULL,
    status VARCHAR(32) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_receptii_factura (factura_id),
    CONSTRAINT fk_receptii_factura FOREIGN KEY (factura_id) REFERENCES facturi_furnizor(id),
    CONSTRAINT fk_receptii_gestiune FOREIGN KEY (gestiune_id) REFERENCES gestiuni(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE receptii_linii (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receptie_id BIGINT UNSIGNED NOT NULL,
    factura_linie_id BIGINT UNSIGNED NOT NULL,
    produs_id BIGINT UNSIGNED NOT NULL,
    cantitate DECIMAL(18,3) NOT NULL,
    cost_unitar DECIMAL(24,12) NOT NULL,
    valoare DECIMAL(18,2) NOT NULL,
    UNIQUE KEY uq_receptie_factura_linie (receptie_id, factura_linie_id),
    CONSTRAINT fk_rl_receptie FOREIGN KEY (receptie_id) REFERENCES receptii(id),
    CONSTRAINT fk_rl_factura_linie FOREIGN KEY (factura_linie_id) REFERENCES facturi_furnizor_linii(id),
    CONSTRAINT fk_rl_produs FOREIGN KEY (produs_id) REFERENCES produse(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE miscari_stoc (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    gestiune_id BIGINT UNSIGNED NOT NULL,
    produs_id BIGINT UNSIGNED NOT NULL,
    tip VARCHAR(32) NOT NULL,
    cantitate DECIMAL(18,3) NOT NULL,
    cost_unitar DECIMAL(24,12) NULL,
    receptie_linie_id BIGINT UNSIGNED NULL,
    referinta_tip VARCHAR(32) NULL,
    referinta_id BIGINT UNSIGNED NULL,
    explicatie VARCHAR(500) NOT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY ix_miscari_stoc_produs_data (produs_id, created_at),
    KEY ix_miscari_stoc_gestiune_produs (gestiune_id, produs_id),
    CONSTRAINT fk_ms_gestiune FOREIGN KEY (gestiune_id) REFERENCES gestiuni(id),
    CONSTRAINT fk_ms_produs FOREIGN KEY (produs_id) REFERENCES produse(id),
    CONSTRAINT fk_ms_receptie_linie FOREIGN KEY (receptie_linie_id) REFERENCES receptii_linii(id),
    CONSTRAINT chk_miscari_cantitate CHECK (cantitate <> 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE solduri_stoc (
    gestiune_id BIGINT UNSIGNED NOT NULL,
    produs_id BIGINT UNSIGNED NOT NULL,
    cantitate_fizica DECIMAL(18,3) NOT NULL DEFAULT 0.000,
    cantitate_rezervata DECIMAL(18,3) NOT NULL DEFAULT 0.000,
    updated_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
    PRIMARY KEY (gestiune_id, produs_id),
    CONSTRAINT fk_ss_gestiune FOREIGN KEY (gestiune_id) REFERENCES gestiuni(id),
    CONSTRAINT fk_ss_produs FOREIGN KEY (produs_id) REFERENCES produse(id),
    CONSTRAINT chk_ss_rezervat CHECK (cantitate_rezervata >= 0)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exporturi_saga (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    tip VARCHAR(32) NOT NULL,
    factura_id BIGINT UNSIGNED NULL,
    nume_fisier VARCHAR(255) NOT NULL,
    hash_sha256 CHAR(64) NOT NULL,
    cale_stocare VARCHAR(500) NOT NULL,
    status VARCHAR(32) NOT NULL,
    confirmat_la DATETIME(6) NULL,
    mesaj_rezultat TEXT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_exporturi_saga_hash (hash_sha256),
    CONSTRAINT fk_export_saga_factura FOREIGN KEY (factura_id) REFERENCES facturi_furnizor(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exporturi_fgo_stoc (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    receptie_id BIGINT UNSIGNED NOT NULL,
    mod_actualizare VARCHAR(32) NULL,
    nume_fisier VARCHAR(255) NOT NULL,
    hash_sha256 CHAR(64) NOT NULL,
    cale_stocare VARCHAR(500) NOT NULL,
    status VARCHAR(32) NOT NULL,
    importat_prin VARCHAR(16) NULL,
    confirmat_la DATETIME(6) NULL,
    mesaj_rezultat TEXT NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    UNIQUE KEY uq_exporturi_fgo_hash (hash_sha256),
    CONSTRAINT fk_export_fgo_receptie FOREIGN KEY (receptie_id) REFERENCES receptii(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE exporturi_fgo_stoc_linii (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    export_id BIGINT UNSIGNED NOT NULL,
    produs_id BIGINT UNSIGNED NOT NULL,
    cod_exportat CHAR(8) NOT NULL,
    nume_exportat VARCHAR(255) NOT NULL,
    cantitate DECIMAL(18,3) NOT NULL,
    pret_ponderat DECIMAL(18,4) NULL,
    valoare_stoc DECIMAL(18,2) NULL,
    UNIQUE KEY uq_export_fgo_produs (export_id, produs_id),
    CONSTRAINT fk_efsl_export FOREIGN KEY (export_id) REFERENCES exporturi_fgo_stoc(id),
    CONSTRAINT fk_efsl_produs FOREIGN KEY (produs_id) REFERENCES produse(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE jurnal_audit (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    actor_tip VARCHAR(32) NOT NULL,
    actor_id BIGINT UNSIGNED NULL,
    actiune VARCHAR(64) NOT NULL,
    entitate_tip VARCHAR(64) NOT NULL,
    entitate_id BIGINT UNSIGNED NULL,
    date_inainte JSON NULL,
    date_dupa JSON NULL,
    created_at DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
    KEY ix_audit_entitate (entitate_tip, entitate_id),
    KEY ix_audit_data (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO firme (denumire, cod_fiscal)
VALUES ('DESIGN MEDIA BUSINESS SRL', 'RO20548513');

INSERT INTO gestiuni (firma_id, cod, denumire)
SELECT id, 'FIRMA', 'FIRMA' FROM firme WHERE cod_fiscal = 'RO20548513';

INSERT INTO categorii (denumire) VALUES ('Pe comanda');
INSERT INTO unitati_masura (cod, denumire) VALUES ('BUC', 'Bucata'), ('SET', 'Set');
INSERT INTO secvente_cod_fgo (id, urmatorul_cod, cod_minim, cod_maxim)
VALUES (1, 1000000, 1000000, 1999999);

INSERT INTO schema_migrations (versiune) VALUES ('001_initial_schema');
