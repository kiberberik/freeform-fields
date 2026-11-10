# Security policy

## Supported versions

Security fixes go into the latest release.

## Reporting a vulnerability

Please **do not open a public issue** for a security problem.

Report it privately through the repository's **Security → Report a vulnerability** form (GitHub private vulnerability reporting). Include:

- the affected version;
- the steps to reproduce or a proof of concept;
- the impact you expect, for example: which user role can do what.

We aim to confirm reports within a few days. After a fix is released, we will publish an advisory and credit you, unless you prefer to stay anonymous.

## Scope

In scope: anything in this repository that lets a user read or change data beyond their WordPress capabilities. This includes:

- forms, AJAX and REST routes;
- block data;
- the Block Bindings source;
- abilities;
- the import tools;
- escaping of field output.

Out of scope:

- issues that need `unfiltered_html` or administrator access to begin with;
- problems in WordPress core or other plugins.
