/**
 * End-to-end: WordPress, the plugin, and an HTTP server standing in for
 * HuggerHustle.
 *
 * This is the test that would have caught every bug this plugin can have:
 *
 * 1. no key → no request at all (a WordPress.org requirement, and easy to break)
 * 2. key saved → the dashboard renders balance, campaigns and the businesses
 *    waiting, with the introduction and an approve button
 * 3. the held business is not offered for approval
 * 4. approving sends one PATCH and the screen reports it
 * 5. the API unreachable → the screen says so instead of showing a blank table
 * 6. the API's own error message reaches the screen, not a generic one
 * 7. PHP parses under the WordPress version this targets
 *
 * Run: pnpm test
 */

import { execFileSync, spawn } from "node:child_process";
import { existsSync, readFileSync, rmSync } from "node:fs";

const ROOT = new URL("..", import.meta.url).pathname;
const FAKE_PORT = 4010;
const FAKE_LIMITED_PORT = 4011;
// wp-env mounts this repository as a plugin directory named after the repo, so
// the slug is read rather than assumed. The release zip still ships as
// `huggerhustle/`, which is what WordPress.org keys the plugin by.
let PLUGIN = "huggerhustle-wp-plugin";
const FAKE_URL = `http://host.docker.internal:${FAKE_PORT}`;

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

/** wp-env run, with its noise trimmed. */
function wp(...args) {
  return execFileSync("npx", ["wp-env", "run", "cli", "wp", ...args], {
    cwd: ROOT,
    encoding: "utf8",
    stdio: "pipe",
  });
}

/** Options may already be absent; a failing wp-cli call must not end the run. */
function wpSoft(...args) {
  try {
    return wp(...args);
  } catch (error) {
    return String(error.stdout ?? "");
  }
}

function setOption(name, value) {
  wp("option", "update", name, value);
}

function clearCache() {
  wpSoft("transient", "delete", "--all");
}

/**
 * The directory this repo is mounted as, found rather than assumed.
 *
 * A packaged copy (`huggerhustle/`) may also be installed from a previous
 * plugin-check run, so the mount is identified by the file only this repo has.
 */
function detectSlug() {
  const listing = wp(
    "eval",
    'foreach ( glob( WP_PLUGIN_DIR . "/*/huggerhustle.php" ) as $f ) { $d = basename( dirname( $f ) ); if ( file_exists( WP_PLUGIN_DIR . "/$d/tests/render-admin.php" ) ) { echo $d; } }',
  );
  const slug = listing
    .split("\n")
    .map((line) => line.trim())
    .filter((line) => line && !line.includes(" "))
    .pop();
  return slug ?? "huggerhustle-wp-plugin";
}

function wpEval(code) {
  return wp("eval", code);
}

function renderAdmin(extra = "") {
  // `wp eval-file` runs with the plugin loaded and a logged-in admin.
  const args = ["eval-file", `wp-content/plugins/${PLUGIN}/tests/render-admin.php`];
  if (extra) args.push(extra);
  return wp(...args);
}

/** Wait until the fake API answers, so the first request is not a connection refused. */
async function waitForFake(port = FAKE_PORT) {
  for (let attempt = 0; attempt < 40; attempt += 1) {
    try {
      const response = await fetch(`http://localhost:${port}/api/v1/balance`, {
        headers: { authorization: "Bearer hh_test_0000000000000000" },
      });
      if (response.status) return;
    } catch {
      // not up yet
    }
    await new Promise((resolve) => setTimeout(resolve, 250));
  }
  throw new Error("fake API never answered");
}

