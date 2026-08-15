# Gestiune Piese Kymco

Aplicație Laravel pentru gestiunea pieselor DESIGN MEDIA BUSINESS SRL.

## Structură date

Schema MariaDB este definită în migrările SQL numerotate din `database/migrations`.

Ordinea curentă:

1. `001_initial_schema.sql`
2. `002_extindere_interval_cod_fgo.sql`
3. `003_pret_achizitie_4_zecimale.sql`
4. `004_cantitati_intregi.sql`
5. `005_tip_document_storno.sql`
6. `006_necesar_aprovizionare.sql`
7. `007_furnizori_si_linii_cost.sql`
8. `008_cod_produs_neunic.sql`
9. `009_autentificare_admin.sql`

După aplicarea migrării `009`, accesează `/configurare-administrator` și setează parola pentru administratorul unic `nusescu@gmail.com`. Pagina devine indisponibilă după crearea utilizatorului. Nu există înregistrare publică sau recuperare de parolă în această versiune.

Migrările SQL nu se aplică automat prin `php artisan migrate`. Aplicarea lor pe baza online necesită confirmare explicită.

## Date de test

Seederul idempotent creează cele șapte produse MOTO TREND, mapările furnizorului și soldurile inițiale:

```bash
php artisan db:seed --class=ProduseTestSeeder
```

## Înlocuirea datelor de test cu baza îmbunătățită

După aplicarea migrărilor `001`–`008`, catalogul final poate fi verificat fără modificări:

```bash
php artisan catalog:replace-production --dry-run
```

Operația definitivă acceptă exclusiv baza `piesekym_gestiune`, creează mai întâi o copie JSONL în `storage/app/private/backups`, șterge datele de test dependente și importă 5.298 produse fără mapări inițiale la furnizori:

```bash
php artisan catalog:replace-production --confirm=STERGE-TESTELE-SI-IMPORTA-5298
```

Fișierul sursă verificat este `database/data/baza_produse_imbunatatita.csv`. Cele patru poziții cu UM lipsă sau `E48` nu sunt incluse.

## Interfață

- `/` — panou principal;
- `/produse` — catalog, căutare, prețuri și stoc.

Modulele Facturi furnizori, Recepții, Export SAGA și Export FGO sunt afișate în meniu ca funcții viitoare.

## Deploy prin Git

Document root: `/home/piesekym/gestiune/public`.

Flux recomandat:

1. modificările sunt testate local;
2. se publică într-un branch GitHub și se revizuiesc prin pull request;
3. branch-ul aprobat se integrează în `main`;
4. găzduirea execută `git pull` în `/home/piesekym/gestiune`;
5. se rulează `composer install --no-dev --optimize-autoloader` și comenzile Laravel de cache;
6. migrările SQL se aplică separat numai după confirmare și backup.

Fișierele `.env`, facturile, exporturile FGO și artefactele locale sunt excluse din Git.
