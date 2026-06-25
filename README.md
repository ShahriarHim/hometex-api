# Hometex Bangladesh — REST API

Laravel 10 backend serving two frontends for a multi-branch home textile retail business in Bangladesh. One API, two consumer namespaces: an admin/staff Inventory Management System (IMS) and a customer-facing e-commerce storefront (ECOM).

**Related repositories:**
- Admin dashboard (IMS): https://github.com/ShahriarHim/hometex-ims
- Customer storefront (ECOM): https://github.com/ShahriarHim/hometex-ecom

---

## The Engineering Problem

A retail business operating multiple physical branches needed:

1. **Unified inventory** across branches — stock at Branch A must be visible and transferable to Branch B, with every movement auditable.
2. **Two very different auth surfaces** — staff users with granular role-based permissions, and customer accounts with Google OAuth and guest checkout, all sharing the same `users` table.
3. **A single API that could not break either frontend** — the IMS and ECOM storefront have conflicting caching needs (IMS needs fresh data on every write; ECOM needs aggressive caching to handle storefront traffic).
4. **Courier integration without coupling** — order placement had to survive Steadfast API outages without failing the customer's checkout.

---

## Tech Stack

| Layer | Choice | Why |
|---|---|---|
| **Framework** | Laravel 10 / PHP 8.1 | Sanctum token auth, route model binding, Eloquent ORM, form requests — ships everything needed without glue code |
| **Auth** | Laravel Sanctum 3.2 | Stateless Bearer tokens for two distinct consumer types (IMS staff, ECOM customer) against the same guard |
| **RBAC** | Spatie laravel-permission 6.0 | Permission-per-route middleware (`permission:x`) instead of role checks scattered across controllers |
| **Audit trail** | Spatie laravel-activitylog 4.12 | Structured audit log with causer, subject, event, property diff — backed by its own `activity_log` table |
| **Image storage** | league/flysystem-aws-s3-v3 + intervention/image 2.7 | Base64 upload → R2 (S3-compatible) using a two-phase pattern: write to R2 first, update DB second, delete old key only after DB commit |
| **HTTP client** | guzzlehttp/guzzle 7.2 | Steadfast courier API integration (order creation, status tracking by consignment/invoice/tracking code) |
| **Monitoring** | sentry/sentry-laravel 4.26 | Exception capture + performance tracing in production |
| **Log viewer** | opcodesio/log-viewer 3.24 | In-app log browser without SSH access to the VM |
| **Cache** | Redis (production) / file (dev) | `CacheService` abstracts both: tag-based flush on Redis, version-key invalidation on file driver — same code path works either way |

---

## System Architecture

```mermaid
graph TB
    subgraph Clients
        IMS[IMS Admin Dashboard<br/>React 18 / Vite]
        ECOM[ECOM Storefront<br/>Next.js 16]
    end

    subgraph API ["Laravel 10 API — api.hometexbangladesh.org"]
        direction TB
        subgraph Namespaces
            NS_ADMIN["/api/ — IMS routes<br/>admin_or_staff middleware"]
            NS_ADMIN_ONLY["/api/ — admin only<br/>admin middleware"]
            NS_PUBLIC["/api/products, /api/v1/ — public<br/>no auth"]
            NS_ECOM["/api/v1/ecom/ — ECOM auth<br/>auth:sanctum"]
            NS_GUEST["/api/guest/ — guest checkout<br/>no auth"]
        end

        subgraph Services
            SVC_PRODUCT[ProductService<br/>+ Observer]
            SVC_CACHE[CacheService<br/>versioned keys + tags]
            SVC_ACTIVITY[ActivityLogService<br/>spatie activitylog]
            SVC_STEADFAST[SteadfastService<br/>courier integration]
            SVC_IMAGE[ImageService<br/>R2 two-phase upload]
        end
    end

    subgraph Storage
        MySQL[(MySQL<br/>hometex_db)]
        Redis[(Redis<br/>cache + sessions)]
        R2[(Cloudflare R2<br/>images)]
        Steadfast[Steadfast Courier API<br/>packzy.com]
        Sentry[Sentry<br/>error tracking]
    end

    IMS -->|Bearer token| NS_ADMIN
    IMS -->|Bearer token| NS_ADMIN_ONLY
    ECOM -->|Bearer token| NS_ECOM
    ECOM -->|no auth| NS_PUBLIC
    ECOM -->|no auth| NS_GUEST

    NS_ADMIN --> SVC_PRODUCT
    NS_PUBLIC --> SVC_CACHE
    SVC_PRODUCT --> SVC_CACHE
    NS_ADMIN --> SVC_ACTIVITY
    NS_GUEST --> SVC_STEADFAST

    SVC_PRODUCT --> MySQL
    SVC_CACHE --> Redis
    SVC_IMAGE --> R2
    SVC_STEADFAST --> Steadfast
    API --> Sentry
```

