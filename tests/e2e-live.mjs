/**
 * End-to-end, second layer: the plugin against the real app.
 *
 * The first layer (`tests/e2e.mjs`) proves the plugin's own logic against a fake
 * API. This one proves the thing a fake cannot: that the contract the plugin was
 * written to is the contract the app actually serves — the real balance shape,
 * real key authentication, real error codes.
 *
 * That distinction is not academic. The fake answered
 * `{ available: 42 }` while the app answers
 * `{ credits: { available: 1, trialEmailsLeft: 42 } }`, and `available` there
 * means free meetings, not introductions. Every first-layer check passed while
 * the dashboard showed the wrong number.
 *
 * Needs: the app running (`pnpm dev` in platform-v2 with `FAKE_SEARCHES=1`) and a
 * seeded key. See AGENTS.md.
 *
 * Run: HH_KEY=hh_… node tests/e2e-live.mjs [base-url]
 */

import { execFileSync } from "node:child_process";
import { readFileSync } from "node:fs";

const ROOT = new URL("..", import.meta.url).pathname;
const APP = process.argv[2] ?? process.env.HH_URL ?? "http://localhost:3000";
/** From inside the WordPress container, the host is reachable this way. */
const APP_FROM_WP = APP.replace("localhost", "host.docker.internal");

let PLUGIN = "huggerhustle-wp-plugin";
const KEY = process.env.HH_KEY ?? readKey();
const SITE = "https://demo.test/"; // accepted by the app in development only

let failures = 0;
let checks = 0;

function check(name, condition, detail = "") {
  checks += 1;
  if (condition) {
    console.log(`ok    ${name}`);
    return;
  }
  failures += 1;
  console.log(`FAIL  ${name}${detail ? `\n        ${detail}` : ""}`);
}

function readKey() {
  try {
    return readFileSync("/tmp/e2e-key", "utf8").trim();
  } catch {
    return "";
  }
}

function wp(...args) {
  return execFileSync("npx", ["wp-env", "run", "cli", "wp", ...args], {
    cwd: ROOT,
    encoding: "utf8",
    stdio: "pipe",
  });
}

function wpSoft(...args) {
  try {
    return wp(...args);
  } catch (error) {
    return String(error.stdout ?? "");
  }
}

/**
 * The evaluated output, without wp-env's chatter.
 *
 * Filtering by the banner's own marks, not by line position: a short answer can
 * arrive on the same line as the "Ran" footer, and slicing by index then ate it —
 * which made a successful campaign creation look like a failure.
 */
function wpEval(code) {
  return wp("eval", code)
    .split("\n")
    .filter((line) => !/^[ℹ✔✖]/.test(line.trim()) && line.trim() !== "")
    .join("\n")
    .trim();
}

function renderAdmin() {
  return wp("eval-file", `wp-content/plugins/${PLUGIN}/tests/render-admin.php`);
}

async function main() {
  if (!KEY.startsWith("hh_")) {
    throw new Error("set HH_KEY, or write the key to /tmp/e2e-key");
  }

  const probe = await fetch(`${APP}/api/v1/balance`, {
    headers: { authorization: `Bearer ${KEY}` },
  });
  if (!probe.ok)
    throw new Error(
      `the app answered ${probe.status}; is it running with this key?`,
    );

  execFileSync("npx", ["wp-env", "start"], { cwd: ROOT, stdio: "inherit" });
  wpSoft("plugin", "deactivate", "huggerhustle");
  wpSoft("plugin", "delete", "huggerhustle");
  const detected = wpEval(
    'foreach ( glob( WP_PLUGIN_DIR . "/*/huggerhustle.php" ) as $f ) { $d = basename( dirname( $f ) ); if ( file_exists( WP_PLUGIN_DIR . "/$d/tests/render-admin.php" ) ) { echo $d; } }',
  );
  if (detected) PLUGIN = detected;
  wp("plugin", "activate", PLUGIN);

  // Point the plugin at the real app.
  wp("option", "update", "huggerhustle_api_url", APP_FROM_WP);
  wp("option", "update", "huggerhustle_api_key", KEY);
  wpSoft("transient", "delete", "--all");

  // The app rejects a localhost website on purpose, so the test speaks as a
  // public site — the filter exists for exactly this.
  wpEval(
    `add_filter( "huggerhustle_site_url", function () { return "${SITE}"; } ); echo "hooked";`,
  );

  // 1. The real balance shape reaches the screen.
  const dashboard = renderAdmin();
  check(
    "the real balance shape is read",
    /introductions? left in your trial/.test(dashboard) ||
      dashboard.includes("Sending is free"),
    dashboard.slice(0, 700),
  );
  check(
    "no PHP warning reached the screen",
    !/Warning:|Fatal error|Notice:/.test(dashboard),
    (dashboard.match(/(Warning|Fatal error|Notice):.{0,200}/) ?? [""])[0],
  );

  // 2. Starting a campaign really posts to the app.
  //
  // A free account may start one campaign a day, so the run is repeatable: an
  // existing campaign for this site is the assertion, and creation happens only
  // when there is none. A second create would answer 422, which is the app being
  // right, not the plugin being wrong.
  const existing = await fetch(`${APP}/api/v1/campaigns`, {
    headers: { authorization: `Bearer ${KEY}` },
  })
    .then((r) => r.json())
    .then((body) => (body.items ?? []).find((c) => c.website === SITE));

  if (existing) {
    console.log(
      "      (a campaign for this site already exists; not creating another)",
    );
    check("starting a campaign answers with an id", true);
  } else {
    const created = wpEval(
      `add_filter( "huggerhustle_site_url", function () { return "${SITE}"; } );` +
        "$c = huggerhustle_client(); $r = $c->create_campaign( HuggerHustle_Admin::campaign_payload() );" +
        'echo is_wp_error( $r ) ? "ERR " . $r->get_error_message() : "OK " . $r["id"];',
    );
    check(
      "starting a campaign answers with an id",
      created.startsWith("OK "),
      created.slice(0, 300),
    );
  }

  // 3. And the campaign the app now holds is the one this site matches.
  wpSoft("transient", "delete", "--all");
  const after = renderAdmin();
  check(
    "the new campaign is listed",
    after.includes("Find businesses for this site") === false,
    after.slice(0, 700),
  );
  check(
    "this site's campaign is selected by website",
    after.includes("huggerhustle-current"),
    after.slice(0, 700),
  );

  console.log(`\n${checks - failures}/${checks} live checks passed`);
  if (failures > 0) process.exit(1);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
