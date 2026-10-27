/**
 * Build the zip that goes to WordPress.org.
 *
 * The archive must unpack to `huggerhustle/` — that directory name is the
 * plugin slug, and a zip whose root is the repository name installs under the
 * wrong slug and then cannot be updated. "plugin" is also a term WordPress.org
 * refuses in a slug, so the repository name can never be the shipped name.
 *
 * Development files stay out: only what WordPress loads at runtime ships. That
 * is also what the directory review checks — Plugin Check flags `.github`,
 * `.gitignore` and extra markdown files when they are present.
 *
 * Run: node scripts/package.mjs
 */

import { execFileSync } from "node:child_process";
import { cpSync, mkdirSync, rmSync } from "node:fs";
import { join } from "node:path";

const ROOT = new URL("..", import.meta.url).pathname;
const SLUG = "huggerhustle";
const DIST = join(ROOT, "dist");
const STAGE = join(DIST, SLUG);

const INCLUDE = [
  "huggerhustle.php",
  "uninstall.php",
  "readme.txt",
  "LICENSE",
  "includes",
  "assets",
];

rmSync(DIST, { recursive: true, force: true });
mkdirSync(STAGE, { recursive: true });

for (const entry of INCLUDE) {
  try {
    cpSync(join(ROOT, entry), join(STAGE, entry), { recursive: true });
  } catch {
    console.log(`skip ${entry} (not present yet)`);
  }
}

execFileSync("zip", ["-qr", `${SLUG}.zip`, SLUG], {
  cwd: DIST,
  stdio: "inherit",
});

console.log(`built dist/${SLUG}.zip — upload this one`);
