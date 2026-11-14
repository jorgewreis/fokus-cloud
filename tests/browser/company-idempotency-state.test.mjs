import { test } from "node:test";
import assert from "node:assert/strict";
import { companyIdempotencyOutcome } from "../../public/backoffice/assets/js/company-idempotency-state.js";

test("only a valid completed result is conclusive", () => {
    assert.equal(companyIdempotencyOutcome({ state: "completed", revoked: false, result: { id: "synthetic", status: 201 } }, "company.create"), "completed");
    assert.equal(companyIdempotencyOutcome({ state: "completed", revoked: true, result: { id: "synthetic", status: 200 } }, "company.update", "synthetic"), "completed");
    for (const state of ["issued", "observed", "absent", "unknown"]) {
        for (const revoked of [true, false, null]) {
            assert.equal(companyIdempotencyOutcome({ state, revoked }, "company.create"), "indeterminate");
        }
    }
    for (const response of [null, {}, { state: "completed" }, { state: "completed", result: { id: "synthetic", status: 503 } }]) {
        assert.equal(companyIdempotencyOutcome(response, "company.create"), "indeterminate");
    }
});

test("rejects malformed and contradictory completion results", () => {
    const completed = (id, status) => ({ state: "completed", result: { id, status } });
    for (const id of ["", " ", "x".repeat(129), null]) {
        assert.equal(companyIdempotencyOutcome(completed(id, 201), "company.create"), "indeterminate");
    }
    for (const status of [200, 202, 204, 500, "201"]) {
        assert.equal(companyIdempotencyOutcome(completed("synthetic", status), "company.create"), "indeterminate");
    }
    for (const status of [201, 202, 204, "200"]) {
        assert.equal(companyIdempotencyOutcome(completed("synthetic", status), "company.update", "synthetic"), "indeterminate");
    }
    assert.equal(companyIdempotencyOutcome(completed("other", 200), "company.update", "synthetic"), "indeterminate");
    assert.equal(companyIdempotencyOutcome(completed("synthetic", 200), "company.update"), "indeterminate");
    assert.equal(companyIdempotencyOutcome(completed("synthetic", 201), "other"), "indeterminate");
});