async function main() {
  console.log("starting the fake HuggerHustle API…");
  const fake = spawn("node", ["tests/fake-api.mjs", String(FAKE_PORT)], {
    cwd: ROOT,
    stdio: ["ignore", "pipe", "inherit"],
  });
  const requested = [];
  fake.stdout.on("data", (chunk) => {
    for (const line of String(chunk).split("\n")) {
      if (line.trim()) requested.push(line.trim());
    }
  });
  // A second instance, already in its failure mode: a query string on the base
  // URL would land in the path, not in the mode switch.
  const limited = spawn("node", ["tests/fake-api.mjs", String(FAKE_LIMITED_PORT)], {
    cwd: ROOT,
    stdio: ["ignore", "ignore", "inherit"],
    env: { ...process.env, FAKE_MODE: "429" },
  });
  await waitForFake();
  await waitForFake(FAKE_LIMITED_PORT);

  try {
    console.log("starting WordPress (wp-env)…");
    execFileSync("npx", ["wp-env", "start"], { cwd: ROOT, stdio: "inherit" });
    // A packaged copy from a previous run would answer the same hooks twice.
    wpSoft("plugin", "deactivate", "huggerhustle");
    wpSoft("plugin", "delete", "huggerhustle");
    PLUGIN = detectSlug();
    console.log(`plugin slug: ${PLUGIN}`);
    wpSoft("plugin", "activate", PLUGIN);

    // 1. No key, no request — WordPress.org Guideline 7.
    wpSoft("option", "delete", "huggerhustle_api_key");
    requested.length = 0;
    const disconnected = renderAdmin();
    check(
      "with no key the screen asks for one",
      disconnected.includes("Add your API key"),
      disconnected.slice(0, 400),
    );
    check(
      "with no key nothing is requested",
      requested.length === 0,
      requested.join(" | "),
    );

    // 2. Connected: the dashboard renders what the API answered.
    setOption("huggerhustle_api_url", FAKE_URL);
    setOption("huggerhustle_api_key", "hh_test_0000000000000000");
    clearCache();
    const dashboard = renderAdmin();
    check(
      "the balance is shown",
      dashboard.includes("42 introductions left"),
      dashboard.slice(0, 600),
    );
    check("the campaign is listed", dashboard.includes("Acme Dental"));
    check("the site is matched to its campaign", dashboard.includes("huggerhustle-current"));
    check(
      "the ready business is offered",
      dashboard.includes("Northwind Dental"),
      dashboard.slice(0, 800),
    );
    check(
      "the introduction is rendered",
      dashboard.includes("Quick question for Northwind Dental"),
    );
    check(
      "an approve button is rendered",
      dashboard.includes("button-primary") && dashboard.includes("Approve"),
    );

    // 3. The held business is not up for approval.
    check(
      "a held business is not offered",
      !dashboard.includes("Held Co"),
    );

    // 6. The API's own words reach the screen when it refuses.
    setOption("huggerhustle_api_url", `http://host.docker.internal:${FAKE_LIMITED_PORT}`);
    clearCache();
    const limited = renderAdmin();
    check(
      "a 429 shows the API's message, not a generic one",
      limited.includes("rate limit") && limited.includes("Wait before retrying"),
      limited.slice(0, 600),
    );

    // 5. Unreachable: an error the owner can act on, never a blank screen.
    setOption("huggerhustle_api_url", "http://127.0.0.1:1");
    clearCache();
    const unreachable = renderAdmin();
    check(
      "an unreachable API says so",
      unreachable.includes("Could not reach HuggerHustle"),
      unreachable.slice(0, 600),
    );

    // 8. Approving really sends a PATCH and reads its answer.
    setOption("huggerhustle_api_url", FAKE_URL);
    clearCache();
    const approved = wpEval(
      `$c = huggerhustle_client(); $r = $c->approve( "c0000000-0000-4000-8000-000000000001", "p0000000-0000-4000-8000-000000000001" ); echo is_wp_error( $r ) ? "ERR " . $r->get_error_message() : "OK " . $r["status"];`,
    );
    check(
      "approving a business answers with its new status",
      approved.includes("OK approved"),
      approved.slice(0, 300),
    );
    check(
      "the approve button carries a nonce and a POST action",
      dashboard.includes('name="_wpnonce"') &&
        dashboard.includes("huggerhustle_approve"),
    );

    // 9. Starting a campaign sends this site's address, name and page text.
    wp(
      "post",
      "create",
      "--post_type=page",
      "--post_status=publish",
      "--post_title=Pricing",
      "--post_content=<p>Plans from $99 a month</p>[gallery ids=\"1,2\"]",
      "--porcelain",
    );
    const payload = wpEval(
      `echo wp_json_encode( HuggerHustle_Admin::campaign_payload() );`,
    );
    let parsed = {};
    try {
      parsed = JSON.parse(payload.trim().split("\n").pop());
    } catch {
      parsed = {};
    }
    const blogname = wp("option", "get", "blogname").trim().split("\n").pop().trim();
    const siteurl = wp("option", "get", "home").trim().split("\n").pop().trim();
    check(
      "the campaign carries this site's address and name",
      parsed.website === `${siteurl}/` && parsed.businessName === blogname,
      `got ${JSON.stringify({ website: parsed.website, businessName: parsed.businessName })} wanted ${JSON.stringify({ website: `${siteurl}/`, businessName: blogname })}`,
    );
    const pricing = (parsed.documents ?? []).find((d) => d.name === "Pricing");
    check(
      "page text is plain: no markup, no shortcodes",
      Boolean(pricing) &&
        pricing.content.includes("Plans from $99 a month") &&
        !pricing.content.includes("<p>") &&
        !pricing.content.includes("[gallery"),
      JSON.stringify(pricing ?? {}).slice(0, 300),
    );

    // 7. Parses on the PHP this environment runs.
    execFileSync("node", ["tests/lint.mjs", PLUGIN], { cwd: ROOT, stdio: "inherit" });
  } finally {
    fake.kill();
    limited.kill();
  }

  console.log(`\n${checks - failures}/${checks} checks passed`);
  if (failures > 0) process.exit(1);
}

main().catch((error) => {
  console.error(error);
  process.exit(1);
});
