/**
 * A fake HuggerHustle API, for tests only.
 *
 * The plugin's own tests must not depend on a live account, a database or the
 * network, so this answers the five endpoints the plugin uses with canned data
 * shaped exactly like `lib/contracts/*` in the platform repo — including the
 * error envelope, which is the part a client most often gets wrong.
 *
 * A test can ask it to fail: `?fake=429` on any path, or by setting
 * `process.env.FAKE_MODE`, which is how the error rendering is exercised.
 *
 * Run: node tests/fake-api.mjs [port]
 */

import { createServer } from "node:http";

const PORT = Number(process.argv[2] ?? process.env.FAKE_API_PORT ?? 4010);

const CAMPAIGN_ID = "c0000000-0000-4000-8000-000000000001";
const PROSPECT_READY = "p0000000-0000-4000-8000-000000000001";
const PROSPECT_HELD = "p0000000-0000-4000-8000-000000000002";

const campaigns = [
  {
    id: CAMPAIGN_ID,
    name: "Acme Dental",
    website: "https://acme.com/",
    status: "review",
    mode: "review",
    createdAt: "2026-01-01T00:00:00.000Z",
    counts: { found: 2, ready: 1, approved: 0, held: 1 },
  },
];

// Only the ready one is listed under `?status=draft`, which is what the screen
// asks for. The held business exists to prove the filter is respected.
const prospects = [
  {
    id: PROSPECT_READY,
    status: "draft",
    category: "business-fit",
    company: "Northwind Dental",
    domain: "northwind.example",
    line: "A four-chair practice adding a second location.",
    sentAt: null,
    replyStatus: "none",
    booked: false,
    sending: null,
  },
  {
    id: PROSPECT_HELD,
    status: "held",
    category: null,
    company: "Held Co",
    domain: "held.example",
    line: "No public address on its own site.",
    sentAt: null,
    replyStatus: "none",
    booked: false,
    sending: null,
  },
];

const drafts = {
  [PROSPECT_READY]: {
    subject: "Quick question for Northwind Dental",
    // The address is deliberately absent: the real API redacts it, and the
    // screen must not be able to show one that was never sent.
    text: "Hi Sam,\n\nI saw Northwind Dental is opening a second location. We help practices keep their rooms stocked without the weekly phone calls.\n\nWould an introduction be useful?",
    to: null,
    sent: false,
  },
};

const approved = new Set();

function send(res, status, body) {
  const payload = JSON.stringify(body);
  res.writeHead(status, {
    "content-type": "application/json",
    "content-length": Buffer.byteLength(payload),
  });
  res.end(payload);
}

function fail(res, status, code, message, extra = {}) {
  send(res, status, { error: { code, message, ...extra } });
}

const server = createServer(async (req, res) => {
  const url = new URL(req.url, `http://localhost:${PORT}`);
  const path = url.pathname;
  const mode = url.searchParams.get("fake") ?? process.env.FAKE_MODE ?? "";

  // Every request is logged so an e2e can assert what the plugin asked for.
  console.log(`${req.method} ${url.pathname}${url.search}`);

  if (!req.headers.authorization?.startsWith("Bearer hh_"))
    return fail(res, 401, "sign_in_required", "Please sign in or provide an Authorization: Bearer hh_... API key.");

  if (mode === "429")
    return fail(res, 429, "rate_limited", "API key rate limit exceeded (60 requests per minute). Wait before retrying.");

  if (req.method === "GET" && path === "/api/v1/balance")
    return send(res, 200, {
      available: 42,
      trialEmailsLeft: null,
      subscription: null,
      freeMeetings: 1,
      meetingsBooked: 0,
      children: [],
    });

  if (req.method === "GET" && path === "/api/v1/campaigns")
    return send(res, 200, { items: campaigns, nextCursor: null, total: campaigns.length });

  const campaignMatch = path.match(/^\/api\/v1\/campaigns\/([^/]+)$/);
  if (req.method === "GET" && campaignMatch)
    return send(res, 200, {
      ...campaigns[0],
      counts: { found: 2, ready: 1, approved: approved.size, sent: 0, held: 1, replied: 0, booked: 0 },
      researchNote: null,
      error: null,
      pausedReason: null,
      rounds: [],
    });

  const listMatch = path.match(/^\/api\/v1\/campaigns\/([^/]+)\/prospects$/);
  if (req.method === "GET" && listMatch) {
    const status = url.searchParams.get("status");
    const items = status ? prospects.filter((p) => p.status === status) : prospects;
    return send(res, 200, { items, nextCursor: null, total: items.length });
  }

  const oneMatch = path.match(/^\/api\/v1\/campaigns\/([^/]+)\/prospects\/([^/]+)$/);
  if (oneMatch) {
    const prospect = prospects.find((p) => p.id === oneMatch[2]);
    if (!prospect) return fail(res, 404, "not_found", "This prospect was not found.");

    if (req.method === "GET")
      return send(res, 200, {
        prospect: {
          id: prospect.id,
          status: prospect.status,
          category: prospect.category,
          company: prospect.company,
          domain: prospect.domain,
          reason: prospect.line,
          need: { text: "Not established", kind: "unknown", sourceId: null, quote: null },
          website: "https://northwind.example/",
          sentAt: null,
          replyStatus: "none",
          booked: false,
          sending: null,
        },
        draft: drafts[prospect.id] ?? null,
      });

    if (req.method === "PATCH") {
      let body = "";
      for await (const chunk of req) body += chunk;
      let parsed = {};
      try {
        parsed = JSON.parse(body || "{}");
      } catch {
        return fail(res, 400, "bad_request", "Request body must be valid JSON.");
      }
      if (typeof parsed.approved !== "boolean")
        return fail(res, 422, "validation_error", "approved: Expected boolean", {
          fields: { approved: "Expected boolean" },
        });
      if (parsed.approved) approved.add(prospect.id);
      else approved.delete(prospect.id);
      return send(res, 200, {
        id: prospect.id,
        status: parsed.approved ? "approved" : "draft",
        approved: parsed.approved,
      });
    }
  }

  if (req.method === "POST" && path === "/api/v1/campaigns") {
    let body = "";
    for await (const chunk of req) body += chunk;
    let parsed = {};
    try {
      parsed = JSON.parse(body || "{}");
    } catch {
      return fail(res, 400, "bad_request", "Request body must be valid JSON.");
    }
    if (parsed.website && /localhost|\.local|\.test$/.test(parsed.website))
      return fail(res, 422, "invalid_input", "Enter a complete public website address, starting with https://.", {
        fields: { website: "Not a public address." },
      });
    console.log(`CREATE ${JSON.stringify(parsed)}`);
    return send(res, 201, { id: CAMPAIGN_ID, status: "researching", mode: parsed.mode ?? "review" });
  }

  return fail(res, 404, "not_found", `No route for ${req.method} ${path}.`);
});

server.listen(PORT, () => {
  console.log(`fake HuggerHustle API on http://localhost:${PORT}`);
});