---

## Request Lifecycle

```mermaid
sequenceDiagram
    participant Client
    participant Middleware
    participant Controller
    participant Service
    participant Cache
    participant DB

    Client->>Middleware: HTTP Request + Bearer token
    Middleware->>Middleware: auth:sanctum (validate token)
    Middleware->>Middleware: admin_or_staff (role check)
    Middleware->>Middleware: permission:x (granular gate)
    Middleware->>Controller: $request (authenticated)

    Controller->>Controller: FormRequest validation
    Controller->>Service: delegate business logic

    alt Read path (ECOM public)
        Service->>Cache: Cache::remember(versioned_key, TTL)
        Cache-->>Service: hit → return cached
        Service->>DB: miss → query
        DB-->>Cache: store result
    end

    alt Write path (IMS mutation)
        Service->>DB: DB::transaction { ... }
        DB-->>Service: commit
        Service->>Cache: CacheService::clearProductCaches()
        Service->>Service: ActivityLogService::log(action, subject)
    end

    Controller-->>Client: JSON { status, message, data }
```

---

## Auth Flow

```mermaid
flowchart TD
    Start([Request]) --> IsPublic{Public route?}
    IsPublic -->|yes| Handler[Controller]
    IsPublic -->|no| HasToken{Bearer token?}
    HasToken -->|no| 401[401 Unauthenticated]
    HasToken -->|yes| SanctumGuard[auth:sanctum validates token]
    SanctumGuard --> UserType{User type?}

    UserType -->|customer / corporate| EcomOnly{ECOM route?}
    EcomOnly -->|yes| Handler
    EcomOnly -->|no - tries IMS| 403A[403 Access denied]

    UserType -->|admin / manager /<br/>product_manager /<br/>sales_staff / warehouse| RoleCheck[admin_or_staff middleware<br/>hasAnyRole check]
    RoleCheck -->|passes| PermCheck{permission:x<br/>on this route?}
    PermCheck -->|no permission gate| Handler
    PermCheck -->|has gate| GateCheck[hasPermissionTo check]
    GateCheck -->|granted| Handler
    GateCheck -->|denied| 403B[403 Forbidden]

    UserType -->|customer trying /api| 403A

    subgraph IMS Login
        direction LR
        L1[POST /api/login] --> L2[email or phone lookup]
        L2 --> L3[account lock check<br/>5 failed = 30min lock]
        L3 --> L4[isActive check]
        L4 --> L5[Hash::check password]
        L5 --> L6[recordLogin → activityLog]
        L6 --> L7[createToken → plainTextToken]
    end
```

---

## Database Design

78 migrations. Key entity relationships:

```mermaid
erDiagram
    users {
        int id PK
        string uuid
        string email
        string phone
        string user_type
        string status
        int staff_shop_id FK
        int failed_login_attempts
        datetime locked_until
    }

    products {
        int id PK
        string name
        string sku
        string slug
        int stock
        int status
        int category_id FK
        int brand_id FK
        int supplier_id FK
        decimal price
        decimal cost
        int low_stock_threshold
        string type
        string visibility
    }

    orders {
        int id PK
        string order_number
        int customer_id FK
        int staff_user_id FK
        int shop_id FK
        int order_status
        int payment_status
        boolean is_guest_order
        string guest_token
        string consignment_id
        string tracking_code
        datetime stock_adjusted_at
    }

    stock_ledger {
        int id PK
        int shop_id FK
        int product_id FK
        int quantity_change
        decimal unit_price
        string type
        string reference_type
        int reference_id
        int created_by FK
    }

    shop_product {
        int shop_id FK
        int product_id FK
        int quantity
    }

    transfer_products {
        int id PK
        int from_shop_id FK
        int to_shop_id FK
        int product_id FK
        int quantity
        string status
        int approved_by FK
    }

    activity_log {
        int id PK
        string log_name
        string event
        string causer_type
        int causer_id
        string subject_type
        int subject_id
        json properties
    }

    system_settings {
        int id PK
        string key
        string value
        string type
        string group
    }

    users ||--o{ orders : "places (staff)"
    users }o--o{ shops : "user_shop_access pivot"
    products }o--o{ shops : "shop_product pivot"
    products ||--o{ stock_ledger : "tracked by"
    shops ||--o{ stock_ledger : "source/dest"
    orders ||--o{ order_details : "contains"
    orders }o--|| shops : "placed at"
    products }o--|| categories : "belongs to"
    transfer_products }o--|| shops : "from/to"
```

