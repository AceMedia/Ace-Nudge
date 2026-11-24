# Security

## Reporting a vulnerability

Please report vulnerabilities privately, either through "Report a vulnerability" on this repository's Security tab or by email to shane@acemedia.ninja. Do not open a public issue.

Include the version, what you found and how to reproduce it. Expect an acknowledgement within a few days, and a fix or a plan once the report has been confirmed. Please give reasonable time for a fix before disclosing publicly.

## Supported versions

The latest release.

## Scope notes

The REST routes under `ace-nudge/v1` are public by design, because cached pages cannot carry a nonce. Sign-up is rate limited and everything after it needs the subscription's own token. Reports about ways round those protections, about the confirm and unsubscribe links, the push endpoint allow-list, or anything that sends to people who did not consent, are especially welcome.
