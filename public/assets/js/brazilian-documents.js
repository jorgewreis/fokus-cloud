(function (window) {
    "use strict";

    const compact = (value) => String(value ?? "")
        .toUpperCase()
        .replace(/[^0-9A-Z]/g, "");

    const cpfDigits = (value) => String(value ?? "").replace(/\D/g, "");

    const formatParts = (value, separators) => {
        const parts = [];
        let cursor = 0;
        separators.forEach(([length, separator]) => {
            const part = value.slice(cursor, cursor + length);
            if (part) parts.push(part);
            cursor += length;
            if (part.length === length && cursor < value.length) parts.push(separator);
        });
        const remainder = value.slice(cursor);
        if (remainder) parts.push(remainder);
        return parts.join("");
    };

    const formatCpf = (value) => formatParts(cpfDigits(value).slice(0, 11), [
        [3, "."], [3, "."], [3, "-"]
    ]);

    const formatCnpj = (value) => formatParts(compact(value).slice(0, 14), [
        [2, "."], [3, "."], [3, "/"], [4, "-"]
    ]);

    const cpf = (value) => {
        const digits = cpfDigits(value);
        if (digits.length !== 11 || /^(\d)\1+$/.test(digits)) return false;

        for (let length = 9; length <= 10; length += 1) {
            const sum = digits.slice(0, length).split("").reduce(
                (total, digit, index) => total + Number(digit) * (length + 1 - index), 0
            );
            const expected = ((sum * 10) % 11) % 10;
            if (expected !== Number(digits[length])) return false;
        }

        return true;
    };

    const cnpj = (value) => {
        const normalized = compact(value);
        if (!/^[0-9A-Z]{12}[0-9]{2}$/.test(normalized) || /^(.)\1+$/.test(normalized)) return false;

        const calculate = (length, firstWeight) => {
            let weight = firstWeight;
            const sum = normalized.slice(0, length).split("").reduce((total, character) => {
                const numericValue = character.charCodeAt(0) - 48;
                const result = total + numericValue * weight;
                weight = weight === 2 ? 9 : weight - 1;
                return result;
            }, 0);
            const remainder = sum % 11;
            return remainder < 2 ? 0 : 11 - remainder;
        };

        return calculate(12, 5) === Number(normalized[12])
            && calculate(13, 6) === Number(normalized[13]);
    };

    const typeOf = (value) => {
        const normalized = compact(value);
        return normalized.length > 11 || /[A-Z]/.test(normalized) ? "cnpj" : "cpf";
    };
    const format = (value, type) => type === "cpf" ? formatCpf(value) : formatCnpj(value);
    const valid = (value, type) => type === "cpf" ? cpf(value) : type === "cnpj" ? cnpj(value) : (cpf(value) || cnpj(value));
    const normalize = (value, type) => type === "cpf" ? cpfDigits(value).slice(0, 11) : compact(value).slice(0, 14);
    const bind = (input, getType) => {
        const resolveType = () => typeof getType === "function" ? getType() : getType;
        const apply = () => {
            const type = resolveType();
            const start = input.selectionStart;
            const before = start == null ? null : normalize(input.value.slice(0, start), type).length;
            input.value = format(input.value, type);
            if (before !== null && document.activeElement === input) {
                let position = 0;
                let count = 0;
                while (position < input.value.length && count < before) {
                    if (/[0-9A-Z]/i.test(input.value[position])) count += 1;
                    position += 1;
                }
                input.setSelectionRange(position, position);
            }
        };
        input.addEventListener("input", apply);
        apply();
        return { apply, valid: () => valid(input.value, resolveType()), normalize: () => normalize(input.value, resolveType()) };
    };

    window.FokusDocuments = { compact, cpf, cnpj, format, formatCpf, formatCnpj, typeOf, valid, normalize, bind };
})(window);
