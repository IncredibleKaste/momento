# Momento: project assessment and mockup-derived feature list

Assessment date: 2026-09-27. Application revision: `d5240d7` on `develop`, with existing uncommitted documentation changes. Scope: repository and prototype inspection, current design decisions, feature extraction and proposed delivery order. No application implementation is authorized by this document.

## Assessment

Momento has a coherent visual direction and a useful interaction prototype, but the product implementation remains an authentication/settings foundation. The mockup demonstrates how a small photo-sharing product could feel; it does not demonstrate persistent social functionality, private media delivery or messaging.

The strongest design decisions are the photo-first feed, restrained retro typography and rainbow accents, transparent expanding navigation, and paired light/dark themes. The profile and chat layouts are explicitly unsatisfactory to the user and remain redesign candidates. Preserve their requested capabilities without treating their present layouts as accepted designs.

The main scope change is from a small Feed/Create/Profile application to a social product with follows, search, comments, bookmarks and direct messaging. These are meaningful additions to data ownership, visibility, abuse handling and testing. A button in the mockup is evidence of a requested interaction, not a commitment to ship every feature in the first release.

Recommendation: stop broad visual exploration temporarily, agree the first-release boundary and content visibility rules, then implement one complete photo-sharing flow. Keep chat in the backlog until its experience and access rules are defined. There is no demonstrated need to change the confirmed Laravel/Inertia/React architecture for this scope.

## Evidence and readiness

| Area | Evidence observed | Assessment |
| --- | --- | --- |
| Application foundation | `routes/web.php`, `routes/settings.php`, `app/Models/User.php`, auth/settings React pages, `config/fortify.php` | Local auth, verification/recovery and account settings scaffolding exist. Product feed/profile/chat routes and product models are absent. |
| Persistence | Migrations cover users, sessions, cache, jobs, 2FA and passkeys | No post, media, follow, like, bookmark, comment or conversation schema exists. |
| Auth adoption | Fortify enables registration, email verification, recovery, 2FA and passkeys | 2FA/passkey release adoption remains open; generated code does not resolve it. External providers remain planned. |
| Target services | [Architecture](architecture.md), example configuration and existing assessment | PostgreSQL/Redis and private media are target integrations, not proven by the starter's SQLite/in-memory tests. |
| Test and delivery evidence | Existing test files, `.github/workflows/tests.yml`, [workflow assessment](workflow-assessment.md) | Historical local checks passed on 2026-09-26; not rerun for this assessment. CI configuration exists, but live CI/deployment/HA/restore success is not established here. |
| Design prototype | Sibling `momento-ui-mockups/instant-memories-v2.html`, `social.js`, `settings.js`, `login.html` and styles | Separate static prototype with sample content and temporary browser state; not integrated into the Laravel application. |
| Working tree | Existing changes to README and four architecture/auth/review/workflow documents | These predate this assessment. No application code changes were found in the working-tree summary. |

Planning remains PARTIAL. STANDARD assurance and provisional R2 remain the documented product classification; this is not a security certification. The prototype is an experiment. Production readiness cannot be inferred from either polished screens or starter tests.

### Prototype shortcuts that must not become requirements

- The own-profile filter accepts every sample post (`own || x.person === person`), so “Your moments” is not a correct ownership implementation.
- Following updates the people strip/list, but the main feed still renders all sample posts. A following-only feed has not been implemented.
- New local uploads are attributed to sample person 0. The navigation avatar is also a fixed sample image, not a real current-user photo.
- Likes, bookmarks, follows, comments, messages and profile-setting edits are temporary JavaScript state. Reloading loses them.
- Chat lists sample people and appends outgoing text locally. There is no recipient delivery, server history, unread state or actual conversation membership.
- Login/provider controls and reports are previews. Logging out navigates to the login mockup; it does not revoke a server session.
- Main-page themes are selected by query/local page state. Durable cross-device preferences have not been established.
- The prototype layers function replacements and CSS overrides over its original HTML. Use it as a visual/interaction reference, then implement coherent React components and backend actions rather than transplanting it wholesale.

## Feature catalog

The canonical feature catalog is in the Obsidian Vault at `Work/Projects/Momento/02 Features/00 Feature Index.md`, linked from the Momento Project Hub. It contains 28 stable records as individual feature notes under seven topic indexes: Authentication; Interface and Appearance; Feed and Posts; Profiles and Following; Interactions and Search; Settings and Support; Messaging.

