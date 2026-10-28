---
paths:
    - '**'
---

# General

## Keep deployment infrastructure private

Never publish actual deployment infrastructure in GitHub commits, history, PRs, issues, logs, artifacts or images: real hostnames/domains, addresses, network names, topology/node counts, sizing, storage locations, provider or tenant identifiers, credentials and internal operator notes. Keep environment-specific material outside this repository; use only generic application requirements and fictional placeholders here. Before every push or PR, review all outgoing commits and generated artifacts against this rule.
