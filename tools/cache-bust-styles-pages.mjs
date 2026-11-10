import { createHash } from "node:crypto";
import { readdirSync, readFileSync, writeFileSync } from "node:fs";
import { dirname, extname, join, resolve } from "node:path";
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

const assetVersion = (assetPath, htmlPath) => {
  const absolutePath = assetPath.startsWith("/")
    ? join(publicRoot, assetPath.replace(/^\/+/, ""))
    : resolve(dirname(htmlPath), assetPath);
  if (!absolutePath.startsWith(publicRoot + "/")) throw new Error("Asset outside public directory");
  const contents = readFileSync(absolutePath);
  return createHash("sha256").update(contents).digest("hex").slice(0, 12);
};

const htmlFiles = walk(stylesRoot);
let changedFiles = 0;
let changedAssets = 0;

for (const htmlPath of htmlFiles) {
  const original = readFileSync(htmlPath, "utf8");
  const updated = original.replace(/((?:href|src)=")([^"?#]+)(?:\?[^" ]*)?("[^>]*>)/g, (match, prefix, assetPath, suffix) => {
    if (/^(?:[a-z]+:|\/\/)/i.test(assetPath) || !/\.(css|js)$/i.test(assetPath)) return match;
    // Version site composition and runtime files, including portable relative
    // links. Published framework and immutable next bundles retain their URLs.
    if (!assetPath.startsWith("/assets/") && !/(?:site-palette\.css|styles-theme\.js|composition\.css|catalog\.css)$/.test(assetPath)) return match;
    try {
      const version = assetVersion(assetPath, htmlPath);
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
