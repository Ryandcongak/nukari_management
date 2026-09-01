/*
|--------------------------------------------------------------------------
| Nukari UI Global Components
|--------------------------------------------------------------------------
| v1.0 - Currency Input Formatter
| - Menampilkan nominal dengan titik ribuan: 2300000 -> 2.300.000
| - Saat form disubmit, nilai dikembalikan ke angka mentah: 2300000
| - Otomatis aktif pada input uang yang memiliki class .currency-input
|   atau input dengan nama field nominal/amount/price/cost/salary/dll.
*/
(function () {
    'use strict';

    const MONEY_NAME_PATTERN = /(amount|nominal|price|cost|salary|rate|balance|payment|paid|deduction|bonus|allowance|tax|bpjs|hpp|profit|value|deposit|subtotal|discount|contribution|fee|shipping|total|cash|loan|advance|overhead|material|labor|rental)/i;
    const EXCLUDE_NAME_PATTERN = /(percent|percentage|qty|quantity|stock|days|day|hours|hour|minutes|minute|month|year|date|id|code|phone|number|barcode|nik|fingerprint|score|status|type)/i;

    function rawNumber(value) {
        return String(value || '').replace(/[^0-9]/g, '');
    }

    function formatRupiahNumber(value) {
        const raw = rawNumber(value);
        if (raw === '') return '';
        return new Intl.NumberFormat('id-ID').format(Number(raw));
    }

    function shouldAutoCurrency(input) {
        if (!input || input.dataset.noCurrency === '1') return false;
        if (input.classList.contains('currency-input')) return true;

        const name = input.getAttribute('name') || '';
        const id = input.getAttribute('id') || '';
        const combined = `${name} ${id}`;

        if (EXCLUDE_NAME_PATTERN.test(combined)) return false;
        return MONEY_NAME_PATTERN.test(combined);
    }

    function moveCursorToEnd(input) {
        try {
            const len = input.value.length;
            input.setSelectionRange(len, len);
        } catch (e) {}
    }

    function setTargetValue(input, raw) {
        const targetName = input.dataset.target;
        if (!targetName) return;
        const hiddenInput = document.querySelector(`input[name="${targetName}"]`);
        if (hiddenInput) hiddenInput.value = raw;
    }

    function formatInput(input) {
        const raw = rawNumber(input.value);
        input.value = raw === '' ? '' : new Intl.NumberFormat('id-ID').format(Number(raw));
        setTargetValue(input, raw);
    }

    function prepareInput(input) {
        if (!shouldAutoCurrency(input)) return;
        if (input.dataset.nukariCurrencyReady === '1') return;

        input.dataset.nukariCurrencyReady = '1';
        input.classList.add('currency-input');
        input.setAttribute('inputmode', 'numeric');
        input.setAttribute('autocomplete', 'off');

        // type=number tidak bisa menampilkan titik ribuan, jadi diubah menjadi text.
        if ((input.getAttribute('type') || '').toLowerCase() === 'number') {
            input.setAttribute('type', 'text');
        }

        if (!input.placeholder) {
            input.placeholder = '0';
        }

        if (input.value !== '') formatInput(input);

        input.addEventListener('input', function () {
            formatInput(input);
            moveCursorToEnd(input);

            if (typeof window.calculatePercentageFromAmount === 'function') {
                window.calculatePercentageFromAmount();
            }
            if (typeof window.calculateTotals === 'function') {
                window.calculateTotals();
            }
        });

        input.addEventListener('blur', function () {
            formatInput(input);
        });
    }

    function prepareForm(form) {
        if (!form || form.dataset.nukariCurrencySubmitReady === '1') return;
        form.dataset.nukariCurrencySubmitReady = '1';

        form.addEventListener('submit', function () {
            form.querySelectorAll('input.currency-input').forEach(function (input) {
                const raw = rawNumber(input.value);
                setTargetValue(input, raw);
                if (!input.dataset.target && input.name) {
                    input.value = raw;
                }
            });
        });
    }

    function initCurrencyInputs(scope) {
        const root = scope || document;
        root.querySelectorAll('input').forEach(prepareInput);
        root.querySelectorAll('form').forEach(prepareForm);
    }

    document.addEventListener('DOMContentLoaded', function () {
        initCurrencyInputs(document);

        // Memberi petunjuk aksesibel pada tabel yang dapat digeser di layar kecil.
        document.querySelectorAll('.table-responsive').forEach(function (wrapper) {
            wrapper.setAttribute('tabindex', '0');
            wrapper.setAttribute('role', 'region');
            wrapper.setAttribute('aria-label', wrapper.getAttribute('aria-label') || 'Tabel dapat digeser ke samping');
        });

        // Untuk modal/form yang muncul dinamis.
        const observer = new MutationObserver(function (mutations) {
            mutations.forEach(function (mutation) {
                mutation.addedNodes.forEach(function (node) {
                    if (node.nodeType !== 1) return;
                    if (node.matches && node.matches('input, form')) {
                        if (node.matches('input')) prepareInput(node);
                        if (node.matches('form')) prepareForm(node);
                    }
                    if (node.querySelectorAll) initCurrencyInputs(node);
                });
            });
        });

        observer.observe(document.body, { childList: true, subtree: true });
    });

    window.NukariUI = window.NukariUI || {};
    window.NukariUI.rawNumber = rawNumber;
    window.NukariUI.formatRupiahNumber = formatRupiahNumber;
    window.NukariUI.initCurrencyInputs = initCurrencyInputs;
})();