F01–F22 preserve the original inventory IDs. F23–F26 make 2FA, passkeys, own-post deletion and the generated email-updates preference explicit adoption candidates. F27 covers the animated landing page; F28 covers message media attachments. Each note distinguishes lifecycle, implementation evidence, provenance, draft acceptance, open decisions and unassigned release target. Requested directions are PLANNING; unconfirmed candidates are PROPOSED. Nothing is marked RELEASED. Chat postponement remains a recommendation, not an accepted DEFERRED state.

This repository page owns the dated source assessment, not the live backlog. Feature status and scheduling decisions should be updated in the Vault catalog.

### Visual requirements to preserve

These are shared presentation requirements, not separate backend features:

- Warm paper light theme and charcoal dark theme; expressive serif headings/wordmark with simple sans-serif controls.
- Retro Apple/instant-photography color, typography and shape references, without Polaroid mats around photographs.
- Feed/profile content takes center stage; thin post borders with a rotation of 16 gradient pairs and subtly rounded corners.
- Five-color diagonal ribbon reaches the top and right page edges, leaves background beyond blue, remains fixed while scrolling, and has gentle motion with reduced-motion fallback.
- Name/link text shifts to the rainbow gradient with a close, thin matching underline; entry and exit both animate.
- Round avatars; stronger caption author names; smooth heart shape and obvious selected like/save states.
- Profile rail entry uses the user's avatar and a deeper blue than Chat. Settings uses rainbow lines instead of the discarded gear icon.
- No “community only” badge. Removing that badge does not decide whether content is public or member-only.

## Necessary work not demonstrated by the mockup

These are engineering/product recommendations supporting the visible features, not additional accepted screens.

| Gap | Why it belongs in the implementation plan | Evidence needed |
| --- | --- | --- |
| Content visibility and object authorization | Every feed, profile, search, saved-item and media response must obey the same audience rules | Two-user tests covering allowed and denied reads/actions, including direct media URLs |
| Media lifecycle | File preview does not cover processing, storage cost or failure cleanup | Invalid/oversized image rejection, bounded decoding/re-encoding, metadata policy, private thumbnails/originals, partial failure and deletion cleanup |
| Own-post deletion and account deletion effects | Users need a way to remove their content; Starter account deletion does not establish cleanup for future media/social data | Ownership denial tests, database/file cleanup and documented effects on comments/bookmarks/messages |
| Basic abuse handling and account disablement | Open signup and user content create needs beyond a generic support form | Named moderation/support owner, disablement enforcement and scoped reporting/removal behavior before wider onboarding |
| Data integrity and concurrent actions | Multiple sessions can race on follows, likes, saves and comments | Constraints, authorization, repeat-request handling and meaningful concurrent/retry tests |
| Real service integration | Existing local tests do not exercise the target data/session/cache setup | PostgreSQL migrations/integration, Redis failure behavior, real mail and session lifecycle evidence |
| Operational recovery | Photos and database rows form one recoverable product dataset | Coordinated restore, release rollback and dependency/host failure evidence; separate from UI acceptance |

## Planning and learning references

The Vault catalog and its linked `Assessment - Mockup Reconciliation` note own current scope decisions and sequencing recommendations. Principal unresolved subjects are content audience/feed semantics, first-release scope, identity/scaffold adoption, media lifecycle and chat permissions. No implementation milestone or release target was accepted through this assessment.

The Project Hub now links the Feature Index and assessment. Earlier delivery proposals in project-review.md and older generated scope statements are historical inputs rather than current feature commitments. The selected unframed-photo design supersedes the original Polaroid-frame concept.

Learning reconciliation was read-only: Architecture and Testing indexes were inspected, relevant existing notes were linked in the internal assessment, and unverified concept coverage was recorded. No learning notes were created or changed.

### Verification boundary

This assessment inspected source, routes, model/migration/test inventories, CI configuration, current documents and prototype behavior in code. Earlier browser checks established selected mockup interactions only. No fresh full test suite, accessibility audit, load test, provider login, database deployment, live CI, HA drill or restore was performed for this document. No application code, dependencies, Git history or remote state were changed.

## Reassessment under workflow v0.3.6

Rechecked HEAD/worktree: application baseline remains `d5240d7`; changes are documentation only. The material correction is canonical feature governance and explicit adoption state, not new runtime evidence. The user authorized the Momento Vault catalog, assessment and Hub update; those files were persisted and internal catalog links checked. The full feature table was removed from this page to avoid a competing live backlog. No internal assessment content was copied into this repository revision.
