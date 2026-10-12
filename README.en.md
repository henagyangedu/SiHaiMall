# SihaiMall (四海商城)

[简体中文](README.md) | **English**

[![Checks](https://github.com/henagyangedu/SiHaiMall/actions/workflows/ci.yml/badge.svg)](https://github.com/henagyangedu/SiHaiMall/actions/workflows/ci.yml)
[![Release](https://img.shields.io/github/v/release/henagyangedu/SiHaiMall?include_prereleases&label=release)](https://github.com/henagyangedu/SiHaiMall/releases)
[![License](https://img.shields.io/badge/license-PolyForm%20Noncommercial%201.0.0-blue)](LICENSE.md)

A source-available, multi-language e-commerce system with a built-in affiliate program, made for selling both in China and abroad. One backend serves the admin console, a desktop storefront, a mobile web storefront and a WeChat mini program. It handles multiple languages and currencies, per-region pricing and taxes, and two-level affiliate commissions.

> **Licensing: free for noncommercial use; commercial use requires a paid license.** The author shares the code freely and asks those who run a business on it to pay a one-time fee that funds maintenance and further development. See [Licensing](docs/licensing.en.md).

> **This is a development release.** The core shopping flow is covered by automated tests and browser click-through tests, but most third-party integrations (payments, SMS, shipment tracking, sign-in) have not been verified against real accounts yet. See "Integration status" below, and do your own end-to-end verification before going to production.

> The admin console is in Chinese only. Most of the documentation is in Chinese; this page and the licensing page are the English entry points.

## Screenshots

| Desktop home | Desktop product list |
| --- | --- |
| ![Desktop home](docs/screenshots/pc-home.png) | ![Desktop product list](docs/screenshots/pc-list.png) |

| Mobile home | Mobile categories |
| --- | --- |
| <img src="docs/screenshots/h5-home.png" width="320" alt="Mobile home"> | <img src="docs/screenshots/h5-category.png" width="320" alt="Mobile categories"> |

| Admin dashboard | Admin product list |
| --- | --- |
| ![Admin dashboard](docs/screenshots/admin-home.png) | ![Admin product list](docs/screenshots/admin-goods.png) |

The products shown are the bundled demo data (smart pet supplies), shown here in Chinese. It can be imported or removed with one command.

## Features

**Catalog**
- Categories, brands, variants, per-currency prices, per-region prices (province/city in China, country elsewhere)
- Specifications, accessories, bundles, reviews with follow-ups, favourites, back-in-stock alerts
- Keyword search, popular searches, search history

**Orders**
- Cart, checkout, automatic cancellation of unpaid orders, automatic delivery confirmation
- Shipping templates (by quantity, weight or volume, per region)
- Coupons (fixed amount and percentage), member discounts, paying with points, earning points
- Taxes by shipping destination: tax-exclusive (sales tax) and tax-inclusive (VAT)
- After-sales: refund only, return and refund, refund to the original payment method, merchant-initiated requests
- Invoices (recorded manually, no tax-system integration), order export, receipt printing

**Members and marketing**
- Sign in with email, phone, WeChat mini program or Google
- Member levels with automatic upgrades, points, daily check-in, balance
- Two-level affiliate program: invitations, commissions, settlement, withdrawals
- In-app messages, email notifications, broadcast messages

**Internationalization**
- Storefront languages: Simplified Chinese, English, Spanish, French, German, Japanese, Russian
- Products, categories, articles and policies are maintained per language, with built-in AI translation (OpenAI, DeepSeek, Claude, Qwen)
- Multiple currencies with automatic exchange-rate updates; each order keeps the currency and rate it was placed with
- Times are shown in the buyer's own time zone
- Privacy: consent at sign-up, self-service account deletion, personal data export

**Admin**
- Administrators, roles, menu- and button-level permissions
- Dashboards, trends, product rankings
- Articles, announcements, help center, banners and ad slots
- Payments, SMS, storage, tracking, shipping labels and translation are all configured in the admin console; secrets are stored encrypted
- Open API for ERP, accounting and logistics systems: read orders, post shipments, sync stock, pull payment records; one key per system with its own permissions; optional signed webhooks when an order is paid, shipped or refunded

## Integration status

Everything below is implemented and has automated tests, but in those tests the third-party side is simulated. **"Not verified" means it has not been run against a real account**; details follow each provider's public documentation and may need adjusting on first use.

| Area | Integrated | Status |
| --- | --- | --- |
| Payments | Stripe, PayPal, WeChat Pay (mini program, mobile web, QR code, in-WeChat), Alipay (mobile and desktop web) | Not verified |
| Sign-in | WeChat mini program, Google | Not verified |
| SMS | GM SMS platform (verification codes, mainland China numbers) | Not verified |
| Shipment tracking | Kuaidi100 (China), 17TRACK (international) | Not verified |
| Shipping labels | Kuaidi100 | Not verified |
| AI translation | OpenAI, DeepSeek, Claude, Qwen | Claude and Qwen not verified |
| Exchange rates | Frankfurter, ExchangeRate-API (free public APIs) | Working |
| Storage | Local disk, Alibaba Cloud OSS and other cloud storage | Local disk works; cloud storage not verified |

Also worth knowing:
- The WeChat mini program has only been compiled, never run in WeChat DevTools or on a device.
- The Spanish, French, German, Japanese and Russian translations were produced by AI and have not been reviewed by native speakers.
- There is no native app, and no Apple sign-in yet.

## Tech stack

| Part | Directory | Stack |
| --- | --- | --- |
| Backend API | `home/` | Laravel 13, PHP 8.4+, MySQL 8 |
| Admin console | `admin-web/` | Vue 3, Element Plus, Vite, Pinia |
| Desktop storefront | `mall-pc/` | Vue 3, Element Plus, Vite |
| Mobile web / mini program | `mall-uni/` | uni-app (Vue 3, CLI project, no HBuilderX required) |
| Browser click-through tests | `e2e/` | Playwright (uses the locally installed Edge) |

The backend is layered as middleware → controller → form request → service → model. Money is always handled as strings with bcmath, never as floats.

## Quick start (local development)

You need PHP 8.4+ (with bcmath, openssl, pdo_mysql, mbstring and fileinfo), Composer, MySQL 8 and Node.js 20+.

```bash
# 1. Backend
cd home
composer install
cp .env.example .env          # then set the DB_* values
php artisan key:generate
php artisan migrate
php artisan db:seed           # creates the "admin" user; the password is printed once, write it down
php artisan mall:demo-import  # optional: import demo products and content
php artisan serve             # http://127.0.0.1:8000

# 2. The three frontends, each in its own terminal. They proxy API calls to http://127.0.0.1:8000 by default;
#    if your backend runs elsewhere, create .env.development.local in each directory with VITE_API_PROXY=<your backend URL>
cd admin-web && npm install && npm run dev      # admin    http://127.0.0.1:5173
cd mall-pc   && npm install && npm run dev      # desktop  http://127.0.0.1:5190
cd mall-uni  && npm install && npm run dev:h5   # mobile   http://127.0.0.1:5180
```

When you open the frontends on `127.0.0.1`, add `UPLOADS_URL=/uploads` and `PAY_RETURN_HOSTS=127.0.0.1` to `home/.env`.

The default language is Simplified Chinese. To show the storefront in another language, sign in to the admin console and enable it under 设置 → 语言管理 (Settings → Languages); the language codes are `en`, `es`, `fr`, `de`, `ja` and `ru`.

For server deployment see [docs/部署说明.md](docs/部署说明.md) (Chinese).

## Tests

```bash
# Backend: create an empty database named gmmall_test first; tests refuse to run on a database whose name does not end in _test
cd home && php artisan test

# Storefront copy: checks that all seven languages have every entry
cd mall-uni && npm run check:locale

# Browser click-through tests: start the three frontends first; development databases only
cd e2e && npm install && npm run all
```

## Documentation

- [Licensing](docs/licensing.en.md)
- [Deployment guide](docs/部署说明.md) (Chinese)
- [Open API](docs/开放接口.md) (Chinese): for integrating ERP, accounting and logistics systems
- [Changelog](CHANGELOG.md) (Chinese)
- [Development notes](CLAUDE.md) (Chinese): business rules, design trade-offs and known limitations of each module

## Contributing

Issues and pull requests are welcome, in English or Chinese. See [CONTRIBUTING.md](CONTRIBUTING.md) (Chinese). Because the project also sells commercial licenses, you need to agree to the [contributor agreement](docs/贡献者协议.md) before your code can be merged: you keep the copyright in your contribution and allow the author to license it under any terms, including the commercial license.

Please do not report security problems in public issues. Use "Security → Report a vulnerability" on the repository page instead; see [SECURITY.md](SECURITY.md).

## License

Licensed under the [PolyForm Noncommercial License 1.0.0](LICENSE.md):

- **Personal study, research and nonprofit use** are free. You may modify and redistribute the code as long as you keep `LICENSE.md` and `NOTICE.md`.
- **Commercial use** (running a store on it, using it in a business, building it for paying clients and so on) requires a commercial license.

Prices and terms are on the [licensing page](docs/licensing.en.md). License fees go towards maintaining and developing this project.
