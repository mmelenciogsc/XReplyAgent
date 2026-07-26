# XReplyAgent Implementation Inventory

## Requirement Map

### Plugin Shell And Bootstrap

- `xreplyagent.php`
- `src/Plugin.php`
- `src/Support/Autoloader.php`
- `src/Support/View.php`

Purpose:
- Register activation/deactivation hooks.
- Load the autoloader.
- Register admin menus, REST routes, assets, shortcodes, and scheduled cleanup.
- Render the public, auth, and admin shells without coupling business logic to the active theme.

### Security, Capabilities, And Settings

- `src/Support/Capabilities.php`
- `src/Support/Settings.php`

Purpose:
- Install custom capabilities and custom XReplyAgent roles.
- Store provider, model, key, limits, cost, retention, mock mode, and access settings.
- Keep capability checks separate from display code.

### Schema And Storage

- `src/Support/Schema.php`
- `src/Storage/Store.php`
- `src/Support/Redactor.php`
- `src/Support/Similarity.php`

Purpose:
- Create and migrate custom tables.
- Persist posts, analyses, reply sets, reply candidates, personas, prompt versions, metrics, AI requests, audit logs, and error logs.
- Redact sensitive fields before logs or exports.
- Deduplicate by content hash and normalize similar reply text.

### AI Orchestration

- `src/AI/ProviderInterface.php`
- `src/AI/ProviderFactory.php`
- `src/AI/OpenAIProvider.php`
- `src/AI/MockProvider.php`
- `src/AI/SchemaValidator.php`
- `src/AI/Service.php`

Purpose:
- Route provider selection through a provider-agnostic interface.
- Use OpenAI GPT-4o mini as the live default.
- Fall back to mock mode when no key is configured or mock mode is enabled.
- Enforce strict JSON-schema validation with one controlled repair attempt for malformed output.
- Support stage-based analysis, generation, scoring, and recommendations.

### Workflow, Rate Limits, And Maintenance

- `src/Domain/Workflow.php`
- `src/Domain/RateLimiter.php`
- `src/Domain/Seeder.php`
- `src/Domain/Maintenance.php`
- `src/Domain/Exporter.php`

Purpose:
- Submit posts into the three-candidate reply pipeline.
- Enforce daily AI limits per user or guest identity.
- Seed demo rows and reset plugin data.
- Run retention cleanup.
- Export CSV data.

### Controllers And Routes

- `src/Public/Controller.php`
- `src/Admin/Controller.php`
- `src/Rest/Controller.php`

Purpose:
- Serve a branded public shell, auth shell, and admin shell.
- Register the site launcher and page form handlers.
- Expose REST routes for health, workflow, review, analytics, history, metrics, seed, and reset.

### Views And Assets

- `templates/page.php`
- `views/public-shell.php`
- `views/admin-shell.php`
- `views/auth-shell.php`
- `assets/css/xreplyagent.css`
- `assets/js/xreplyagent.js`

Purpose:
- Render semantic server-side shells.
- Keep the dark, restrained UI responsive and accessible.
- Provide fullscreen, copy, workflow, and connection-test interactions with minimal JavaScript.

### Tests

- `tests/bootstrap.php`
- `tests/MockProviderTest.php`
- `tests/SchemaValidatorTest.php`
- `tests/ServiceTest.php`

Purpose:
- Validate stage-specific mock responses.
- Validate JSON schema handling.
- Validate the single repair attempt path.

## Data Tables

- `wp_xra_posts`
- `wp_xra_analyses`
- `wp_xra_reply_sets`
- `wp_xra_reply_candidates`
- `wp_xra_personas`
- `wp_xra_prompt_versions`
- `wp_xra_performance_metrics`
- `wp_xra_ai_requests`
- `wp_xra_audit_log`
- `wp_xra_error_log`

## Screens

### Public

- Overview
- Analyze
- History
- Analytics

### Admin

- Overview
- Analyze
- Review Queue
- History
- Analytics
- Personas
- Prompts
- Settings
- Audit
- Error Log

### Auth

- Branded login/register shell that routes into site accounts without exposing the default login surface.

### Walkthroughs

- `docs/walkthroughs/`

Purpose:
- Define the customer and admin capture flows used to produce the narrated walkthrough videos and screenshots.
- Start the customer walkthrough from the public guest view before the sign-in transition.
- Allow the admin walkthrough to show the public guest view briefly before sign-in, if desired.

## Served URL

- Primary app URL: `https://audsnaps.ariadev.tools/apps/xreplyagent`
- Site-relative path: `/apps/xreplyagent/`

## Demo Accounts

- User demo account: `demouser` / `demouser`
- Admin demo account: `demoadmin` / `demoadmin`

## API Endpoints

- `GET /wp-json/xreplyagent/v1/health`
- `POST /wp-json/xreplyagent/v1/workflow`
- `POST /wp-json/xreplyagent/v1/analyze`
- `POST /wp-json/xreplyagent/v1/review`
- `GET /wp-json/xreplyagent/v1/analytics`
- `GET /wp-json/xreplyagent/v1/history`
- `POST /wp-json/xreplyagent/v1/metrics`
- `POST /wp-json/xreplyagent/v1/seed`
- `POST /wp-json/xreplyagent/v1/reset`

## Configuration Locations

- OpenAI key option: `xra_ai_api_key`
- OpenAI key file option: `xra_ai_api_key_file`
- Model option: `xra_ai_model`
- Endpoint option: `xra_ai_endpoint`
- Mock mode: `xra_mock_mode`
- Daily limit: `xra_ai_daily_limit`
- Retention: `xra_retention_days`
- Cost estimates: `xra_cost_per_1k_prompt_tokens`, `xra_cost_per_1k_completion_tokens`
- Public access: `xra_public_access`
- Retain on uninstall: `xra_retain_data_on_uninstall`
- Default tone: `xra_default_tone`

## Validation

- `php -l` on all changed PHP files
- `phpcs --standard=phpcs.xml.dist src xreplyagent.php`
- `phpunit --configuration phpunit.xml.dist`
