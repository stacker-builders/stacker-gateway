/**
 * SDK PagBank: criptografa o cartão no navegador (PagSeguro.encryptCard).
 * O PAN não segue para o nosso servidor.
 * @see https://developer.pagbank.com.br/reference/criar-pagar-pedido-com-cartao
 */

const SDK_SRC = 'https://assets.pagseguro.com.br/checkout-sdk-js/rc/dist/browser/pagseguro.min.js';

function loadSdk() {
    if (typeof window === 'undefined') {
        return Promise.reject(new Error('SDK da PagBank indisponível neste ambiente.'));
    }
    if (window.PagSeguro && typeof window.PagSeguro.encryptCard === 'function') {
        return Promise.resolve(window.PagSeguro);
    }

    return new Promise((resolve, reject) => {
        const fail = () => reject(new Error('Não foi possível carregar o SDK da PagBank.'));
        const done = () => {
            if (window.PagSeguro && typeof window.PagSeguro.encryptCard === 'function') {
                resolve(window.PagSeguro);
                return;
            }
            fail();
        };
        const existing = document.querySelector('script[data-pagbank-sdk]');
        if (existing) {
            existing.addEventListener('load', done, { once: true });
            existing.addEventListener('error', fail, { once: true });
            return;
        }
        const script = document.createElement('script');
        script.src = SDK_SRC;
        script.async = true;
        script.setAttribute('data-pagbank-sdk', '1');
        script.onload = done;
        script.onerror = fail;
        document.head.appendChild(script);
    });
}

/**
 * @param {{ publicKey: string, holder: string, number: string, expMonth: string, expYear: string, securityCode: string }} input
 * @returns {Promise<string>}
 */
export async function encryptPagBankCard(input) {
    const publicKey = String(input.publicKey || '').trim();
    if (publicKey === '') {
        throw new Error('Chave pública da PagBank não configurada.');
    }
    const PagSeguro = await loadSdk();
    let expYear = String(input.expYear || '').replace(/\D/g, '');
    if (expYear.length === 2) {
        expYear = `20${expYear}`;
    }
    const card = PagSeguro.encryptCard({
        publicKey,
        holder: String(input.holder || '').trim(),
        number: String(input.number || '').replace(/\D/g, ''),
        expMonth: String(input.expMonth || '').replace(/\D/g, '').padStart(2, '0').slice(0, 2),
        expYear,
        securityCode: String(input.securityCode || '').replace(/\D/g, ''),
    });
    if (card?.hasErrors) {
        const first = Array.isArray(card.errors) ? card.errors[0] : null;
        const message = first && typeof first.message === 'string' && first.message.trim() !== ''
            ? first.message.trim()
            : 'Não foi possível criptografar o cartão.';
        throw new Error(message);
    }
    const encrypted = typeof card?.encryptedCard === 'string' ? card.encryptedCard.trim() : '';
    if (encrypted === '') {
        throw new Error('PagBank não retornou o cartão criptografado.');
    }

    return encrypted;
}
