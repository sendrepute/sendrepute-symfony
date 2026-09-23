# Security

Report adapter vulnerabilities privately to `security@sendrepute.com`. Do not
include production API tokens, customer email content, or paid request IDs in
reports.

Tokens are server configuration only. Never put them in an `Email`, browser
bundle, repository, log, exception context, or generated ZIP. Rotate a token if
it may have been exposed.

The adapter is intentionally disabled and lacks paid consent by default.
Per-message opt-in is mandatory. Keep sensitive account/security mail unmarked
unless its classification is specifically authorized. Choose `failure_policy:
block` where unsupported MIME or classifier outages must stop delivery.

The package preserves the original message and envelope. It sends only sender
display name, subject, and one supported displayed body to SendRepute. Mixed
display alternatives, textual DataParts, CSS braces, QP/base64 syntax, and
other ambiguous normalizer inputs are refused before the paid call. Do not
weaken these checks by classifying only the first MIME part.

The client uses a fixed HTTPS endpoint, certificate verification, no redirects,
no automatic retries, bounded deadlines, and a 1 MiB response cap. Diagnostic
events contain only category, status, safe identifiers, action, and score; do
not add raw messages, tokens, response bodies, or HTTP request objects.