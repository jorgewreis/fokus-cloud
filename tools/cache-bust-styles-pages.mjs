import { createHash } from "node:crypto";
import { readdirSync, readFileSync, writeFileSync } from "node:fs";
import { extname, join, resolve } from "node:path";
import { fileURLToPath } from "node:url";

const projectRoot = resolve(fileURLToPath(new URL("..", import.meta.url)));
const stylesRoot = join(projectRoot, "public", "styles");
const publicRoot = join(projectRoot, "public");

const walk = (directory) => {
  const entries = readdirSync(directory, { withFileTypes: true });
  const files = [];

  for (const entry of entries) {
    const path = join(directory, entry.name);
    if (entry.isDirectory()) files.push(...walk(path));
    else if (extname(entry.name).toLowerCase() === ".html") files.push(path);
  }

  return files;
};

const assetVersion = (assetPath) => {
  const absolutePath = join(publicRoot, assetPath.replace(/^\/+/, ""));
  const contents = readFileSync(absolutePath);
  return createHash("sha256").update(contents).digest("hex").slice(0, 12);
};

const htmlFiles = walk(stylesRoot);
let changedFiles = 0;
let changedAssets = 0;

for (const htmlPath of htmlFiles) {
  const original = readFileSync(htmlPath, "utf8");
  const updated = original.replace(/((?:href|src)=")(\/assets\/[^"?]+)(?:\?[^" ]*)?("[^>]*>)/g, (match, prefix, assetPath, suffix) => {
    try {
      const version = assetVersion(assetPath);
      changedAssets += 1;
      return `${prefix}${assetPath}?v=${version}${suffix}`;
    } catch {
      return match;
    }
  });

  if (updated !== original) {
    writeFileSync(htmlPath, updated);
    changedFiles += 1;
  }
}

console.log(`Cache-busted ${changedAssets} local assets in ${changedFiles} Styles page(s).`);
