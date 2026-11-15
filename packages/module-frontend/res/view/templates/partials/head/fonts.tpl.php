<?php
/**
 * Web-font slot of the frontend head (layoutConfig `head` → `fonts`) — empty on stock.
 *
 * The frontend uses system fonts (`tokens/_typography.scss`, `--fe-font-sans`). Until 2026-11-15
 * this partial linked Inter from fonts.googleapis.com: used by no style, it only sent every
 * visitor's IP to Google and was the one foreign origin against the page CSP (owner decision
 * 2026-11-15: removed, CSS-FRONTEND-FONTS-001). A project that wants a web font overrides this
 * partial under `override/` and serves the face from its OWN origin (`@font-face` + files under
 * public/), the way the backend self-hosts its faces — never a foreign font host.
 */
