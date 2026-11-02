/**
 * PHP syntax check, inside the WordPress container.
 *
 * No PHP is installed on the host on purpose: the version that matters is the
 * one WordPress runs, and wp-env already has it. One `php -l` per file, and the
 * first failure stops the run.
 */

import { execFileSync } from "node:child_process";
import { readdirSync, statSync } from "node:fs";
import { join, relative } from "node:path";

const ROOT = new URL("..", import.meta.url).pathname;
// The directory this repo is mounted as inside WordPress; wp-env names it after
// the repository, while the release zip ships as `huggerhustle/`.
const SLUG = process.argv[2] ?? mountedSlug();

/** The plugin directory WordPress actually has, so `pnpm test:lint` works with
 * no argument: the packaged slug is not mounted while the repository is. */
function mountedSlug() {
  // The checkout first: it is what this repo mounts, and an installed copy of
  // the release zip may sit beside it under the packaged slug.
  const candidates = [ROOT.replace(/\/$/, "").split("/").pop(), "huggerhustle"];
  for (const slug of candidates) {
    try {
      execFileSync(
        "npx",
        ["wp-env", "run", "cli", "test", "-d", `wp-content/plugins/${slug}`],
        { cwd: ROOT, stdio: "pipe" },
      );
      return slug;
    } catch {
      // not mounted under that name
    }
  }
  return candidates[0];
}

function phpFiles(dir, found = []) {
  for (const entry of readdirSync(dir)) {
    if (["node_modules", ".git", ".agents", "dist"].includes(entry))
      continue;
    const path = join(dir, entry);
    if (statSync(path).isDirectory()) phpFiles(path, found);
    else if (entry.endsWith(".php")) found.push(path);
  }
  return found;
}

const files = phpFiles(ROOT).sort();
if (files.length === 0) {
  console.error("no PHP files found");
  process.exit(1);
}

let failed = 0;
for (const file of files) {
  const inside = `wp-content/plugins/${SLUG}/${relative(ROOT, file)}`;
  try {
    execFileSync(
      "npx",
      ["wp-env", "run", "cli", "php", "-l", inside],
      { cwd: ROOT, stdio: "pipe", encoding: "utf8" },
    );
    console.log(`ok   ${relative(ROOT, file)}`);
  } catch (error) {
    failed += 1;
    console.error(`FAIL ${relative(ROOT, file)}`);
    console.error(String(error.stdout ?? "") + String(error.stderr ?? ""));
  }
}

console.log(`\n${files.length - failed}/${files.length} files parse`);
process.exit(failed === 0 ? 0 : 1);
