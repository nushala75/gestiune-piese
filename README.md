# Gestiune Piese Kymco

Aplicație Laravel pentru gestiunea pieselor DESIGN MEDIA BUSINESS SRL.

## Structură date

Schema MariaDB este definită în migrările SQL numerotate din `database/migrations`.

Ordinea curentă:

1. `001_initial_schema.sql`
2. `002_extindere_interval_cod_fgo.sql`
3. `003_pret_achizitie_4_zecimale.sql`

Migrările SQL nu se aplică automat prin `php artisan migrate`. Aplicarea lor pe baza online necesită confirmare explicită.

## Date de test

Seederul idempotent creează cele șapte produse MOTO TREND, mapările furnizorului și soldurile inițiale:

```bash
php artisan db:seed --class=ProduseTestSeeder
```

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
