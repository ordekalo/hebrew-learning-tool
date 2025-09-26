# Mobile-First Roadmap for Hebrew Learning Tool

This document converts the product brief into an actionable roadmap that fits the current PHP/MySQL stack. It groups the work into milestones, lists the technical tasks, and calls out dependencies or open questions so we can prioritize implementation and testing effectively.

## Guiding Principles

1. **Mobile-first** – layouts, interactions, and performance budgets target mid-tier Android/iOS devices on 3G/4G networks.
2. **Progressive enhancement** – existing desktop flows stay available while the new PWA/mobile UI is layered on top.
3. **Incremental delivery** – database migrations and API changes ship behind feature flags when possible to avoid downtime on shared hosting.
4. **Security and privacy** – authentication, rate limiting, and CSRF protections are mandatory for all new POST/PUT/DELETE endpoints.

## Milestone Overview

| Milestone | Scope | Key Deliverables | Dependencies |
|-----------|-------|------------------|---------------|
| M1 | Data model + API foundations | `users`, `decks`, `tags`, `user_progress` tables, CRUD endpoints, import enhancements | Requires maintenance window for migrations |
| M2 | Spaced repetition + daily session | SM-2 logic, study session endpoints, updated study UI | M1 APIs and tables |
| M3 | Media capture + mobile UX polish | Audio/image uploads, offline cache, tactile feedback, RTL QA | M1 (users) for auth, M2 for study flow |
| M4 | Gamification + analytics | Streaks, achievements, multi-mode drills, dashboard metrics | M2 study flow |
| M5 | PWA + performance hardening | Manifest, service worker, Lighthouse improvements | M1–M3 base features |
| M6 | Sync + backups + documentation | `/api/progress/sync`, cron backup scripts, README/PWA docs | M1 data model |

Each milestone should conclude with QA on mobile (Android Chrome + iOS Safari) and a dry-run deploy to the staging slot if available.

## M1 – Data Model & API Foundations

### Database
- Apply migrations in order:
  1. `users`
  2. `decks` + `deck_words`
  3. `tags` + `word_tags`
  4. `user_progress`
- Add indices on `due_at`, `deck_id`, `tag_id` for query performance.
- Provide rollback scripts for shared hosting (no transactional DDL).

### Backend
- Implement lightweight migration runner PHP script (CLI) to apply SQL files sequentially.
- Add `UserRepository`, `DeckRepository`, `TagRepository`, and expand `WordRepository` for deck/tag relationships.
- Expose JSON APIs:
  - `GET /api/decks` (with due counts)
  - `GET /api/search`
  - `GET /api/learn/next`
  - `POST /api/learn/answer`
- Enforce CSRF via same token system used on forms; for AJAX, return token in meta tag.
- Integrate simple rate limiting (per-IP, per-user) stored in cache table.

### CSV Import Enhancements
- Extend parser to recognise `deck`, `tags`, `image_url`, `audio_url` columns.
- Validate file size (<5MB) and row-level errors with line numbers.
- Batch inserts in 500-row chunks to meet <30s import requirement.

### Acceptance Tests
- Manual: import 5k rows on staging; confirm timing and deck/tag linkage.
- Automated: PHPUnit feature tests for search API (AND filters, tokenization stubs).

## M2 – Spaced Repetition & Daily Session

### SM-2 Lite Engine
- Create service `SpacedRepetitionScheduler` with configurable defaults (`ease = 2.5`, new intervals 1d/6d).
- Persist `reps`, `lapses`, `last_result` in `user_progress`.
- When answering cards:
  - Update `ease` within 1.3–2.8 range.
  - Clamp `interval_days` to at least 1 when result is `good`/`easy`.
  - Schedule reviews by setting `due_at` to UTC timestamp.

### Session Flow
- Add `/study` route for mobile with deck selector, “Start session” CTA.
- Track in-session mistakes in PHP session/Redis; reinsert into queue within next 5 cards until two consecutive successes.
- Show progress meter (studied/remaining) and `Cards for today` counter based on due queries.

