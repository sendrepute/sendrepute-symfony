# SendRepute Symfony Mailer adapter

`sendrepute/symfony` 0.1.1 is a standalone Symfony bundle that can inspect an
explicitly opted-in `Email` at Symfony Mailer's official pre-send
`MessageEvent`. It calls the paid SendRepute customer classifier; it never
sends mail itself and does not guarantee inbox placement.

## Compatibility and installation

The supported baseline is Symfony **6.4 or 7.4** with PHP 8.1+ (Symfony 7.4
itself requires PHP 8.2+). There is no Symfony 8.1 compatibility claim.

Install this local source or ZIP as a Composer path/artifact repository and
register `SendRepute\Symfony\SendReputeBundle` if Symfony Flex does not do so.
The package has not been published to a registry.

## Configuration

Store the token only in server-side secrets (for example a Symfony secret or
environment variable resolved by your application's configuration), then pass
the resolved value as `api_token`:

```yaml
send_repute:
  enabled: false
  paid_analysis_consent: false
  api_token: '%env(SENDREPUTE_API_TOKEN)%'
  mode: advisory
  failure_policy: allow
  spam_probability_threshold: 0.8
```

Both paid consent and the adapter are off by default. `mode` controls the score
decision (`advisory` or `blocking`); the independent `failure_policy` controls
errors (`allow` or `block`). Thresholds must be 0 through 1. The URL is fixed
to `https://www.sendrepute.com/api/v1/classify`, TLS verification is inherited
from Symfony HttpClient, redirects are disabled, no retry client is installed,
deadlines are bounded, and responses are capped at 1 MiB.

## Per-message opt-in

Only messages containing `X-SendRepute-Classify: yes` are eligible:

```php
$email->getHeaders()->addTextHeader('X-SendRepute-Classify', 'yes');
$mailer->send($email);
```

This explicit route protects password resets, MFA, security and account mail by
default: unmarked mail never incurs a paid call or a block. The adapter does
not alter the message, envelope, or selected transport, so the opt-in header
remains in the delivered message. Use a different valid header name through
`opt_in_header` if required.

## MIME and queue behavior

Exactly one non-empty in-memory text or HTML body is supported. Binary
attachments are not transmitted. Messages containing both displayed text and
HTML alternatives, textual inline/attachment `DataPart` instances, or
resource/path-backed bodies are rejected before a paid request and then follow
`failure_policy`. Any displayed body containing CSS braces, quoted-printable
syntax, a base64 transfer header, or a base64-looking whole body is likewise
rejected; plain text also rejects markup delimiters. Ordinary braces-free text
and HTML remain unchanged. This is deliberate: the current API
`visibleEmailText` normalizer decodes QP/base64 and strips markup/CSS over one
string, so braces can erase content and encoded input can become hidden markup.
The adapter never approves a whole message from only one alternative.

With synchronous transports, classification happens in the transport's
pre-send event. With Messenger, the initial queued clone event is skipped to
avoid paying twice; classification occurs only in the worker's actual transport
event. A worker outage or a producer-only event therefore cannot produce a
classification. Configure the bundle in workers too.

## API contract and evidence

The request is exactly `sender` (display name), `subject`, and `body`, with an
optional model. The response parser type-checks top-level `requestId`, `model`,
`result`, and `billing`; all required result fields (`label`, bounded
`spamProbability`, confidence, typed reasons/terms/fields, model version and
analysis timestamp) and billing fields are checked. Optional flag counts and
the complete content-audit object are checked when present, and unknown
properties are rejected. This matches
`artifacts/api-server/src/customer-api-openapi.json` `POST /v1/classify`.

`composer test` uses real Symfony Mailer, EventDispatcher, HttpClient
`MockHttpClient`/`MockResponse`, and the checked-in response fixture. It proves
the public path/payload, nested schema parsing, official `event.reject()`
transport cancellation, opt-in, unchanged messages, independent policies,
queued-clone skipping, redirect/size refusal, typed responses, textual DataPart
rejection, and mixed/CSS/QP/base64/markup pre-payment rejection. A Node bridge
imports the checked-in production `visibleEmailText` function itself and runs
exact CSS-brace, quoted-printable, MIME-base64-header, and global-base64
payloads; no copied normalizer or source double is used. No test performs a
paid network call or sends email. The normalizer claim is scoped to this actual
checked source and these payloads, not a broad server-version certification.

The runtime suite (37 PHPUnit tests/88 assertions plus four actual-normalizer
payloads) passed against exact Composer-resolved Symfony
Mailer/FrameworkBundle 7.4.19 and 6.4.46. Those sources expose
`MessageEvent::reject()` and `isQueued()`; the real transport test exercises
rejection rather than substituting a local event double. These two tested lines
are the entire compatibility claim.

Build the deterministic source-only archive with `composer package`. Its closed
allowlist excludes tests, dependencies, caches, local environment files, and
credentials.
## Customer API client and operator console

The `SendRepute\Symfony\CustomerApi\CustomerApiClient` service covers all 49 customer API operations, including paid-result recovery. It uses the bundle's `HttpClientInterface`.

Its safeguards:

- It uses one fixed HTTPS origin.
- It never retries a request, refuses redirects and caps response size.
- Parameters and body fields must match the contract, and bodies are limited to 512 KiB.
- Paid operations require an explicit `paidConsent` price.
- Billing and recovery actions require `confirm`.

```yaml
send_repute:
  customer_api:
    api_key: '%env(SENDREPUTE_CUSTOMER_API_KEY)%'
    console:
      enabled: true
      handoff_return_origin: null   # exact https origin to enable hosted handoff
```

Import the routes behind your firewall:

```yaml
sendrepute_customer_api:
  resource: '@SendReputeBundle/config/routes/customer_api.php'
  prefix: /admin/sendrepute
```

Access is denied by default: `ConsoleAuthorizer` is aliased to `DenyAllConsoleAuthorizer`. To grant access, alias it to your own implementation, for example one that calls `Security::isGranted('ROLE_ADMIN')`.

Console safeguards:

- It requires a session-bound CSRF token in `X-CSRF-Token` and a same-origin `Origin` header.
- A paid operation needs its quote operation to have run in the same session within the last 15 minutes. That quote is used up by the paid call.
- Only one paid or billing call can be in flight per session.
- The API key is never sent to the browser.

The mail subscriber is unchanged.

## Paid-intent ledger (anti-duplicate)

Every paid or billing console call is recorded in a durable intent ledger **before** the request is sent. The identity is the credential fingerprint, operation, method, path and canonical body. An identical request is refused (409 `INTENT_LOCKED` / `INTENT_COMPLETED`) from any session, worker or restart while the intent is pending, ambiguous (timeout, transport error, 5xx, 408/425, malformed response) or completed. Definitive 4xx refusals unlock it. The upstream replay identity (`recoveryId`, or `analysisId`) is generated once, persisted with the intent and reused on resend, so the server can deduplicate. Operators review intents with the **Paid intents** button and release one only with a reason and an explicit confirmation. Releasing a completed intent issues a fresh replay id for a deliberate second charge. Pending intents are never released online or by timeout, because a stalled worker may still have the request in flight. After a worker crash, stop every worker and run `$store->recoverPendingAfterShutdown(true, time())`; leftover pending intents become ambiguous for reconciliation and release. With no ledger configured, paid and billing operations return 503 `INTENT_STORE_REQUIRED`. The filesystem store supports a **single host only**, and it refuses to start unless you acknowledge that and its directory is absolute, `0700` and owned by the server user. Prices are always sent upstream for server-side enforcement, and a charge above consent is flagged. Classification (`classifyCustomerEmail`, POST /v1/classify) sends `priceAuthorization` with the four effective rates returned by this session's latest GET /v1/pricing plus the operator's ceiling. A confirmation that no longer matches the latest rates is refused (409 `PRICE_CONFIRMATION_STALE`) before anything is sent. The server re-checks rates and ceiling atomically at settlement (409 `PRICE_CHANGED`, no debit). Manual edit reclassification (`customerClassifyEmail`, POST /v1/classify/edit) has no price field in its request schema (`CustomerManualEditInput`, additionalProperties false); the server prices it authoritatively.

```yaml
send_repute:
  customer_api:
    console:
      intent_store:
        path: "%kernel.project_dir%/var/sendrepute-intents"   # single host only
        single_host: true
        # service: app.my_shared_intent_ledger   # custom IntentLedger for multi-host
```
