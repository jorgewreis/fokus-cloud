import { test } from "node:test";
import assert from "node:assert/strict";
import { createCompanyIdempotencyLab } from "../../public/backoffice/assets/js/company-idempotency-lab.js";

function makeStorage() {
    const data = new Map();
    return { get length() { return data.size; }, key: (i) => [...data.keys()][i] ?? null,
        getItem: (key) => data.get(key) ?? null, setItem: (key, value) => data.set(key, value),
        removeItem: (key) => data.delete(key), data };
}

test("retains no payload, keeps uncertain status and requires same session", async () => {
    const storage = makeStorage();
    let current = "a".repeat(64);
    let submits = 0;
    const transport = {
        issue: async () => "synthetic-token",
        status: async () => ({ state: "observed", revoked: true }),
        submit: async () => { submits++; throw new Error("synthetic-token"); },
    };
    const client = createCompanyIdempotencyLab({ storage, transport, sessionBinding: async () => current, newId: () => "synthetic-id-00001", now: () => 1 });
    const id = await client.issue("company.create");
    assert.equal(await client.status(id), "indeterminate");
    assert.equal(await client.submit(id, { password: "synthetic-password" }), "indeterminate");
    assert.ok(![...storage.data.values()].join().includes("synthetic-password"));
    current = "b".repeat(64);
    await assert.rejects(client.submit(id, {}), /Sessão não verificada/);
    assert.equal(submits, 1);
    client.clear();
    assert.equal(storage.length, 0);
});

test("logout during issue prevents storage and repeated issue cannot overwrite", async () => {
    const storage = makeStorage();
    let resolveIssue;
    const transport = { issue: () => new Promise((resolve) => { resolveIssue = resolve; }) };
    const client = createCompanyIdempotencyLab({ storage, transport, sessionBinding: async () => "a".repeat(64), newId: () => "synthetic-id-00001" });
    const pending = client.issue("company.create");
    await new Promise((resolve) => setImmediate(resolve));
    await assert.rejects(client.issue("company.create"), /Registro indisponível/);
    client.clear();
    resolveIssue("synthetic-token");
    await assert.rejects(pending, /Sessão não verificada/);
    assert.equal(storage.length, 0);
});