### UI/UX (Mobile)
- Implement responsive layout using CSS grid/flexbox with bottom button bar (≥44px targets, 12–16px spacing).
- Support swipe gestures (Again/Easy) using Hammer.js or pointer events fallback.
- RTL-first card design with large Hebrew text, transliteration, translations, media buttons.

### Acceptance
- QA with 30-card session to validate due updates, mistake resurfacing, and empty-state messaging.

## M3 – Media Capture & Mobile Polish

### Audio Recording
- Frontend: `MediaRecorder` API + fallback file input on iOS; allow playback via `<audio>` element.
- Backend: upload handler stores audio under `uploads/audio/`, enforces ≤10MB, verifies MIME with Fileinfo.
- Generate presigned URLs or session tokens to prevent CSRF on uploads.

### Image Capture
- Use `<input type="file" accept="image/*" capture="environment">` for camera access.
- Server-side: create thumbnails (GD/Imagick) with max width 512px; store originals separately.
- Lazy-load images with `loading="lazy"` and placeholder skeletons.

### Optional TTS
- Abstract `AudioSource` so cards fall back to generated TTS if no recorded audio.
- Wrap third-party API credentials in `.env` (never commit to repo).

### UX Polish
- Vibration API (if supported) for incorrect answers.
- Ensure fast transitions (<200ms) by preloading next card JSON.
- Mobile admin form for quick word entry with deck/tag selection.

## M4 – Gamification & Analytics

### Game Modes
- Implement mode toggle per deck (`hard`, `medium`, `easy`).
- Hard: audio/transliteration → Hebrew input with RTL keyboard and validation (allow typos with Levenshtein threshold).
- Medium: translation prompt with mixed input/multiple choice.
- Easy: multiple choice translations/images.
- Record performance metrics per mode to adjust difficulty.

### Achievements & Streaks
- Tables: `streaks`, `achievements` as per spec.
- Calculate daily streak server-side (cron) and update `streaks` table.
- Unlock achievements based on thresholds (e.g., 7-day streak, 100 mastered words) and surface on home screen.

### Acceptance
- Smooth transitions between stages, tactile feedback, streak badge on home view.

## M5 – PWA & Performance

### PWA Essentials
- Add `manifest.json` with icons, theme, RTL splash screen.
- Service worker caches static assets + most recent study payloads for offline review.
- Provide “Install App” prompt on Android Chrome and instructions for iOS add-to-home.

### Performance Targets
- Minify CSS/JS (use build step or PHP minifier).
- Preload next audio file, lazy-load images, add HTTP caching headers.
- Measure via Lighthouse mobile; iterate until score ≥ 85 and LCP < 2.5s.
- Ensure CLS ≈ 0 by reserving media dimensions.

### Accessibility
- Confirm 44×44px touch targets, keyboard navigation, aria labels, proper `dir="rtl"` usage.
- Test with screen reader (VoiceOver, TalkBack) for card announcements.

## M6 – Sync, Backups, Documentation

### Sync API
- Authenticated `POST /api/progress/sync` accepting JSON array of progress updates.
- Conflict resolution: use latest `updated_at` timestamp, return summary of applied records.
- Mobile clients sync on app open/close.

### Backups & Ops
- Shell script + cron to run `mysqldump` nightly to `/backups/`, retain 7 days.
- Update GitHub Actions to exclude `config.php` and `uploads/**` from artifacts; lint PHP.

### Documentation
- Expand README with mobile-first instructions, PWA install guide, API docs, and privacy notice (GDPR-lite export flow).
- Maintain QA checklist (RTL, performance, offline) in `/docs/qa-mobile.md`.

## Open Questions

1. Do we need multi-tenant support or is user scope limited to a single cohort?
2. Should study sessions operate entirely client-side for offline mode, or will we keep server authoritative?
3. What telemetry is acceptable within privacy constraints (e.g., anonymized analytics vs. none)?
4. Who owns visual design assets (deck covers, icons) and how are they delivered for responsive layouts?

## Next Steps

1. Confirm hosting provider allows long-running imports and background cron jobs.
2. Schedule downtime (if needed) for initial migrations; take database snapshot beforehand.
3. Kick off M1 implementation with pair of tasks: migration runner + deck CRUD API.
4. Set up staging environment or local Docker stack for end-to-end testing on real devices.