---

## RBAC Design

7 roles, 53 granular permissions, all scoped to the `sanctum` guard. Permissions follow `module.action` convention throughout.

```mermaid
graph LR
    subgraph IMS Roles
        admin[admin<br/>all 53 permissions]
        manager[manager<br/>45 permissions]
        product_manager[product_manager<br/>19 permissions]
        sales_staff[sales_staff<br/>12 permissions]
        warehouse[warehouse<br/>7 permissions]
    end

    subgraph ECOM Roles
        customer[customer<br/>0 IMS permissions]
        corporate[corporate<br/>0 IMS permissions]
    end

    subgraph Permission Modules
        D[dashboard.view / export]
        P[products.view / create / edit / delete / import / export]
        C[catalog.view / create / edit / delete]
        A[attributes.view / manage]
        PR[pricing.view / manage]
        I[inventory.view / adjust / transfer.create / transfer.approve]
        O[orders.view / create / edit / cancel / export]
        SO[store_orders.view / cancel]
        CU[customers.view / create / edit / delete]
        R[returns.view / process]
        S[suppliers.view / create / edit / delete]
        SH[shops.view / create / edit / delete]
        ST[staff.view / create / edit / delete]
        AP[approvals.view / action]
        RP[reports.view / export]
        BC[barcode.generate]
        BN[banners.view / manage]
        AN[analytics.view]
        RL[roles.view / manage]
    end

    admin --> D & P & C & A & PR & I & O & SO & CU & R & S & SH & ST & AP & RP & BC & BN & AN & RL
```

**Enforcement**: `admin_or_staff` middleware (checks `hasAnyRole`) gates the entire IMS section. Individual write and sensitive read routes carry an additional `permission:x` middleware check via Spatie's `hasPermissionTo`. The `admin` middleware gates role management, system settings, and review moderation. Customer-type users are blocked at the IMS login controller before a token is issued.

**Shop scoping**: Staff with `staff_shop_id` set on their user record are scoped to that branch only. `Order::getAllOrders` reads `$user->assignedShopId()` and appends a `where shop_id = ?` clause when the resolved shop ID is non-null. Admins and managers with no `staff_shop_id` see all shops.

---

## API Surface

244 registered routes across three namespaces.

