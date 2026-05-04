# Vercel cost optimization — see `inboxreya` repo

**Scope of this repo**
- `websocket-server.js` — Express + Socket.io server, run via PM2 on a Node host
- `odoo-dashboard.js` (+ `.min.js`) — static dashboard JS shipped to clients
- `line-mini-app/` — separate Next.js mini-app (own `next.config.js`)

This repo **does not run on Vercel**. The $42/mo Vercel infrastructure
bill (Fast Origin Transfer + Fluid Memory + Function Invocations)
comes entirely from the `inboxreya` Next.js app.

All optimization + Netlify/Cloudflare deploy work lives in:

- PR: https://github.com/reyatelehealth2026-crypto/inboxreya/pull/8
- Branch: `claude/optimize-deploy-netlify-3XUAY` in `inboxreya`
- Guide: `DEPLOY_OPTIMIZE_TH.md` in that PR

## If you want to also reduce cost on this repo's runtime

The WebSocket server is on a self-managed host (PM2), so cost depends
on the VM/VPS plan, not Vercel. Knobs that help:

- Front it behind Cloudflare (orange cloud) so static dashboard JS/CSS
  is cached at the edge and Socket.io WebSocket frames go through CF —
  cuts origin egress.
- Run `npm run build:js` to ship `odoo-dashboard.min.js` (terser already
  configured) — smaller payload per dashboard load.
- Set long `Cache-Control` on the minified bundle (filename-hash it if
  not already) so browsers don't refetch on every visit.

If later you want this Express server on Netlify Functions / CF
Workers, the Socket.io part will need to switch to Pusher / Ably /
Durable Objects since neither platform supports long-lived WS
connections in their function runtimes.
