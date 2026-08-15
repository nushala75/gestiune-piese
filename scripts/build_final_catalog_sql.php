<?php

declare(strict_types=1);

$inputPath = dirname(__DIR__).'/database/data/baza_produse_imbunatatita.csv';
$outputDirectory = dirname(__DIR__).'/outputs/import-final';
$outputPath = $outputDirectory.'/import_catalog_final_5298_unicode.sql';
$compressedOutputPath = $outputPath.'.gz';

if (! is_file($inputPath)) {
    throw new RuntimeException('Fișierul CSV verificat lipsește.');
}

$handle = fopen($inputPath, 'rb');
if ($handle === false) {
    throw new RuntimeException('Fișierul CSV nu poate fi deschis.');
}

$expectedHeader = [
    'Cod FGO', 'Cod produs', 'Nume produs', 'Categorie', 'Categorie conta',
    'Stoc initial', 'UM', 'Pret vanzare fara TVA (RON)',
    'Pret vanzare cu TVA (RON)', 'Pret intrare', 'Moneda pret intrare',
    'TVA %', 'Descriere',
];

$header = fgetcsv($handle);
if ($header !== $expectedHeader) {
    throw new RuntimeException('Antetul CSV nu corespunde catalogului verificat.');
}

$products = [];
$fgoCodes = [];
while (($row = fgetcsv($handle)) !== false) {
    if (count($row) !== 13) {
        throw new RuntimeException('Catalogul conține un rând cu număr incorect de coloane.');
    }
    [$codFgo, $codProdus, $numeProdus, $categorie, $categorieConta, $stoc, $um,
        $pretFaraTva, $pretCuTva, $pretIntrare, $moneda, $tva, $descriere] = $row;

    if (! preg_match('/^\d{8}$/', $codFgo) || isset($fgoCodes[$codFgo])) {
        throw new RuntimeException("Cod FGO invalid sau duplicat: {$codFgo}.");
    }
    $fgoCodes[$codFgo] = true;
    $prefix = $codProdus.' ';
    if (! str_starts_with($numeProdus, $prefix)) {
        throw new RuntimeException("Nume invalid pentru {$codFgo}.");
    }
    $englishName = trim(mb_substr($numeProdus, mb_strlen($prefix)));
    $categoryName = match (mb_strtolower(trim($categorie))) {
        'marfuri' => 'Marfuri',
        'pe comanda' => 'Pe comanda',
        default => throw new RuntimeException("Categorie invalidă pentru {$codFgo}."),
    };
    if ($categorieConta !== 'Marfuri gestiune 1 firma'
        || ! preg_match('/^\d+$/', $stoc)
        || ! in_array($um, ['BUC', 'SET'], true)
        || ! is_numeric($pretFaraTva)
        || ! is_numeric($pretCuTva)
        || $pretIntrare !== ''
        || $moneda !== 'EUR'
        || (float) $tva !== 21.0
        || $englishName === '') {
        throw new RuntimeException("Date invalide pentru {$codFgo}.");
    }

    $stock = (int) $stoc;
    $products[] = [
        'cod_fgo' => $codFgo,
        'cod_produs' => $codProdus,
        'denumire_engleza' => $englishName,
        'descriere_romana' => trim($descriere) !== '' ? trim($descriere) : null,
        'categorie' => $categoryName,
        'um' => $um,
        'stoc' => $stock,
        'pret_fara_tva' => number_format((float) $pretFaraTva, 4, '.', ''),
        'pret_cu_tva' => number_format((float) $pretCuTva, 2, '.', ''),
        'de_comandat' => $stock < 1 ? 1 : 0,
    ];
}
fclose($handle);

if (count($products) !== 5298 || count($fgoCodes) !== 5298) {
    throw new RuntimeException('Catalogul final trebuie să conțină exact 5.298 coduri FGO unice.');
}

function sqlText(string $value): string
{
    return "CONVERT(0x".bin2hex($value).' USING utf8mb4) COLLATE utf8mb4_unicode_ci';
}

