# Hometex API — Laravel Backend

Production REST API serving two separate frontends — an admin inventory dashboard and a customer-facing storefront — from a single Laravel 10 codebase. Handles auth, RBAC, inventory, orders, media, real-time events, and third-party courier integration for a multi-branch retail operation in Bangladesh.

> **Portfolio project**. Admin: [hometex-ims](https://github.com/ShahriarHim/hometex-ims) | Storefront: [hometex-ecom](https://github.com/ShahriarHim/hometex-ecom)

---

## The Problem

Two products needed to share one backend: an internal staff dashboard with strict role-based access, and a public customer storefront with guest checkout. The naive solution — two separate APIs — would duplicate business logic (inventory deduction, order processing, product queries) and create sync issues. The constraint was a single VM, a single team, and a single source of truth for all business data.

The solution is a single Laravel API with three namespaced route groups, shared models, and RBAC middleware enforced at the route level — not the frontend.

---

## Tech Stack

| Layer | Choice | Why |
|---|---|---|
| Framework | Laravel 10 | Mature ecosystem, Eloquent ORM, built-in queue/scheduler — reduces boilerplate for a CRUD-heavy business app |
| Auth | Laravel Sanctum | Bearer tokens for staff (IMS) + cookie-based for customer auth (ECOM). One package handles both auth flows |
| RBAC | Spatie Laravel Permission v6 | Seeded 52 permissions across 7 roles without writing a single custom middleware. `can()` checks in policy classes, not controllers |
| Media | Cloudflare R2 (S3-compatible) | R2 has zero egress fees vs. AWS S3. Same SDK, 80% cheaper for a media-heavy product catalog |
| Cache | Redis | MySQL query cache is deprecated; file cache creates race conditions under multiple PHP-FPM workers. Redis is atomic and shared across all workers |
| Queue | Redis + Supervisor | Courier API calls (Steadfast) and activity logging are async — Supervisor keeps workers alive on the VM without Docker overhead |
| Logging | spatie/laravel-activitylog | Observer-driven — no manual log calls in controllers. Every Eloquent model event is captured automatically |
| Monitoring | Sentry | Structured error capture with Laravel context (user, route, request). More actionable than reading raw log files |
| Delivery | Steadfast Courier API | The dominant BD courier API. Integrated for automated waybill generation on order dispatch |

---

## Key Engineering Decisions

**Single codebase, three route namespaces** — Rather than separate APIs, all routes share one codebase under three prefixes: `/api/` (admin/staff), `/api/v1/` (public ECOM), `/api/v1/ecom/` (authenticated customers). Shared models mean inventory changes from the admin side are immediately visible on the storefront. The namespace separation lets middleware be applied independently — `auth:sanctum` on admin routes, rate limiting on public routes, nothing on guest routes.

**Unified `users` table for customers and staff** — Customers and staff are both `User` model rows, differentiated by Spatie roles (`customer`, `admin`, `warehouse`, etc.). This eliminates a second auth system, lets the same `HasRoles` trait handle all permission checks, and means `POST /api/login` and `POST /api/v1/ecom/login` use the same underlying logic. The tradeoff: customer-specific profile data lives on the same table as staff data — a dedicated `CustomerProfile` model would be cleaner at larger scale.

**Two-phase media upload to R2** — Images are uploaded to Cloudflare R2 first (via a `POST /api/upload` endpoint that returns a storage key), then the key is stored in the DB. The DB never holds binary data or base64 strings. CDN delivery is automatic via R2's public bucket URL. This also means failed DB transactions don't leave orphaned files — the R2 key is only committed once the parent record is saved.

**Observer-driven audit logging** — `ActivityLogObserver` registers on all sensitive models at boot. Every `created`, `updated`, `deleted` event is logged to the `activity_log` table with the causer user and a diff of changed attributes. There are zero manual `activity()->log()` calls in controllers — the audit trail is structural, not opt-in.

**Branch-scoped inventory with ledger transfers** — `Inventory` records are always scoped to a `shop_id`. Transfers between branches create two records atomically in a DB transaction: a debit from the source shop and a credit to the destination. This means stock totals are always derivable by summing ledger entries — no mutable "current stock" column that can drift out of sync.

**Atomic stock deduction on order placement** — `POST /api/order` wraps stock validation and deduction in a single DB transaction. If any item is out of stock, the entire transaction rolls back and the order is rejected. This prevents overselling without requiring a separate reservation system.

---

## API Surface

### Admin Routes (`/api/` — Bearer token + Spatie permission)

| Resource | Endpoints |
|---|---|
| Auth | `POST /login`, `GET /me`, `POST /logout` |
| Products | Full CRUD + photo management + barcode generation |
| Catalog | Categories (3 levels), Brands, Attributes, Price Formulas |
| Orders | List, detail, status transitions, approval workflow |
| Inventory | Stock by branch, adjustments, inter-branch transfers |
| People | Customers, Suppliers, Staff (user management) |
| Operations | Shops, Roles, Permissions, System Settings, Banners |
| Analytics | Dashboard stats, Reports, Activity Logs |

### Public Storefront Routes (`/api/v1/` — no auth)

| Endpoint | Description |
|---|---|
| `GET /products` | Paginated product list with filters |
| `GET /products/{slug}` | Product detail with photos and attributes |
| `GET /categories` | Full category tree |
| `GET /banners` | Active banner sliders |
| `GET /settings` | Shipping rates, delivery estimates (no hardcoded values in frontend) |

### Customer Auth Routes (`/api/v1/ecom/` — Bearer token)

Cart, wishlist, order placement, order history, profile management, address book.

### Guest Routes (`/api/guest/`)

Guest checkout — no auth required, session-scoped cart.

---

## System Architecture

```
  IMS (React/Vite) ─── Bearer token ──────────────────┐
                                                       │
  ECOM (Next.js) ─── Bearer token / guest session ────┤
                                                       ▼
                                             Nginx (reverse proxy)
                                                       │
                                             PHP-FPM (Laravel)
                                                       │
                          ┌────────────────────────────┼──────────────────────┐
                          ▼                            ▼                      ▼
                      MySQL DB                    Redis Cache           Cloudflare R2
                  (primary data store)       (query cache, sessions,   (product images,
                                              queue job data)           public CDN)
                          │
                          ▼
                    Redis Queue
                  (Supervisor workers)
                          │
                 ┌────────┴────────┐
                 ▼                 ▼
          Steadfast API         Sentry
        (courier/waybill)    (error tracking)
```

---

## Scope

| Metric | Count |
|---|---|
| User roles | 7 |
| Granular permissions | 52 |
| Route groups | 3 (admin, public, guest) |
| API endpoints | ~90 |
| DB tables | ~35 |
| Eloquent models | ~25 |
| Queue workers (Supervisor) | 2 |

---

## What I'd Do Differently

- **Event-sourced order history** — Order status transitions are currently stored as a single `status` column. A proper event log (e.g. `order_events` table) would give full transition history with timestamps and causer — important for disputes and fulfillment analytics. The ActivityLog observer partially covers this but it's not queryable as a typed event stream.
- **Separate `CustomerProfile` from `User`** — The unified user table works, but customer-specific fields (shipping addresses, loyalty tier, corporate flag) are mixed with staff-specific fields (branch assignment, role). At the next scale step, I'd extract a `CustomerProfile` one-to-one relation.
- **API versioning from day one** — The `/api/v1/` namespace exists for ECOM but the admin namespace is unversioned `/api/`. If we ever need breaking changes on the admin side, we have no clean migration path. Would start every namespace at `/v1/` regardless of consumer.

---

## Getting Started

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

### Environment Variables

```env
APP_KEY=                          # generated by artisan key:generate
DB_DATABASE=hometex_db
DB_USERNAME=
DB_PASSWORD=

FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=                # Cloudflare R2 key
AWS_SECRET_ACCESS_KEY=
AWS_BUCKET=
AWS_ENDPOINT=                     # https://<account>.r2.cloudflarestorage.com
R2_PUBLIC_URL=                    # https://pub-<hash>.r2.dev

CACHE_DRIVER=redis
QUEUE_CONNECTION=redis

STEADFAST_API_KEY=                # BD courier integration
STEADFAST_SECRET_KEY=

SENTRY_LARAVEL_DSN=
ADMIN_SEED_EMAIL=admin@example.com
```

---

## Related Repos

- [hometex-ims](https://github.com/ShahriarHim/hometex-ims) — Vite + React 18 admin dashboard
- [hometex-ecom](https://github.com/ShahriarHim/hometex-ecom) — Next.js 16 customer storefront
