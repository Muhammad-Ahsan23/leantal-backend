# LeanTal Migrations — Kaise Use Karein

## Folder Structure
```
database/migrations/
├── tenant/    <- 23 files — companies, users, jobs, candidates, applications, etc.
│               Ye TEENO regional DBs (pgsql_us, pgsql_eu, pgsql_uk) par identical chalengi
├── routing/   <- 1 file — company_region_lookup, user_email_region_lookup
│               Sirf routing_db par chalegi
└── admin/     <- 3 files — super_admins, impersonation_logs, feature_flags, blog_posts
                Sirf admin_db par chalegi
```

## Phase 1 — Local Development (Single DB)
Agar abhi sirf 1 local database use kar rahe ho (jaisa humne discuss kiya):

```bash
php artisan migrate --path=database/migrations/tenant
```

Ye sirf tenant folder ki migrations chalayega — humari core ATS ka poora
schema (companies, users, jobs, candidates, pipeline, etc.) ban jayega.
routing/ aur admin/ Phase 1 mein zaroori nahi (Super Admin panel aur
region-routing baad mein banega).

## Phase 2 — Multi-Region Simulation (Docker Compose)
Jab region-routing test karna ho, saari migrations sab connections par:

```bash
php artisan migrate --database=pgsql_us   --path=database/migrations/tenant
php artisan migrate --database=pgsql_eu   --path=database/migrations/tenant
php artisan migrate --database=pgsql_uk   --path=database/migrations/tenant
php artisan migrate --database=routing_db --path=database/migrations/routing
php artisan migrate --database=admin_db   --path=database/migrations/admin
```

## Important Notes
- Migration files mein Postgres ENUM types manually `DB::statement()` se
  create ho rahe hain (Laravel ka native enum support MySQL-style hai,
  Postgres ke liye ye zyada reliable tareeqa hai).
- Migration `down()` method mein hamesha `DROP TYPE` bhi likha hai —
  taake rollback (`php artisan migrate:rollback`) clean rahe.
- Foreign keys `foreignUuid()->constrained()` se banti hain — Laravel
  automatically sahi referenced table dhoondh leta hai (naming convention se).
- `applications` table ka `idx_one_active_application_per_job` partial
  unique index PRD ka sabse critical business rule enforce karta hai
  (Section 33/135 — duplicate application prevention) — ISKO KABHI
  REMOVE MAT KARNA.
- `admin/` migrations mein `protected $connection = 'admin_db';` set
  hai — extra safety taake galti se kisi aur DB par na chal jayen.

## Abhi Baaki Kya Hai
- Eloquent Models banana (App\Models\Company, User, Job, etc.)
- Factories/Seeders (test data ke liye)
- config/database.php mein connections define karna (already diya gaya
  04_laravel_region_resolver.php ke sath related file mein)
