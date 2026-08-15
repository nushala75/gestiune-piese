# Corespondenta bazei FGO cu schema aplicatiei

| Coloana baza imbunatatita | Destinatie | Observatie |
|---|---|---|
| Cod FGO | `produse.cod_fgo` | Unic, 8 cifre |
| Cod produs | `produse.cod_produs` | Codul piesei Kymco |
| Nume produs | derivat | `cod_produs` + `denumire_engleza`; nu se dubleaza in DB |
| Categorie | `produse.categorie_id` -> `categorii` | `Marfuri` sau `Pe comanda` |
| Categorie conta | regula export SAGA | Valoare fixa `Marfuri gestiune 1 firma`; nu necesita coloana in `produse` |
| Stoc initial | `solduri_stoc.cantitate_fizica` | Pentru gestiunea `FIRMA` |
| UM | `produse.unitate_masura_id` -> `unitati_masura` | `BUC` sau `SET` |
| Pret vanzare fara TVA (RON) | `produse.pret_vanzare_fara_tva` | 4 zecimale |
| Pret vanzare cu TVA (RON) | `produse.pret_vanzare_cu_tva` | 2 zecimale |
| Pret intrare | `produse_furnizori.pret_achizitie_ultim` | 4 zecimale; calculat `Amount / cantitate` |
| Moneda pret intrare | `produse_furnizori.moneda` | EUR pentru factura MOTO TREND |
| TVA % | `produse.cota_tva` | 21% |
| Descriere | `produse.descriere_romana` | Optionala |

Schema `produse` nu necesita coloane noi pentru pretul de intrare, moneda sau stoc. Aceste valori sunt deja normalizate in tabelele asociate.