$sql = <<<'SQL'
-- Gestiune Piese Kymco
-- Import final: 5.298 produse, fara preturi de intrare si fara mapari initiale la furnizori.
-- Operatie distructiva autorizata explicit; nu include backup la cererea utilizatorului.

SET NAMES utf8mb4;
SET time_zone = '+00:00';

DELIMITER //
CREATE PROCEDURE verifica_import_catalog_final()
BEGIN
    IF DATABASE() <> 'piesekym_gestiune' THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Import oprit: baza selectata nu este piesekym_gestiune.';
    END IF;
    IF (
        SELECT COUNT(*)
        FROM schema_migrations
        WHERE versiune IN (
            '001_initial_schema',
            '002_extindere_interval_cod_fgo',
            '003_pret_achizitie_4_zecimale',
            '004_cantitati_intregi',
            '005_tip_document_storno',
            '006_necesar_aprovizionare',
            '007_furnizori_si_linii_cost',
            '008_cod_produs_neunic'
        )
    ) <> 8 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Import oprit: nu sunt aplicate toate migrarile 001-008.';
    END IF;
    IF (SELECT COUNT(*) FROM firme WHERE cod_fiscal = 'RO20548513') <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Import oprit: firma RO20548513 nu exista exact o data.';
    END IF;
    IF (
        SELECT COUNT(*)
        FROM gestiuni g
        INNER JOIN firme f ON f.id = g.firma_id
        WHERE f.cod_fiscal = 'RO20548513' AND g.cod = 'FIRMA'
    ) <> 1 THEN
        SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Import oprit: gestiunea FIRMA nu exista exact o data.';
    END IF;
END//
DELIMITER ;

CALL verifica_import_catalog_final();
DROP PROCEDURE verifica_import_catalog_final;

SET @gestiune_firma_id = (
    SELECT g.id
    FROM gestiuni g
    INNER JOIN firme f ON f.id = g.firma_id
    WHERE f.cod_fiscal = 'RO20548513' AND g.cod = 'FIRMA'
    LIMIT 1
);

START TRANSACTION;

DELETE FROM jurnal_audit;
DELETE FROM exporturi_fgo_stoc_linii;
DELETE FROM exporturi_fgo_stoc;
DELETE FROM exporturi_saga;
DELETE FROM miscari_stoc;
DELETE FROM receptii_linii;
DELETE FROM receptii;
DELETE FROM facturi_furnizor_linii;
DELETE FROM facturi_furnizor;
DELETE FROM importuri_fisiere;
DELETE FROM solduri_stoc;
DELETE FROM produse_furnizori;
DELETE FROM produse;

INSERT INTO categorii (denumire, activa)
VALUES ('Marfuri', 1), ('Pe comanda', 1)
ON DUPLICATE KEY UPDATE activa = 1;

INSERT INTO unitati_masura (cod, denumire, activa)
VALUES ('BUC', 'Bucata', 1), ('SET', 'Set', 1)
ON DUPLICATE KEY UPDATE denumire = VALUES(denumire), activa = 1;

SQL;

$productChunks = array_chunk($products, 200);
foreach ($productChunks as $chunk) {
    $values = [];
    foreach ($chunk as $product) {
        $description = $product['descriere_romana'] === null ? 'NULL' : sqlText($product['descriere_romana']);
        $values[] = sprintf(
            "(%s,%s,%s,%s,(SELECT id FROM categorii WHERE denumire=%s LIMIT 1),(SELECT id FROM unitati_masura WHERE cod=%s LIMIT 1),'KYMCO',1,%d,NULL,0,%s,%s,21.00,NULL,0,NULL,NULL,NULL,1,'fgo_initial',CURRENT_TIMESTAMP(6),CURRENT_TIMESTAMP(6))",
            sqlText($product['cod_fgo']),
            sqlText($product['cod_produs']),
            sqlText($product['denumire_engleza']),
            $description,
            sqlText($product['categorie']),
            sqlText($product['um']),
            $product['de_comandat'],
            $product['pret_fara_tva'],
            $product['pret_cu_tva'],
        );
    }
    $sql .= "INSERT INTO produse (\n".
        "    cod_fgo, cod_produs, denumire_engleza, descriere_romana, categorie_id, unitate_masura_id,\n".
        "    marca, stoc_minim, cantitate_de_comandat, furnizor_comanda_id, furnizor_comanda_manual,\n".
        "    pret_vanzare_fara_tva, pret_vanzare_cu_tva, cota_tva, greutate_kg, voluminos,\n".
        "    lungime_cm, latime_cm, inaltime_cm, activ, sursa, created_at, updated_at\n".
        ") VALUES\n    ".implode(",\n    ", $values).";\n\n";
}

