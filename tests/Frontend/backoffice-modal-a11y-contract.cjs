'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');

function modal(file, id) {
    const html = fs.readFileSync(path.join(root, file), 'utf8');
    const start = html.indexOf(`id="${id}"`);
    assert.notEqual(start, -1, `${id} não foi encontrado em ${file}.`);
    return html.slice(Math.max(0, start - 120), start + 520);
}

function assertDialog(file, id, titleId, descriptionId) {
    const markup = modal(file, id);
    assert.match(markup, /role="dialog"/, `${id} deve expor role="dialog".`);
    assert.match(markup, /aria-modal="true"/, `${id} deve declarar modalidade.`);
    assert.match(markup, new RegExp(`aria-labelledby="${titleId}"`), `${id} deve referenciar seu título.`);
    if (descriptionId) assert.match(markup, new RegExp(`aria-describedby="${descriptionId}"`), `${id} deve referenciar sua descrição.`);
}

assertDialog('public/backoffice/pages/pagamentos.html', 'billing-action-modal', 'billing-action-title', 'billing-action-description');
assertDialog('public/backoffice/pages/security.html', 'invite-dialog', 'invite-dialog-title', 'invite-dialog-description');
assertDialog('public/backoffice/pages/ui-components.html', 'component-modal', 'component-modal-title', 'component-modal-description');

console.log('backoffice-modal-a11y-contract: modal semantics and references are present');
