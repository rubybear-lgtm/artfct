import { defineRailway, github, image, postgres, preserve, project, service, volume } from "railway/iac";

export default defineRailway(() => {
  const artfct = github("rubybear-lgtm/artfct", { branch: "develop", checkSuites: false });

  const PostgresXPCc = postgres("Postgres-XPCc", { region: "us-west2" });
  PostgresXPCc.networking = { privateNetworkEndpoint: "postgres-xpcc" };
  const Postgres = postgres("Postgres", { region: "us-west2" });
  Postgres.networking = { privateNetworkEndpoint: "postgres" };
  const postgresVolumeFhEc = volume("postgres-volume-FhEc", { alerts: { usage: { "100": {}, "80": {}, "95": {} } }, allowOnlineResize: true, region: "us-west2", sizeMB: 5000 });
  const postgresVolume = volume("postgres-volume", { alerts: { usage: { "100": {}, "80": {}, "95": {} } }, allowOnlineResize: true, region: "us-west2", sizeMB: 5000 });
  const stagingWeb = service("staging-web", {
    source: artfct,
    build: "composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction && npm install --no-audit --no-fund && npm run build:ssr",
    start: "php artisan optimize && (php artisan inertia:start-ssr &) && php artisan serve --host=0.0.0.0 --port=${PORT}",
    healthcheck: "/up",
    healthcheckTimeout: 300,
    preDeploy: "sh -c 'php artisan migrate --force && php artisan auth:publish-jwks'",
    replicas: { "us-west2": 1 },
    deploy: { sleepApplication: true, restartPolicyMaxRetries: 3 },
    domains: ["staging.artfct.dev"],
    env: { APP_DEBUG: preserve(), APP_ENV: preserve(), APP_KEY: preserve(), APP_NAME: preserve(), APP_URL: preserve(), ARTFCT_ARTIFACT_ORIGIN_SUFFIX: preserve(), ARTFCT_ARTIFACT_TOKEN_SECRET: preserve(), ARTFCT_GOVERNANCE_SECRET: preserve(), ARTFCT_JWKS_WRITE_SECRET: preserve(), ARTFCT_LIMITS_WRITE_SECRET: preserve(), ARTFCT_ORG_TOKEN: preserve(), ARTFCT_ORIGIN_REFERENCE_PROBE: preserve(), ARTFCT_REVOCATION_WRITE_SECRET: preserve(), ARTFCT_WORKER_BASE_URL: preserve(), ARTFCT_WORKER_EVENT_SECRET: preserve(), AUTHKIT_DEV_LOGIN_DOMAINS: preserve(), CACHE_STORE: preserve(), CLOUDFLARE_ACCOUNT_ID: preserve(), CLOUDFLARE_API_TOKEN: preserve(), CLOUDFLARE_EMAIL_API_TOKEN: preserve(), COMPOSER_NO_DEV: preserve(), DB_CONNECTION: preserve(), DB_URL: preserve(), INDEXING_ENABLED: preserve(), LEGAL_CONSENT_REQUIRED: preserve(), LOG_CHANNEL: preserve(), LOG_LEVEL: preserve(), MAIL_FROM_ADDRESS: preserve(), MAIL_FROM_NAME: preserve(), MAIL_MAILER: preserve(), NIXPACKS_NODE_VERSION: preserve(), OAUTH_ISSUER: preserve(), OAUTH_REGISTRATION_PER_HOUR: preserve(), ORG_JWT_KID: preserve(), ORG_JWT_PRIVATE_KEY_B64: preserve(), POLIS_API_KEY: preserve(), POLIS_BASE_URL: preserve(), POLIS_CLIENT_SECRET_VERIFIER: preserve(), POLIS_WEBHOOK_SECRET: preserve(), QUEUE_CONNECTION: preserve(), RAILPACK_NODE_VERSION: preserve(), SESSION_DRIVER: preserve(), SESSION_SECURE_COOKIE: preserve(), STAGING_VERIFY_USAGE: preserve(), STRIPE_SECRET_KEY: preserve(), STRIPE_TEAM_PRICE_ID: preserve(), STRIPE_WEBHOOK_SECRET: preserve(), VITE_APP_NAME: preserve(), WORKOS_API_KEY: preserve(), WORKOS_CLIENT_ID: preserve(), WORKOS_REDIRECT_URL: preserve() },
  });
  const stagingScheduler = service("staging-scheduler", {
    source: artfct,
    build: "composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction && npm install --no-audit --no-fund && npm run build",
    start: "php artisan optimize && php artisan schedule:work",
    replicas: { "us-west2": 1 },
    deploy: { restartPolicyType: "ALWAYS" },
    env: { APP_DEBUG: preserve(), APP_ENV: preserve(), APP_KEY: preserve(), APP_NAME: preserve(), APP_URL: preserve(), ARTFCT_GOVERNANCE_SECRET: preserve(), ARTFCT_LIMITS_WRITE_SECRET: preserve(), ARTFCT_ORG_TOKEN: preserve(), ARTFCT_WORKER_BASE_URL: preserve(), ARTFCT_WORKER_EVENT_SECRET: preserve(), AUTHKIT_DEV_LOGIN_DOMAINS: preserve(), CACHE_STORE: preserve(), CLOUDFLARE_ACCOUNT_ID: preserve(), CLOUDFLARE_API_TOKEN: preserve(), CLOUDFLARE_EMAIL_API_TOKEN: preserve(), COMPOSER_NO_DEV: preserve(), DB_CONNECTION: preserve(), DB_URL: preserve(), INDEXING_ENABLED: preserve(), LOG_CHANNEL: preserve(), LOG_LEVEL: preserve(), MAIL_FROM_ADDRESS: preserve(), MAIL_FROM_NAME: preserve(), MAIL_MAILER: preserve(), NIXPACKS_NODE_VERSION: preserve(), POLIS_API_KEY: preserve(), POLIS_BASE_URL: preserve(), POLIS_CLIENT_SECRET_VERIFIER: preserve(), QUEUE_CONNECTION: preserve(), RAILPACK_NODE_VERSION: preserve(), SESSION_DRIVER: preserve(), STRIPE_SECRET_KEY: preserve(), STRIPE_TEAM_PRICE_ID: preserve(), STRIPE_WEBHOOK_SECRET: preserve(), WORKOS_API_KEY: preserve(), WORKOS_CLIENT_ID: preserve(), WORKOS_REDIRECT_URL: preserve() },
  });
  const stagingQueue = service("staging-queue", {
    source: artfct,
    build: "composer install --no-dev --prefer-dist --optimize-autoloader --no-interaction && npm install --no-audit --no-fund && npm run build",
    start: "php artisan optimize && php artisan queue:work --queue=events,indexing,default --tries=3 --sleep=3 --max-time=3600",
    replicas: { "us-west2": 1 },
    deploy: { restartPolicyType: "ALWAYS" },
    env: { APP_DEBUG: preserve(), APP_ENV: preserve(), APP_KEY: preserve(), APP_NAME: preserve(), APP_URL: preserve(), ARTFCT_ARTIFACT_ORIGIN_SUFFIX: preserve(), ARTFCT_ARTIFACT_TOKEN_SECRET: preserve(), ARTFCT_GOVERNANCE_SECRET: preserve(), ARTFCT_JWKS_WRITE_SECRET: preserve(), ARTFCT_LIMITS_WRITE_SECRET: preserve(), ARTFCT_ORG_TOKEN: preserve(), ARTFCT_ORIGIN_REFERENCE_PROBE: preserve(), ARTFCT_REFERENCE_RESOLVE_TOUCH: preserve(), ARTFCT_REVOCATION_WRITE_SECRET: preserve(), ARTFCT_WORKER_BASE_URL: preserve(), ARTFCT_WORKER_EVENT_SECRET: preserve(), AUTHKIT_DEV_LOGIN_DOMAINS: preserve(), CACHE_STORE: preserve(), CLOUDFLARE_ACCOUNT_ID: preserve(), CLOUDFLARE_API_TOKEN: preserve(), CLOUDFLARE_EMAIL_API_TOKEN: preserve(), COMPOSER_NO_DEV: preserve(), DB_CONNECTION: preserve(), DB_URL: preserve(), INDEXING_ENABLED: preserve(), LOG_CHANNEL: preserve(), LOG_LEVEL: preserve(), MAIL_FROM_ADDRESS: preserve(), MAIL_FROM_NAME: preserve(), MAIL_MAILER: preserve(), NIXPACKS_NODE_VERSION: preserve(), ORG_JWT_KID: preserve(), ORG_JWT_PRIVATE_KEY_B64: preserve(), POLIS_API_KEY: preserve(), POLIS_BASE_URL: preserve(), POLIS_CLIENT_SECRET_VERIFIER: preserve(), POLIS_WEBHOOK_SECRET: preserve(), QUEUE_CONNECTION: preserve(), RAILPACK_NODE_VERSION: preserve(), SESSION_DRIVER: preserve(), STRIPE_SECRET_KEY: preserve(), STRIPE_TEAM_PRICE_ID: preserve(), STRIPE_WEBHOOK_SECRET: preserve(), WORKOS_API_KEY: preserve(), WORKOS_CLIENT_ID: preserve(), WORKOS_REDIRECT_URL: preserve() },
  });
  const stagingPolis = service("staging-polis", {
    source: image("boxyhq/jackson:26.2.0"),
    healthcheck: "/api/health",
    replicas: { "us-west2": 1 },
    deploy: { sleepApplication: true },
    env: { API_KEYS: preserve(), CLIENT_SECRET_VERIFIER: preserve(), DB_ENCRYPTION_KEY: preserve(), DB_ENGINE: preserve(), DB_TYPE: preserve(), DB_URL: preserve(), EXTERNAL_URL: preserve(), JACKSON_API_KEYS: preserve(), NEXTAUTH_ADMIN_CREDENTIALS: preserve(), NEXTAUTH_SECRET: preserve(), NEXTAUTH_URL: preserve(), PORT: preserve(), SAML_AUDIENCE: preserve() },
  });

  return project("artfct", {
    resources: [stagingWeb, stagingScheduler, stagingQueue, PostgresXPCc, stagingPolis, Postgres, postgresVolumeFhEc, postgresVolume],
  });
});