### Public — ECOM Storefront (no auth)

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/api/ping` | Health check |
| GET | `/api/products` | Paginated product list with filters |
| GET | `/api/products/featured` | Featured products (cached 1h) |
| GET | `/api/products/new-arrivals` | Products added in last 30 days (cached 1h) |
| GET | `/api/products/trending` | Trending flag products (cached 1h) |
| GET | `/api/products/bestsellers` | Sorted by `product_analytics.purchase_count` (cached 1h) |
| GET | `/api/products/on-sale` | Active discount window products (cached 15m) |
| GET | `/api/products/slug/{slug}` | Product detail by slug |
| GET | `/api/products/{id}/similar` | Same-category products |
| GET | `/api/products/{id}/recommendations` | `frequently_bought_together` relation |
| GET | `/api/v1/categories/tree` | Full category tree |
| GET | `/api/v1/categories/slug/{slug}` | Category by slug |
| GET | `/api/hero-banners` | Active banner sliders |
| GET | `/api/division` / `/api/districts/{id}` | Address lookup |
| POST | `/api/customer-signup` | Customer registration |
| POST | `/api/customer-login` | Customer login |
| POST | `/api/customer-google-login` | Google OAuth login |
| POST | `/api/corporate-register` | Corporate account registration |
| POST | `/api/guest/checkout` | Guest checkout (no account required) |
| GET | `/api/guest/orders/track` | Guest order tracking by token |

### ECOM — Authenticated Customer (`auth:sanctum`)

| Method | Endpoint | Purpose |
|---|---|---|
| GET | `/api/my-profile` | Customer profile |
| GET | `/api/my-order` | Customer's order history |
| POST | `/api/wish-list` | Add to wishlist |
| POST | `/api/store-review` | Submit product review |
| POST | `/api/check-out-logein-user` | Authenticated checkout |

### IMS — Staff (`admin_or_staff` + `permission:x`)

| Resource | Permission gates |
|---|---|
| Auth (login, logout, me, profile update) | — |
| Products (CRUD + duplicate + CSV import + photo management) | `products.*` |
| Catalog (brands, categories 3-level) | `catalog.*` |
| Attributes + attribute values | `attributes.manage` |
| Pricing formulas | `pricing.manage` |
| Suppliers | `suppliers.*` |
| Shops/Branches | `shops.*` |
| Staff (CRUD + activity per user) | `staff.*` |
| Orders (CRUD + cancel + payment update + item management) | `orders.*` |
| Store Orders / POS | `store_orders.*` |
| Customers (CRUD + order history) | `customers.*` |
| Inventory transfers (create + approve/reject) | `inventory.transfer.*` |
| Stock adjustments | `inventory.adjust` |
| Returns | `returns.*` |
| Reports (sales trend, order status, top products, monthly) | `reports.view` |
| Corporate approvals | `approvals.*` |
| Analytics | `analytics.view` |
| Activity logs | — (any staff) |

### IMS — Admin only (`admin` middleware)

| Resource |
|---|
| Roles & Permissions — CRUD roles, sync permissions, assign user roles |
| System Settings — GET all (grouped) + bulk PUT |
| Banners — full CRUD + reorder + config |
| Review Moderation — pending, approve, reject, bulk actions |

---

## Key Engineering Decisions

**1. One `users` table for all user types, Spatie roles as the discriminator — not separate tables.**

The original schema had a `sales_managers` table (migration `2026_06_20_110000` drops it). Unifying all users — admin, warehouse staff, customers, corporate buyers — into a single table with a `user_type` column and Spatie roles means one authentication path, one token validation, one `soft_delete` scope. The cost is that login must explicitly block `user_type === 'customer'` from IMS entry. The benefit is that adding a new staff role requires zero schema changes.

**2. Cache versioning as the driver-agnostic invalidation strategy.**

`CacheService` maintains four version counters (`cache_version:products`, `:categories`, `:banners`, `:navigation`) stored in the cache itself. Every product cache key is prefixed `v{version}:...`. When `clearProductCaches()` runs, it increments the version — old cache entries become unreachable by key without requiring a flush. On Redis this is complemented by tag-based flushing; on file driver the version strategy is the only mechanism. `ProductObserver` wires this to every Eloquent lifecycle event so no controller mutation can forget to invalidate.

**3. LEFT JOIN search over `whereHas` subqueries in the product list.**

`ProductService::getPaginatedProducts` uses LEFT JOINs to categories, sub-categories, and child sub-categories when a search term is present. This is 3-10x faster than `whereHas` subqueries on large catalogs because `whereHas` generates correlated subqueries that MySQL cannot use indexes for. A FULLTEXT index on `(name, sku)` was added in migration `2026_06_20_120000` alongside composite indexes on `(shop_id, product_id)` in `shop_product`.

**4. Stock ledger as a signed-quantity audit table, not a running total.**

`stock_ledger` records `quantity_change` (negative for deductions, positive for returns/adjustments) with a `type` enum covering eight movement types: `ecommerce_order`, `store_order`, `pos_order`, `manual`, `restore`, `return`, `transfer_in`, `transfer_out`. Every stock movement writes a ledger row inside the same `DB::transaction`. The running balance can always be reconstructed from the ledger — which matters for audits and for detecting stock inconsistencies introduced outside the application.

**5. Steadfast courier integration that cannot block order placement.**

`GuestCheckoutController::createSteadfastOrder` catches all `Throwable` and returns a null consignment ID if the Steadfast API is down or times out. The order is created without a consignment ID rather than failing. The tradeoff is that some orders may need manual courier entry when Steadfast is unavailable, but no customer ever sees a 500 from a third-party outage. `consignment_id` and `tracking_code` are nullable columns on `orders`.

**6. Two-phase R2 upload to prevent data loss on DB failure.**

In `ImageService`: the new image is uploaded to R2 first, then the database row is updated with the new key, then the old R2 key is deleted — only after the DB write succeeds. If the DB write fails, the new R2 object is orphaned but the user's existing record remains intact. The reverse order (delete old → DB update → upload new) would result in data loss on any failure mid-sequence.

---

## Scope and Metrics

| Metric | Count |
|---|---|
| Controllers | 47 (39 IMS + 8 ECOM) |
| Models | 49 |
| Migrations | 78 |
| Registered routes | 244 |
| Form Request classes | 50 (paired Store/Update per resource) |
| Service classes | 10 |
| Observer classes | 3 |
| Policy classes | 25 |
| Custom middleware | 5 |
| RBAC roles | 7 (5 IMS + 2 ECOM) |
| RBAC permissions | 53 granular, `module.action` format |
| Permission middleware usages in routes | 103 |
| Manager classes | 5 (Image, Order, Price, Report, Script) |
| External integrations | 1 (Steadfast courier) |

---

## What I Would Do Differently

- **Formal API Resources throughout** — Some older controllers return inline `response()->json([...])` arrays; newer controllers use dedicated Resource classes. A complete pass converting all responses to API Resource classes would make the contract explicit and the JSON shape independently testable.
- **Queue the audit log writes** — `ActivityLogService::log` is called synchronously after `DB::commit()`. It swallows exceptions but still adds latency to write operations. Every mutation that calls it should dispatch a queued job instead. Redis and Supervisor are already in place — it just needs to be wired.
- **Feature tests for RBAC boundaries** — There are no automated tests. Given 53 permissions across 7 roles, a matrix covering RBAC boundaries — can `sales_staff` create a product? can `warehouse` approve a transfer? — would catch regressions on any permission change before they reach production.

---

## Getting Started

```bash
# Clone and install
git clone https://github.com/ShahriarHim/hometex-api
cd hometex-api
composer install

