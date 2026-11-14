import { test } from "node:test";
import assert from "node:assert/strict";
import { createCompanyIdempotencyTransportLab } from "../../public/backoffice/assets/js/company-idempotency-transport-lab.js";

test("adapts actual issue, create and status shapes with CSRF and no-store", async () => {
    const calls = [];
    const fetchResponse = async (path, options) => {
        calls.push({ path, options });
        if (path.endsWith("/issue")) return new Response(JSON.stringify({ message: "ok" }), { status: 201, headers: { "Idempotency-Key": "synthetic-token" } });
        if (path.endsWith("/status")) return Response.json({ state: "completed", revoked: false, result: { id: "synthetic-company", status: 201 } });
        return Response.json({ company_id: "synthetic-company", message: "ok" }, { status: 201 });
    };
    const transport = createCompanyIdempotencyTransportLab({ fetchResponse, csrf: async () => "synthetic-csrf" });
    const token = await transport.issue("company.create");
    assert.equal(token, "synthetic-token");
    assert.equal(await transport.submit("company.create", token, { name: "synthetic" }), null);
    assert.equal((await transport.status("company.create", token)).result.status, 201);
    assert.equal(calls.length, 3);
    assert.ok(calls.every(({ options }) => options.credentials === "same-origin" && options.cache === "no-store" && options.headers["X-CSRF-TOKEN"] === "synthetic-csrf"));
    assert.equal(calls[1].options.headers["Idempotency-Key"], token);
    assert.equal(calls[2].options.headers["Idempotency-Key"], token);
    assert.equal(await transport.submit("company.update", token, {}, "company-one"), null);
    assert.equal(calls.length, 3);
});

test("missing issue header and nonconclusive HTTP responses fail closed", async () => {
    let response = new Response("{}", { status: 201 });
    const transport = createCompanyIdempotencyTransportLab({ fetchResponse: async () => response, csrf: async () => "synthetic-csrf" });
    await assert.rejects(transport.issue("company.create"), /Emissão indisponível/);
    response = new Response("{}", { status: 503 });
    assert.equal(await transport.status("company.create", "synthetic-token"), null);
    assert.equal(await transport.submit("company.create", "synthetic-token", {}), null);
});
