# V16 Fasthosts and Shared-Hosting Deployment

## Constraint-first policy

A Fasthosts production or staging site may map a domain/subdomain to a folder under `htdocs`. Do not assume the control panel can point that mapping directly at V16's `public/` directory. Confirm the actual document root, PHP version, filesystem access rules, rewrite support, and PHP access to paths outside `htdocs` before selecting a layout.

Only public entry points and public assets may be reachable over HTTP. `app/`, `src/`, `config/`, `database/`, `storage/`, `tests/`, `docs/`, `vendor/`, `.env`, Composer files, and Git metadata must not be web-accessible.

## Preferred layout

When PHP may read files outside the document root, keep releases and mutable data outside `htdocs`:

```text
account-home/
├── pyh-v16/
│   ├── releases/<release-id>/    # app, src, config, database, vendor
│   ├── shared/.env               # hosting permissions restrict access
│   ├── shared/storage/           # logs/cache, not web-served
│   └── current -> releases/<release-id>
└── htdocs/<mapped-site>/
    ├── index.php                 # minimal loader for current/public/index.php
    └── assets/                   # explicitly public static files only
```

The public `index.php` must resolve a fixed server-side path, not a request-controlled path. Deployment copies only reviewed public assets into the mapped web folder. Secrets remain in hosting environment variables where supported, otherwise in `shared/.env` outside `htdocs` with the narrowest available file permissions.

## Fallback when code must live under `htdocs`

If hosting restrictions prevent PHP from loading code outside `htdocs`, place the release in a deliberately non-public subtree and deny HTTP access at the web-server layer:

```text
htdocs/<mapped-site>/
├── index.php
├── assets/
└── _private/
    ├── .htaccess                 # deny all requests
    ├── app/ src/ config/ database/ vendor/
    └── storage/
```

This fallback is acceptable only after a deployed probe proves direct requests to representative private files, dotfiles, backup suffixes, and nested paths return denial responses. Directory listing must be disabled. If the host ignores access-control files or exposes `_private`, deployment must stop; obscurity is not a security boundary. Keep `.env` outside `htdocs` whenever the platform permits it. Never place `.git/`, tests, documentation, database exports, or deployment credentials in the hosted tree.

## Release procedure

Build dependencies in a trusted CI/deployment environment with production Composer settings, transfer an immutable release, configure writable shared storage, inject environment values, and run guarded migrations from CLI. Staging uses `CONFIRM_STAGING_DATABASE_CHANGES=true` only for that operation. Production uses `ALLOW_PRODUCTION_DATABASE_CHANGES=true` only in an explicitly approved deployment step. Remove flags immediately afterward.

Verify HTTPS, PHP 8.3, disabled directory indexes, denial of private paths and dotfiles, non-disclosure of errors, writable non-public logs/cache, security headers, and application health. Rollback switches the public loader/current release only after checking database compatibility; schema rollback is never assumed.
