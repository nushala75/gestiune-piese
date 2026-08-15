SET NAMES utf8mb4;
SET time_zone = '+00:00';

ALTER TABLE produse
    ADD cantitate_de_comandat BIGINT NOT NULL DEFAULT 0 AFTER stoc_minim,
    ADD furnizor_comanda_id BIGINT UNSIGNED NULL AFTER cantitate_de_comandat,
    ADD furnizor_comanda_manual TINYINT(1) NOT NULL DEFAULT 0 AFTER furnizor_comanda_id,
    ADD CONSTRAINT fk_produse_furnizor_comanda
        FOREIGN KEY (furnizor_comanda_id) REFERENCES furnizori(id) ON DELETE SET NULL;

UPDATE produse p
LEFT JOIN solduri_stoc ss
    ON ss.produs_id = p.id
    AND ss.gestiune_id = (
        SELECT g.id
        FROM gestiuni g
        INNER JOIN firme f ON f.id = g.firma_id
        WHERE g.cod = 'FIRMA' AND f.cod_fiscal = 'RO20548513'
        LIMIT 1
    )
SET p.cantitate_de_comandat =
    CASE WHEN COALESCE(ss.cantitate_fizica, 0) < p.stoc_minim THEN 1 ELSE 0 END;

UPDATE produse p
SET p.furnizor_comanda_id = (
    SELECT pf.furnizor_id
    FROM produse_furnizori pf
    WHERE pf.produs_id = p.id
    ORDER BY pf.data_ultimei_achizitii DESC, pf.id DESC
    LIMIT 1
)
WHERE p.cantitate_de_comandat > 0;

INSERT INTO schema_migrations (versiune)
VALUES ('006_necesar_aprovizionare');
