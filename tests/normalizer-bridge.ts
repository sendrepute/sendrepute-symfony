import { visibleEmailText } from "../../../artifacts/api-server/src/lib/classifier-features.ts";
import { readFileSync } from "node:fs";

function expect(actual: string, expected: string, name: string): void {
  if (actual !== expected) {
    throw new Error(`${name}: expected ${JSON.stringify(expected)}, got ${JSON.stringify(actual)}`);
  }
}

const fixtures = JSON.parse(readFileSync(new URL("./fixtures/normalizer-hazards.json", import.meta.url), "utf8"));
for (const fixture of fixtures) {
  expect(visibleEmailText(fixture.body), fixture.normalized, fixture.name);
}

process.stdout.write(`actual visibleEmailText regression bridge passed (${fixtures.length} payloads)\n`);