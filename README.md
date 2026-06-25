# Hometex API

Laravel 10 REST API powering the Hometex Bangladesh ecosystem — a multi-branch home textile retail platform. Serves both the admin IMS dashboard and the customer-facing ECOM storefront from a single backend.

> **Portfolio project.** `.env` credentials have been removed. Frontend repos: [hometex-ims](https://github.com/ShahriarHim/hometex-ims) | [hometex-ecom](https://github.com/ShahriarHim/hometex-ecom)

---

## Tech Stack

| Layer | Choice |
|---|---|
| Framework | Laravel 10 |
| Auth | Laravel Sanctum (Bearer token) |
| RBAC | Spatie Laravel Permission v6 |
| Media storage | Cloudflare R2 (S3-compatible) |
| Cache / Queue | Redis |
| Activity logging | spatie/laravel-activitylog |
| Error monitoring | Sentry |
| Process management | Supervisor |
| Web server | Nginx |

---

## API Surface

### Auth Namespace (`/api/`)
Admin and staff — IMS consumers.

| Method | Endpoint | Description |
|---|---|---|
| POST | `/api/login` | Staff login → Bearer token |
| GET | `/api/me` | Authenticated user + roles + permissions |
| POST | `/api/logout` | Invalidate token |

### Admin Resources
Full CRUD with Spatie RBAC middleware on every route:

- Products, Photos, Attributes, Price Formulas
- Categories, Sub-categories, Child-categories, Brands
- Orders, Store Orders, Returns, Approvals
- Inventory Transfers, Stock Adjustments
- Customers, Suppliers, Shops/Branches
- Staff, Roles, Permissions
- Banners, Barcodes, Reports
- Activity Logs, System Settings, Analytics

### ECOM Namespace (`/api/v1/`)
Public storefront consumers.

| Method | Endpoint | Description |
|---|---|---|
| GET | `/api/v1/products` | Product listing with filters |
| GET | `/api/v1/products/{slug}` | Product detail |
| GET | `/api/v1/categories` | Category tree |
| POST | `/api/v1/ecom/login` | Customer login |
| POST | `/api/v1/ecom/register` | Customer registration |
| GET/POST/DELETE | `/api/v1/ecom/cart` | Cart management |
| GET/POST/DELETE | `/api/v1/ecom/wishlist` | Wishlist |
| POST | `/api/v1/ecom/orders` | Place order |
| GET | `/api/v1/ecom/orders` | Order history |

### Guest Namespace (`/api/guest/`)
No auth required — guest checkout flow.

---

## RBAC Design

**7 roles** with 52 granular permissions seeded via `RolePermissionSeeder`:

```
admin          → 52 permissions (full access)
manager        → 38 permissions
product_manager→ 19 permissions
sales_staff    → 13 permissions
warehouse      → 8 permissions
customer       → ECOM role
corporate      → ECOM role
```

All roles are Spatie `Role` model entries on the `sanctum` guard. `/api/me` returns flat `roles[]` and `permissions[]` arrays for frontend consumption.

---

## Key Architecture Decisions

**Two-phase media upload (R2):** Images are uploaded to Cloudflare R2 first, then the key is stored in the DB. This keeps the DB free of binary data and makes CDN delivery trivially easy.

**Observer-driven activity logging:** `ActivityLogObserver` hooks into Eloquent model events — no manual log calls scattered through controllers.

**Unified User model:** Customers and staff share the same `users` table, differentiated by Spatie roles. No separate `customers` or `staff` tables.

**Branch-scoped inventory:** All stock movements are scoped to a `shop_id`. Transfers create paired debit/credit records atomically.

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
APP_NAME=Laravel
APP_ENV=local
APP_KEY=
APP_URL=http://localhost

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=hometex_db
DB_USERNAME=root
DB_PASSWORD=

FILESYSTEM_DISK=s3
AWS_ACCESS_KEY_ID=
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=auto
AWS_BUCKET=
AWS_ENDPOINT=           # Cloudflare R2 endpoint
AWS_USE_PATH_STYLE_ENDPOINT=true
R2_PUBLIC_URL=

CACHE_DRIVER=redis
QUEUE_CONNECTION=redis
REDIS_HOST=127.0.0.1
REDIS_PORT=6379

STEADFAST_API_KEY=      # BD courier integration
STEADFAST_SECRET_KEY=

SENTRY_LARAVEL_DSN=

ADMIN_SEED_EMAIL=admin@example.com
```

---

## Related Repos

- [hometex-ims](https://github.com/ShahriarHim/hometex-ims) — Vite + React 18 admin dashboard
- [hometex-ecom](https://github.com/ShahriarHim/hometex-ecom) — Next.js 16 storefront
