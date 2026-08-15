# Schema MariaDB - etapa 1

Aceasta migrare acopera prima functionalitate confirmata: factura furnizor -> mapare produse -> articole noi SAGA -> factura SAGA -> receptie -> stoc intern -> fisier de actualizare stoc FGO.

## Reguli aplicate

- O singura firma: DESIGN MEDIA BUSINESS SRL.
- O singura gestiune: FIRMA.
- `produse.cod_fgo` are 8 cifre si este unic.
- Codurile generate local folosesc intervalul `01000000`-`08999999`. Valoarea `09000000` marcheaza secventa epuizata si nu se aloca unui produs.
- `produse.cod_produs` ramane separat de `cod_fgo`.
- Preturile de vanzare sunt pastrate in `produse`, in RON.
- Pretul de intrare si moneda furnizorului sunt pastrate in `produse_furnizori`; pretul de intrare are 4 zecimale.
- Toate cantitatile sunt numere intregi (`BIGINT`): stoc minim, linii de factura si receptie, miscari si solduri de stoc, respectiv liniile exportului FGO.
- Stocul nu este stocat in `produse`; soldul curent este in `solduri_stoc` si istoricul in `miscari_stoc`.
- Tipul contabil SAGA `Marfuri gestiune 1 firma` este o regula fixa de export, nu o coloana duplicata in `produse`.
- Stocul este explicat prin `miscari_stoc`; `solduri_stoc` este soldul operational actualizat tranzactional.
- Amount-ul liniei MOTO TREND ramane sursa de adevar. Pretul calculat are precizie extinsa.
- Importul PDF MOTO TREND se face in doi pasi: previzualizare/editare, apoi confirmare si salvare.
- Facturile MOTO TREND au TVA 0 si taxare inversa activa. Produsele au cota TVA 21%.
- Pentru un produs nou fara pret de vanzare, pretul de vanzare cu TVA propus este pretul de intrare EUR inmultit cu 11,5, rotunjit la 2 zecimale. Produsul ramane inactiv pana cand pretul este verificat sau editat si campul `activ` este bifat.
- O pozitie nemapata din previzualizarea facturii are actiunea `Produs NOU`. Formularul preia codul, denumirea in engleza, pretul de intrare si pretul de vanzare propus din factura; la salvare aloca `cod_fgo`, creeaza maparea MOTO TREND si poate activa produsul prin campul `activ`.
- Pentru un produs existent, pretul de intrare se actualizeaza in EUR. Pretul de vanzare ramane neschimbat daca `pret_intrare * 11,5` nu il depaseste; in caz contrar aplicatia avertizeaza si il inlocuieste cu valoarea mai mare la receptie.
- Importul facturii nu modifica stocul si nu aplica preturile produselor. Cantitatile si preturile se actualizeaza numai la etapa `Receptie`, dupa confirmarea importului SAGA.
- Egalitatea `Amount = cantitate * pret_unitar_calculat` se verifica in serviciul de import folosind aritmetica zecimala; nu este impusa printr-un CHECK SQL sensibil la rotunjire.
- Importul FGO ramane cu `mod_actualizare` NULL pana la verificarea sensului coloanei Cantitate.
- Accesul SAGA nu este automatizat de schema bazei si necesita confirmare prealabila.

## Ordinea tranzactiei de receptie

1. Se incarca si se deduplica fisierul sursa dupa SHA-256.
2. Se creeaza factura si liniile extrase.
3. Fiecare linie este mapata exact sau trimisa la verificare manuala.
4. Pentru produse noi se aloca tranzactional urmatorul `cod_fgo`.
5. Se genereaza si se confirma exportul de articole SAGA.
6. Se genereaza si se confirma exportul facturii SAGA.
7. Se creeaza receptia, liniile si miscarile de stoc intr-o singura tranzactie.
8. Se actualizeaza soldul operational.
9. Se genereaza fisierul FGO si se asteapta confirmarea importului manual sau web.

## Informatii intentionat nefixate

- Numele bazei, utilizatorul si parola ClausWeb.
- Daca `Cantitate` din importul FGO este valoare absoluta sau diferenta.
- Daca importul FGO de stoc poate crea un articol inexistent.
- Automatizarea accesului web FGO.
- Tabelele pentru comenzi clienti si rezervari vor intra in migrarea urmatoare, dupa validarea completa a tranzitiilor de status si a regulilor de rezervare.
