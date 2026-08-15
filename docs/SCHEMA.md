# Schema MariaDB - etapa 1

Aceasta migrare acopera prima functionalitate confirmata: factura furnizor -> mapare produse -> articole noi SAGA -> factura SAGA -> receptie -> stoc intern -> fisier de actualizare stoc FGO.

## Reguli aplicate

- O singura firma: DESIGN MEDIA BUSINESS SRL.
- O singura gestiune: FIRMA.
- `produse.cod_fgo` are 8 cifre si este unic.
- Codurile generate local folosesc intervalul `01000000`-`08999999`. Valoarea `09000000` marcheaza secventa epuizata si nu se aloca unui produs.
- `produse.cod_produs` ramane separat de `cod_fgo`.
- Structura convenita a codului de produs se foloseste numai la filtrarea bazei initiale. Aplicatia accepta orice cod nevid de maximum 64 de caractere; unicitatea se verifica independent de format.
- Preturile de vanzare sunt pastrate in `produse`, in RON.
- Pretul de intrare si moneda furnizorului sunt pastrate in `produse_furnizori`; pretul de intrare are 4 zecimale.
- Toate cantitatile sunt numere intregi (`BIGINT`): stoc minim, linii de factura si receptie, miscari si solduri de stoc, respectiv liniile exportului FGO.
- Stocul nu este stocat in `produse`; soldul curent este in `solduri_stoc` si istoricul in `miscari_stoc`.
- Lista de produse permite filtre combinabile dupa denumire sau cod, categorie si stocul din gestiunea FIRMA (`pozitiv`, `zero` sau `negativ`). Produsele fara sold sunt incluse la stoc zero.
- Cand stocul fizic din FIRMA scade sub `stoc_minim`, `cantitate_de_comandat` devine initial `1`. O cantitate modificata manual se pastreaza cat timp stocul ramane sub prag; la atingerea sau depasirea stocului minim se reseteaza automat la `0`.
- Furnizorul propus pentru comanda trebuie sa fie mapat produsului. Selectia manuala are prioritate si se pastreaza; in lipsa ei se foloseste furnizorul celei mai recente achizitii, ordonat dupa data achizitiei si apoi dupa maparea cea mai noua.
- Estimarea viitoare pe baza rulajului va inlocui valoarea initiala `1` numai dupa confirmarea formulei si a perioadei analizate.
- Tipul contabil SAGA `Marfuri gestiune 1 firma` este o regula fixa de export, nu o coloana duplicata in `produse`.
- Stocul este explicat prin `miscari_stoc`; `solduri_stoc` este soldul operational actualizat tranzactional.
- Amount-ul liniei MOTO TREND ramane sursa de adevar. Pretul calculat are precizie extinsa.
- Importul PDF MOTO TREND se face in doi pasi: previzualizare/editare, apoi confirmare si salvare.
- Facturile MOTO TREND au TVA 0 si taxare inversa activa. Produsele au cota TVA 21%.
- Pentru un produs nou fara pret de vanzare, pretul de vanzare cu TVA propus este pretul de intrare EUR inmultit cu 11,5, rotunjit la 2 zecimale. Produsul ramane inactiv pana cand pretul este verificat sau editat si campul `activ` este bifat.
- O pozitie nemapata din previzualizarea facturii are actiunea `Produs NOU`. Formularul preia codul, denumirea in engleza, pretul de intrare si pretul de vanzare propus din factura; la salvare aloca `cod_fgo`, creeaza maparea MOTO TREND si poate activa produsul prin campul `activ`.
- Factura cu pozitii nemapate are status `import_partial`. Maparile pot fi continuate dupa salvare, iar `Finalizeaza importul` schimba statusul in `import_finalizat` numai dupa maparea tuturor pozitiilor.
- O factura fara receptie poate fi stearsa definitiv impreuna cu liniile, inregistrarea importului, PDF-ul si exporturile locale asociate. Produsele, maparile de produs si preturile confirmate nu sunt sterse, iar acelasi PDF poate fi reimportat.
- Un produs poate fi sters din catalog cu confirmare numai daca nu are istoric in facturi, receptii, miscari de stoc sau exporturi FGO. Maparile furnizorului si soldurile locale ale produsului sunt eliminate impreuna cu el.
- Pentru un produs existent, pretul de intrare se actualizeaza in EUR. Pretul de vanzare ramane neschimbat daca `pret_intrare * 11,5` nu il depaseste; in caz contrar previzualizarea afiseaza compact pretul propus editabil si pretul actual. Butonul `OK` actualizeaza imediat pretul cu TVA si recalculeaza pretul fara TVA.
- Importul facturii nu modifica stocul. Pretul de vanzare se poate modifica in previzualizare numai prin confirmarea explicita `OK`; celelalte actualizari de cantitati si preturi se aplica la etapa `Receptie`, dupa confirmarea importului SAGA.
- Receptia este permisa numai pentru o factura cu import finalizat si toate pozitiile mapate. Este integrala, necesita bifarea confirmarii ca importul in SAGA a fost facut manual si foloseste o data editabila, completata implicit cu data curenta.
- Finalizarea receptiei este definitiva: toate liniile, miscarile de stoc, soldurile si ultimele preturi de intrare sunt salvate intr-o singura tranzactie. Unicitatea facturii in `receptii` si verificarea tranzactionala impiedica dublarea stocului.
- Factura storno se introduce printr-o optiune separata si nu necesita legatura cu o factura initiala. Cantitatile si valorile sunt pozitive in PDF, dar receptia storno creeaza miscari negative si scade stocul; stocul negativ este permis dupa afisarea unei avertizari.
- Transportul, ambalarea si alte servicii identificate de parser se pastreaza ca linii de factura cu `tip_linie = cost`. Acestea intra in totalul documentului, nu necesita mapare la produs si nu creeaza linii de receptie sau miscari de stoc.
- Numai `cod_fgo` este unic in catalog. `cod_produs` poate aparea pe mai multe produse atunci cand denumirea si codul FGO pastreaza egalitatile din baza FGO.
- Catalogul initial final contine 5.298 produse: cele patru pozitii cu UM lipsa sau `E48` sunt excluse. Pretul de intrare si maparea la furnizor se completeaza ulterior din facturi.
- Produsul adaugat manual primeste automat `cod_fgo`. Poate fi salvat inactiv fara pret de intrare; activarea cere Description of Goods, descriere in romana si pret de vanzare cu TVA.
- Cand stocul scade sub `stoc_minim`, `cantitate_de_comandat` devine cel putin egala cu `stoc_minim`. O valoare manuala mai mare se pastreaza; la revenirea stocului la minimum sau peste, cantitatea se reseteaza la 0.
- Storno poate contine numai o parte dintre produsele unei facturi anterioare. Produsele noi sunt interzise: maparea automata sau manuala poate folosi numai produse care au deja o mapare pentru furnizorul documentului.
- Receptia storno necesita aceeasi confirmare manuala SAGA, este definitiva si nu modifica ultimul pret de intrare al produsului.
- Furnizori confirmati suplimentar: `Scootercraft S.O.O` (`PL6793242148`, Polonia, EUR) si `RACING PLANET Vertrieb GmbH` (`DE297237364`, Germania, EUR). Produsele lor se mapeaza numai la importul facturilor de test, dupa confirmarea eventualelor egalitati.
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
