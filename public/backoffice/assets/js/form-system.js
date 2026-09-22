(function (window, document) {
    "use strict";

    const controls = "input, select, textarea";
    const requiredControls = "input[required], select[required], textarea[required]";

    function getField(form, name) {
        return form.querySelector(`[name="${CSS.escape(name)}"]`);
    }

    function feedbackId(field) {
        return `${field.id || field.name}-error`;
    }

    function clearField(field) {
        document.getElementById(feedbackId(field))?.remove();
        field.removeAttribute("aria-invalid");
        field.removeAttribute("aria-describedby");
        field.classList.remove("is-invalid", "is-valid");
        field.closest(".fs-form-col")?.classList.remove("is-invalid", "is-valid");
    }

    function setFeedback(field, message, type = "error") {
        if (!field) return;
        clearField(field);
        const feedback = document.createElement("small");
        feedback.id = feedbackId(field);
        feedback.className = type === "error" ? "fs-invalid-feedback" : "fs-valid-feedback";
        feedback.setAttribute("role", type === "error" ? "alert" : "status");
        feedback.textContent = message;
        field.insertAdjacentElement("afterend", feedback);
        field.setAttribute("aria-invalid", type === "error" ? "true" : "false");
        field.setAttribute("aria-describedby", feedback.id);
        field.classList.add(type === "error" ? "is-invalid" : "is-valid");
        field.closest(".fs-form-col")?.classList.add(type === "error" ? "is-invalid" : "is-valid");
    }

    function validationMessage(field) {
        if (field.validity.valueMissing) return "Este campo é obrigatório.";
        if (field.validity.typeMismatch && field.type === "email") return "Informe um e-mail válido.";
        if (field.validity.tooShort) return `Informe ao menos ${field.minLength} caracteres.`;
        if (field.validity.tooLong) return `Use no máximo ${field.maxLength} caracteres.`;
        if (field.validity.rangeUnderflow) return `Informe um valor maior ou igual a ${field.min}.`;
        if (field.validity.rangeOverflow) return `Informe um valor menor ou igual a ${field.max}.`;
        if (field.validity.patternMismatch) return "Informe um valor válido.";
        return field.validationMessage;
    }

    function validateField(field) {
        if (!field || field.disabled || field.readOnly) return true;
        if (!field.value && !field.required) {
            clearField(field);
            return true;
        }
        if (!field.checkValidity()) {
            setFeedback(field, validationMessage(field));
            return false;
        }
        clearField(field);
        return true;
    }

    function clearSummary(form) { form.querySelector("[data-fs-form-error-summary]")?.remove(); }

    function renderSummary(form, errors) {
        clearSummary(form);
        const entries = Object.entries(errors).filter(([, message]) => message);
        if (!entries.length) return;
        const summary = document.createElement("div");
        summary.className = "fs-alert fs-alert-danger";
        summary.dataset.fsFormErrorSummary = "true";
        summary.setAttribute("role", "alert");
        summary.innerHTML = "<strong>Revise os campos destacados.</strong>";
        const list = document.createElement("ul");
        entries.forEach(([name, message]) => {
            const field = getField(form, name);
            const item = document.createElement("li");
            const link = document.createElement("a");
            link.href = field?.id ? `#${field.id}` : "#";
            link.textContent = message;
            item.appendChild(link);
            list.appendChild(item);
        });
        summary.appendChild(list);
        form.prepend(summary);
    }

    function validate(form) {
        const errors = {};
        form.classList.add("fs-was-validated");
        form.querySelectorAll(controls).forEach((field) => {
            clearField(field);
            if (!field.checkValidity()) {
                errors[field.name || field.id] = validationMessage(field);
                setFeedback(field, validationMessage(field));
            }
        });
        renderSummary(form, errors);
        form.querySelector('[aria-invalid="true"]')?.focus();
        return Object.keys(errors).length === 0;
    }

    function mapServerErrors(form, errors) {
        const normalized = {};
        Object.entries(errors || {}).forEach(([name, messages]) => {
            const field = getField(form, name);
            const rawMessage = Array.isArray(messages) ? messages[0] : messages;
            const message = rawMessage === "validation.max.string" && field?.maxLength > 0
                ? `Use no máximo ${field.maxLength} caracteres.`
                : rawMessage;
            if (!message) return;
            normalized[name] = message;
            setFeedback(field, message);
        });
        renderSummary(form, normalized);
        form.querySelector('[aria-invalid="true"]')?.focus();
    }

    function setLoading(form, loading, button = form.querySelector("button[type=submit]")) {
        form.toggleAttribute("aria-busy", loading);
        button?.classList.toggle("is-loading", loading);
        if (button) button.disabled = loading;
    }

    function clear(form) {
        clearSummary(form);
        form.classList.remove("fs-was-validated");
        form.querySelectorAll(controls).forEach(clearField);
    }

    function markRequiredFields(root = document) {
        const fields = root.matches?.(".fs-form-col")
            ? [root, ...root.querySelectorAll(".fs-form-col")]
            : [...root.querySelectorAll(".fs-form-col")];

        fields.forEach((field) => {
            const required = field.querySelector(requiredControls);

            if (!required) {
                return;
            }

            const label = required.id
                ? field.querySelector(`label[for="${CSS.escape(required.id)}"]`)
                : field.querySelector("label, legend, .fs-form-label");

            if (!label) {
                return;
            }

            label.setAttribute("data-required", "true");
        });
    }

    function enhance(root = document) {
        markRequiredFields(root);
        root.querySelectorAll?.(controls).forEach((field) => {
            if (field.dataset.fsValidationBound === "true") return;
            field.dataset.fsValidationBound = "true";
            field.addEventListener("blur", () => validateField(field));
        });
    }

    const observer = new MutationObserver((mutations) => {
        mutations.forEach((mutation) => {
            mutation.addedNodes.forEach((node) => {
                if (node.nodeType !== Node.ELEMENT_NODE) {
                    return;
                }

                if (node.matches?.(".fs-form-col, form, .fs-form")) {
                    enhance(node);
                    return;
                }

                if (node.querySelector?.(".fs-form-col")) {
                    enhance(node);
                }
            });
        });
    });

    if (document.readyState === "loading") {
        document.addEventListener("DOMContentLoaded", () => enhance(document), { once: true });
    } else {
        enhance(document);
    }

    observer.observe(document.documentElement, { childList: true, subtree: true });

    window.FokusForm = { clear, clearField, enhance, mapServerErrors, markRequiredFields, setFeedback, setLoading, validate, validateField };
})(window, document);