# Configure environment
cp .env.example .env
php artisan key:generate
# Edit .env — set DB_*, CACHE_DRIVER, AWS_* for R2, STEADFAST_*, SENTRY_LARAVEL_DSN

# Database setup
php artisan migrate
php artisan db:seed --class=RolePermissionSeeder
php artisan db:seed --class=SystemSettingSeeder
php artisan db:seed --class=AdminSeeder

# Production cache (skip for local dev)
php artisan config:cache
php artisan route:cache

php artisan serve
```

**Minimum PHP**: 8.1 | **Minimum MySQL**: 8.0 (uses FULLTEXT index, JSON functions) | **Redis**: Required for production; file driver works for local dev

### Required Environment Variables

```env
APP_URL=
DB_HOST=
DB_DATABASE=hometex_db
DB_USERNAME=
DB_PASSWORD=

CACHE_DRIVER=redis          # or file for local dev
QUEUE_CONNECTION=redis

AWS_ACCESS_KEY_ID=          # Cloudflare R2 key
AWS_SECRET_ACCESS_KEY=
AWS_DEFAULT_REGION=auto
AWS_BUCKET=
AWS_ENDPOINT=               # https://<account-id>.r2.cloudflarestorage.com
R2_PUBLIC_URL=

STEADFAST_API_KEY=
STEADFAST_SECRET_KEY=

SENTRY_LARAVEL_DSN=
ADMIN_SEED_EMAIL=admin@example.com
```

---

## Related Repositories

| Project | Repository | Stack |
|---|---|---|
| Admin IMS dashboard | https://github.com/ShahriarHim/hometex-ims | React 18 + Vite |
| Customer storefront | https://github.com/ShahriarHim/hometex-ecom | Next.js 16 + TypeScript |

---

*Built by Shahriar Him. Portfolio project — client system for Hometex Bangladesh.*