foreach ($productChunks as $chunk) {
    $derivedRows = [];
    foreach ($chunk as $index => $product) {
        $select = sprintf('SELECT %s AS cod_fgo, %d AS stoc', sqlText($product['cod_fgo']), $product['stoc']);
        $derivedRows[] = $index === 0 ? $select : 'UNION ALL '.$select;
    }
    $sql .= "INSERT INTO solduri_stoc (gestiune_id, produs_id, cantitate_fizica, cantitate_rezervata, updated_at)\n".
        "SELECT @gestiune_firma_id, p.id, x.stoc, 0, CURRENT_TIMESTAMP(6)\n".
        "FROM (\n    ".implode("\n    ", $derivedRows)."\n) x\n".
        "INNER JOIN produse p ON p.cod_fgo = x.cod_fgo;\n\n";
}

$sql .= <<<'SQL'
CREATE TEMPORARY TABLE validare_import_catalog (
    valid TINYINT NOT NULL,
    CONSTRAINT chk_validare_import_catalog CHECK (valid = 1)
) ENGINE=InnoDB;

INSERT INTO validare_import_catalog (valid)
SELECT CASE WHEN
    (SELECT COUNT(*) FROM produse) = 5298
    AND (SELECT COUNT(DISTINCT cod_fgo) FROM produse) = 5298
    AND (SELECT COUNT(*) FROM solduri_stoc) = 5298
    AND (SELECT COUNT(*) FROM produse_furnizori) = 0
    AND (SELECT COUNT(*) FROM facturi_furnizor) = 0
    AND (SELECT COUNT(*) FROM receptii) = 0
    AND (SELECT COUNT(*) FROM miscari_stoc) = 0
THEN 1 ELSE 0 END;

COMMIT;
DROP TEMPORARY TABLE validare_import_catalog;

SELECT
    (SELECT COUNT(*) FROM produse) AS produse,
    (SELECT COUNT(DISTINCT cod_fgo) FROM produse) AS coduri_fgo_unice,
    (SELECT COUNT(*) FROM solduri_stoc) AS solduri,
    (SELECT COUNT(*) FROM produse_furnizori) AS mapari_furnizori,
    (SELECT COUNT(*) FROM facturi_furnizor) AS facturi_ramase,
    (SELECT COUNT(*) FROM receptii) AS receptii_ramase,
    (SELECT COUNT(*) FROM miscari_stoc) AS miscari_ramase;
SQL;

if (! is_dir($outputDirectory) && ! mkdir($outputDirectory, 0775, true) && ! is_dir($outputDirectory)) {
    throw new RuntimeException('Directorul de ieșire nu poate fi creat.');
}
if (file_put_contents($outputPath, $sql) === false) {
    throw new RuntimeException('Fișierul SQL nu poate fi salvat.');
}
$compressed = gzencode($sql, 9);
if ($compressed === false || file_put_contents($compressedOutputPath, $compressed) === false) {
    throw new RuntimeException('Fișierul SQL comprimat nu poate fi salvat.');
}

echo $outputPath.PHP_EOL;
echo 'Produse: '.count($products).PHP_EOL;
echo 'SHA256: '.hash_file('sha256', $outputPath).PHP_EOL;
echo 'Dimensiune: '.filesize($outputPath).' bytes'.PHP_EOL;
echo $compressedOutputPath.PHP_EOL;
echo 'SHA256 GZIP: '.hash_file('sha256', $compressedOutputPath).PHP_EOL;
echo 'Dimensiune GZIP: '.filesize($compressedOutputPath).' bytes'.PHP_EOL;
