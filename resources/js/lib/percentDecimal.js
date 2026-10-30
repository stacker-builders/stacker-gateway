/**
 * Normaliza percentual para até 4 casas (0–100), alinhado a App\Support\PercentDecimal.
 */
export function normalizePercentInput(value) {
    if (value === null || value === undefined || value === '') {
        return 0;
    }
    const n = parseFloat(String(value).trim().replace(',', '.'));
    if (!Number.isFinite(n)) {
        return 0;
    }
    return Math.round(n * 10000) / 10000;
}

/** Exibe percentual no input (pt-BR): 2.5 → "2,5". */
export function formatPercentForDisplayBr(value) {
    const s = formatPercentForInput(value);
    if (s === '') {
        return '';
    }
    return s.replace('.', ',');
}

/** Exibe percentual vindo do servidor sem ruído de float. */
export function formatPercentForInput(value) {
    if (value === null || value === undefined || value === '') {
        return '';
    }
    const n = Number(value);
    if (!Number.isFinite(n)) {
        return '';
    }
    const rounded = Math.round(n * 10000) / 10000;
    if (Number.isInteger(rounded)) {
        return String(rounded);
    }
    return String(rounded).replace(/(\.\d*?)0+$/, '$1').replace(/\.$/, '');
}

/**
 * @param {Record<string, { percent?: unknown, fixed?: unknown }>} rules
 */
export function normalizeMerchantFeeRulesForSubmit(rules) {
    if (!rules || typeof rules !== 'object') {
        return rules;
    }
    const out = {};
    for (const [key, block] of Object.entries(rules)) {
        if (key === 'card_installments' || key === 'low_ticket' || !block || typeof block !== 'object') {
            continue;
        }
        const row = {};
        if (block.percent !== '' && block.percent !== null && block.percent !== undefined) {
            row.percent = normalizePercentInput(block.percent);
        } else {
            row.percent = 0;
        }
        if (block.fixed !== '' && block.fixed !== null && block.fixed !== undefined) {
            const f = parseFloat(String(block.fixed).replace(',', '.'));
            row.fixed = Number.isFinite(f) ? Math.round(f * 100) / 100 : 0;
        } else {
            row.fixed = 0;
        }
        if (Object.prototype.hasOwnProperty.call(block, 'enabled')) {
            row.enabled = !!block.enabled;
        }
        out[key] = row;
    }
    return out;
}

/**
 * @param {Record<string|number, { percent?: unknown, fixed?: unknown, days_to_available?: unknown }>} rules
 */
export function normalizeCardInstallmentRulesForSubmit(rules) {
    const out = {};
    for (let n = 1; n <= 12; n++) {
        const block = rules && typeof rules === 'object' ? (rules[n] ?? rules[String(n)] ?? {}) : {};
        const f = parseFloat(String(block.fixed ?? 0).replace(',', '.'));
        const d = parseInt(String(block.days_to_available ?? 0), 10);
        out[n] = {
            percent: normalizePercentInput(block.percent),
            fixed: Number.isFinite(f) ? Math.round(f * 100) / 100 : 0,
            days_to_available: Number.isFinite(d) ? Math.max(0, Math.min(365, d)) : 0,
        };
    }
    return out;
}

/**
 * Overrides opcionais (infoprodutor): só envia chaves com % ou fixo preenchidos.
 */
export function normalizeMerchantFeeOverridesForSubmit(fees) {
    if (!fees || typeof fees !== 'object') {
        return null;
    }
    const out = {};
    for (const [key, block] of Object.entries(fees)) {
        if (!block || typeof block !== 'object') {
            continue;
        }
        const hasPercent = block.percent !== '' && block.percent !== null && block.percent !== undefined;
        const hasFixed = block.fixed !== '' && block.fixed !== null && block.fixed !== undefined;
        if (!hasPercent && !hasFixed) {
            continue;
        }
        const row = {};
        if (hasPercent) {
            row.percent = normalizePercentInput(block.percent);
        }
        if (hasFixed) {
            const f = parseFloat(String(block.fixed).replace(',', '.'));
            row.fixed = Number.isFinite(f) ? Math.round(f * 100) / 100 : 0;
        }
        if (Object.keys(row).length) {
            out[key] = row;
        }
    }
    return Object.keys(out).length ? out : null;
}

const SETTLEMENT_OVERRIDE_KEYS = ['pix', 'open_finance', 'card', 'apple_pay', 'google_pay', 'boleto'];

/**
 * Overrides opcionais de liquidação (infoprodutor): só envia campos preenchidos.
 *
 * @param {Record<string, { days_to_available?: unknown, reserve_percent?: unknown, reserve_hold_days?: unknown }>} overrides
 */
export function normalizeMerchantSettlementOverridesForSubmit(overrides) {
    if (!overrides || typeof overrides !== 'object') {
        return null;
    }
    const out = {};
    for (const key of SETTLEMENT_OVERRIDE_KEYS) {
        const block = overrides[key];
        if (!block || typeof block !== 'object') {
            continue;
        }
        const hasDays =
            block.days_to_available !== '' &&
            block.days_to_available !== null &&
            block.days_to_available !== undefined;
        const hasReserve =
            block.reserve_percent !== '' &&
            block.reserve_percent !== null &&
            block.reserve_percent !== undefined;
        const hasHold =
            block.reserve_hold_days !== '' &&
            block.reserve_hold_days !== null &&
            block.reserve_hold_days !== undefined;
        if (!hasDays && !hasReserve && !hasHold) {
            continue;
        }
        const row = {};
        if (hasDays) {
            const d = parseInt(String(block.days_to_available), 10);
            row.days_to_available = Number.isFinite(d) ? Math.max(0, Math.min(365, d)) : 0;
        }
        if (hasReserve) {
            const r = parseFloat(String(block.reserve_percent).replace(',', '.'));
            row.reserve_percent = Number.isFinite(r) ? Math.round(Math.min(100, Math.max(0, r)) * 100) / 100 : 0;
        }
        if (hasHold) {
            const h = parseInt(String(block.reserve_hold_days), 10);
            row.reserve_hold_days = Number.isFinite(h) ? Math.max(0, Math.min(365, h)) : 0;
        }
        if (Object.keys(row).length) {
            out[key] = row;
        }
    }
    return Object.keys(out).length ? out : null;
}
