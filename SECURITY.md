# Security Policy

## Reporting a Vulnerability

Report security vulnerabilities privately via [GitHub Security Advisories](https://github.com/splitline-php/splitline/security/advisories/new) rather than opening a public issue. Include steps to reproduce, affected versions or commits, and potential impact.

## Deployment Security

Splitline stores sensitive biometrics and GNSS routes that can reveal home and workplace locations. When self-hosting, you are responsible for securing access to your instance:

- **Authentication & TLS**: Splitline has no built-in login and serves plain HTTP on port `8080`. Never expose port `8080` directly to the public internet; restrict access and terminate HTTPS upstream.
- **Reverse proxy headers**: When serving behind HTTPS termination, set the following in `.env` (prefer your proxy's specific IP/CIDR over `*` for `TRUSTED_PROXIES` where known):

```dotenv
APP_URL=https://splitline.example.com
TRUSTED_PROXIES=*
SESSION_SECURE_COOKIE=true
```

- **Secrets**: Keep `GARMIN_KEY` in a host `.env` (`chmod 600 .env`) or secret manager; never commit it or bake it into an image. Keep `/data/.app_key` (`0600`) private and back it up alongside your database.
- **Backups**: Encrypt offsite backups of `splitline_data` (`/data/database.sqlite`) and `splitline_fits` (`/var/www/html/storage/app/private`).
- **Updates**: Keep your container image updated for OS, runtime, and dependency security patches.
