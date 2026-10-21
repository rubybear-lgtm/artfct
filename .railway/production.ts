import { defineRailway, github, postgres, preserve, project, service, volume } from "railway/iac";

export default defineRailway(() => {
  const artfct = github("rubybear-lgtm/artfct", { checkSuites: false });

  const PostgresTsII = postgres("Postgres-tsII", { region: "us-west2" });
  PostgresTsII.networking = { privateNetworkEndpoint: "postgres-tsii" };
  const postgresVolumeOajp = volume("postgres-volume-Oajp", { alerts: { usage: { "100": {}, "80": {}, "95": {} } }, allowOnlineResize: true, region: "us-west2", sizeMB: 5000 });
  const queue = service("queue", {
    source: artfct,
    build: "composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction && npm install --no-audit --no-fund && npm run build",
    start: "php artisan optimize && php artisan queue:work --queue=events,indexing,default --tries=3 --sleep=3 --max-time=3600",
    replicas: { "us-west2": 1 },
    deploy: { restartPolicyType: "ALWAYS" },
    env: { APP_DEBUG: preserve(), APP_ENV: preserve(), APP_KEY: preserve(), APP_NAME: preserve(), APP_URL: preserve(), ARTFCT_ARTIFACT_ORIGIN_SUFFIX: preserve(), ARTFCT_ARTIFACT_TOKEN_SECRET: preserve(), ARTFCT_GOVERNANCE_SECRET: preserve(), ARTFCT_JWKS_WRITE_SECRET: preserve(), ARTFCT_LIMITS_WRITE_SECRET: preserve(), ARTFCT_REVOCATION_WRITE_SECRET: preserve(), ARTFCT_WORKER_BASE_URL: preserve(), ARTFCT_WORKER_EVENT_SECRET: preserve(), CACHE_STORE: preserve(), CLOUDFLARE_ACCOUNT_ID: preserve(), CLOUDFLARE_API_TOKEN: preserve(), CLOUDFLARE_EMAIL_API_TOKEN: preserve(), COMPOSER_NO_DEV: preserve(), DB_CONNECTION: preserve(), DB_URL: preserve(), INDEXING_ENABLED: preserve(), LEGAL_CONSENT_REQUIRED: preserve(), LOG_CHANNEL: preserve(), LOG_LEVEL: preserve(), MAIL_FROM_ADDRESS: preserve(), MAIL_FROM_NAME: preserve(), MAIL_MAILER: preserve(), NIXPACKS_NODE_VERSION: preserve(), OAUTH_ISSUER: preserve(), OAUTH_REGISTRATION_PER_HOUR: preserve(), ORG_JWT_KID: preserve(), ORG_JWT_PRIVATE_KEY_B64: preserve(), QUEUE_CONNECTION: preserve(), RAILPACK_NODE_VERSION: preserve(), SESSION_DRIVER: preserve(), SESSION_SECURE_COOKIE: preserve(), STRIPE_SECRET_KEY: preserve(), STRIPE_TEAM_PRICE_ID: preserve(), STRIPE_WEBHOOK_SECRET: preserve(), VITE_APP_NAME: preserve(), WORKOS_API_KEY: preserve(), WORKOS_CLIENT_ID: preserve(), WORKOS_REDIRECT_URL: preserve() },
  });
  const scheduler = service("scheduler", {
    source: artfct,
    build: "composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction && npm install --no-audit --no-fund && npm run build",
    start: "php artisan optimize && php artisan schedule:work",
    replicas: { "us-west2": 1 },
    deploy: { restartPolicyType: "ALWAYS" },
    env: { APP_DEBUG: preserve(), APP_ENV: preserve(), APP_KEY: preserve(), APP_NAME: preserve(), APP_URL: preserve(), ARTFCT_ARTIFACT_ORIGIN_SUFFIX: preserve(), ARTFCT_ARTIFACT_TOKEN_SECRET: preserve(), ARTFCT_GOVERNANCE_SECRET: preserve(), ARTFCT_JWKS_WRITE_SECRET: preserve(), ARTFCT_LIMITS_WRITE_SECRET: preserve(), ARTFCT_REVOCATION_WRITE_SECRET: preserve(), ARTFCT_WORKER_BASE_URL: preserve(), ARTFCT_WORKER_EVENT_SECRET: preserve(), CACHE_STORE: preserve(), CLOUDFLARE_ACCOUNT_ID: preserve(), CLOUDFLARE_API_TOKEN: preserve(), CLOUDFLARE_EMAIL_API_TOKEN: preserve(), COMPOSER_NO_DEV: preserve(), DB_CONNECTION: preserve(), DB_URL: preserve(), INDEXING_ENABLED: preserve(), LEGAL_CONSENT_REQUIRED: preserve(), LOG_CHANNEL: preserve(), LOG_LEVEL: preserve(), MAIL_FROM_ADDRESS: preserve(), MAIL_FROM_NAME: preserve(), MAIL_MAILER: preserve(), NIXPACKS_NODE_VERSION: preserve(), OAUTH_ISSUER: preserve(), OAUTH_REGISTRATION_PER_HOUR: preserve(), ORG_JWT_KID: preserve(), ORG_JWT_PRIVATE_KEY_B64: preserve(), QUEUE_CONNECTION: preserve(), RAILPACK_NODE_VERSION: preserve(), SESSION_DRIVER: preserve(), SESSION_SECURE_COOKIE: preserve(), STRIPE_SECRET_KEY: preserve(), STRIPE_TEAM_PRICE_ID: preserve(), STRIPE_WEBHOOK_SECRET: preserve(), VITE_APP_NAME: preserve(), WORKOS_API_KEY: preserve(), WORKOS_CLIENT_ID: preserve(), WORKOS_REDIRECT_URL: preserve() },
  });
  const web = service("web", {
    source: github("rubybear-lgtm/artfct", { checkSuites: true }),
    build: "composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction && npm install --no-audit --no-fund && npm run build:ssr",
    start: "php artisan optimize && (php artisan inertia:start-ssr &) && php artisan serve --host=0.0.0.0 --port=${PORT}",
    healthcheck: "/up",
    healthcheckTimeout: 300,
    preDeploy: "sh -c 'php artisan migrate --force && php artisan auth:publish-jwks'",
    replicas: { "us-west2": 1 },
    deploy: { limitOverride: { containers: { cpu: 1, memoryBytes: 2000000000 } }, restartPolicyMaxRetries: 3 },
    domains: ["artfct.dev"],
    env: { APP_DEBUG: preserve(), APP_ENV: preserve(), APP_KEY: preserve(), APP_NAME: preserve(), APP_URL: preserve(), ARTFCT_ARTIFACT_ORIGIN_SUFFIX: preserve(), ARTFCT_ARTIFACT_TOKEN_SECRET: preserve(), ARTFCT_GOVERNANCE_SECRET: preserve(), ARTFCT_JWKS_WRITE_SECRET: preserve(), ARTFCT_LIMITS_WRITE_SECRET: preserve(), ARTFCT_REVOCATION_WRITE_SECRET: preserve(), ARTFCT_WORKER_BASE_URL: preserve(), ARTFCT_WORKER_EVENT_SECRET: preserve(), CACHE_STORE: preserve(), CLOUDFLARE_ACCOUNT_ID: preserve(), CLOUDFLARE_API_TOKEN: preserve(), CLOUDFLARE_EMAIL_API_TOKEN: preserve(), COMPOSER_ALLOW_SUPERUSER: preserve(), COMPOSER_NO_DEV: preserve(), DB_CONNECTION: preserve(), DB_URL: preserve(), INDEXING_ENABLED: preserve(), LEGAL_CONSENT_REQUIRED: preserve(), LOG_CHANNEL: preserve(), LOG_LEVEL: preserve(), LOG_STACK: preserve(), MAIL_FROM_ADDRESS: preserve(), MAIL_FROM_NAME: preserve(), MAIL_MAILER: preserve(), NIXPACKS_NODE_VERSION: preserve(), NIXPACKS_PHP_VERSION: preserve(), OAUTH_ISSUER: preserve(), OAUTH_REGISTRATION_PER_HOUR: preserve(), ORG_JWT_KID: preserve(), ORG_JWT_PRIVATE_KEY_B64: preserve(), QUEUE_CONNECTION: preserve(), RAILPACK_NODE_VERSION: preserve(), SESSION_DRIVER: preserve(), SESSION_SECURE_COOKIE: preserve(), STRIPE_SECRET_KEY: preserve(), STRIPE_TEAM_PRICE_ID: preserve(), STRIPE_WEBHOOK_SECRET: preserve(), VITE_APP_NAME: preserve(), VITE_WORKER_URL: preserve(), WORKOS_API_KEY: preserve(), WORKOS_CLIENT_ID: preserve(), WORKOS_REDIRECT_URL: preserve() },
  });

  return project("artfct", {
    resources: [queue, scheduler, web, PostgresTsII, postgresVolumeOajp],
  });
});
