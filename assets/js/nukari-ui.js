/*
|--------------------------------------------------------------------------
| Nukari UI Global Components
|--------------------------------------------------------------------------
| v1.1 - Currency Input Formatter & Global Table Sorting
| - Menampilkan nominal dengan titik ribuan: 2300000 -> 2.300.000
| - Saat form disubmit, nilai dikembalikan ke angka mentah: 2300000
| - Otomatis aktif pada input uang yang memiliki class .currency-input
|   atau input dengan nama field nominal/amount/price/cost/salary/dll.
| - Otomatis mengaktifkan sorting pada tabel melalui judul kolom.
*/
(function () {
    'use strict';

    const MONEY_NAME_PATTERN = /(amount|nominal|price|cost|salary|rate|balance|payment|paid|deduction|bonus|allowance|tax|bpjs|hpp|profit|value|deposit|subtotal|discount|contribution|fee|shipping|total|cash|loan|advance|overhead|material|labor|rental)/i;
    const EXCLUDE_NAME_PATTERN = /(percent|percentage|qty|quantity|stock|days|day|hours|hour|minutes|minute|month|year|date|id|code|phone|number|barcode|nik|fingerprint|score|status|type)/i;
    const TEXT_NAME_PATTERN = /(^|[_-])(name|nama|description|desc|note|notes|catatan|label|title|program|event|method|reference)([_-]|$)/i;

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
        // Kata seperti "material" atau "payment" juga dapat muncul pada field
        // teks (contoh: material_name dan payment_notes). Jangan hapus hurufnya
        // dengan formatter angka hanya karena nama field memuat kata tersebut.
        if (TEXT_NAME_PATTERN.test(combined)) return false;
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

    const INDONESIAN_MONTHS = {
        jan: 0, januari: 0, feb: 1, februari: 1, mar: 2, maret: 2,
        apr: 3, april: 3, mei: 4, may: 4, jun: 5, juni: 5,
        jul: 6, juli: 6, agu: 7, ags: 7, agustus: 7, aug: 7,
        sep: 8, september: 8, okt: 9, oktober: 9, oct: 9,
        nov: 10, november: 10, des: 11, desember: 11, dec: 11
    };

    function sortableText(cell) {
        if (!cell) return '';
        const value = String(cell.dataset.sortValue ?? cell.innerText ?? cell.textContent ?? '').trim();
        // Pada sel dengan detail tambahan, baris pertama adalah nilai utama tabel.
        return value.split(/\r?\n/).map(part => part.trim()).find(Boolean) || '';
    }

    function sortableNumber(value) {
        let normalized = String(value || '').trim().replace(/\s/g, '');
        if (!normalized || normalized === '-') return null;
        const duration = String(value).toLowerCase().match(/(?:(\d+(?:[.,]\d+)?)\s*jam(?:\s*(\d+)\s*menit)?|(\d+)\s*menit)/);
        if (duration) {
            return Number(String(duration[1] || 0).replace(',', '.')) * 60 + Number(duration[2] || duration[3] || 0);
        }
        normalized = normalized.replace(/Rp/gi, '').replace(/%/g, '').replace(/[^0-9,.-]/g, '');
        if (/^-?\d{1,3}(?:\.\d{3})+(?:,\d+)?$/.test(normalized)) {
            normalized = normalized.replace(/\./g, '').replace(',', '.');
        } else if (/^-?\d+(?:,\d+)?$/.test(normalized)) {
            normalized = normalized.replace(',', '.');
        } else if ((normalized.match(/\./g) || []).length > 1) {
            normalized = normalized.replace(/\./g, '');
        }
        const number = Number(normalized);
        return Number.isFinite(number) ? number : null;
    }

    function sortableDate(value) {
        const text = String(value || '').trim().toLowerCase();
        if (!text || text === '-') return null;
        let match = text.match(/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ t](\d{1,2}):(\d{2}))?/);
        if (match) return new Date(+match[1], +match[2] - 1, +match[3], +(match[4] || 0), +(match[5] || 0)).getTime();
        match = text.match(/^(\d{1,2})[\s\/-]+([a-z]+|\d{1,2})[\s\/-]+(\d{4})(?:\s+(\d{1,2}):(\d{2}))?/);
        if (match) {
            const month = /^\d+$/.test(match[2]) ? +match[2] - 1 : INDONESIAN_MONTHS[match[2]];
            if (month !== undefined) return new Date(+match[3], month, +match[1], +(match[4] || 0), +(match[5] || 0)).getTime();
        }
        return null;
    }

    function sortableType(header, rows, columnIndex) {
        const explicit = header.dataset.sortType;
        if (['number', 'date', 'text'].includes(explicit)) return explicit;
        const title = sortableText(header).toLowerCase();
        if (/(tanggal|tgl|periode|jatuh tempo|dibuat|diperbarui|waktu)/.test(title)) return 'date';
        if (/(nominal|jumlah|total|harga|nilai|saldo|gaji|upah|rate|persen|%|qty|stok|hari|jam|menit|no\.?$)/.test(title)) return 'number';
        const samples = rows.map(row => sortableText(row.cells[columnIndex])).filter(value => value && value !== '-').slice(0, 8);
        if (samples.length && samples.every(value => sortableDate(value) !== null)) return 'date';
        if (samples.length && samples.every(value => sortableNumber(value) !== null)) return 'number';
        return 'text';
    }

    function initSortableTable(table) {
        if (!table || table.dataset.nukariSortableReady === '1' || table.dataset.sortable === 'false') return;
        // Tabel yang sudah memiliki sorter khusus tetap memakai implementasinya sendiri.
        if (table.classList.contains('sortable-table') && table.querySelector('th.sortable')) return;
        const headerRow = table.tHead
            ? table.tHead.rows[table.tHead.rows.length - 1]
            : Array.from(table.rows).find(row => row.querySelector('th'));
        const body = table.tBodies[0];
        if (!headerRow || !body || !headerRow.cells.length) return;
        if (table.tHead && table.tHead.rows.length > 1) return;

        const headers = Array.from(headerRow.cells);
        const sortableHeaders = headers.filter(header => {
            const title = sortableText(header).toLowerCase();
            return header.tagName === 'TH' && header.colSpan === 1 && title !== '' && !/^(aksi|action|opsi)$/.test(title) && header.dataset.sortable !== 'false';
        });
        if (!sortableHeaders.length) return;

        table.dataset.nukariSortableReady = '1';
        sortableHeaders.forEach(header => {
            header.classList.add('nukari-sortable-header');
            header.setAttribute('tabindex', '0');
            header.setAttribute('role', 'button');
            header.setAttribute('aria-sort', 'none');
            header.title = header.title || 'Klik untuk mengurutkan kolom ini';
            const icon = document.createElement('span');
            icon.className = 'nukari-sort-indicator';
            icon.setAttribute('aria-hidden', 'true');
            header.appendChild(icon);

            function sortColumn() {
                const columnIndex = header.cellIndex;
                const direction = header.dataset.sortDirection === 'asc' ? 'desc' : 'asc';
                const rows = Array.from(body.rows).filter(row => row !== headerRow && row.cells.length > columnIndex && !row.querySelector('td[colspan]'));
                const stationaryRows = Array.from(body.rows).filter(row => !rows.includes(row) && row !== headerRow);
                const type = sortableType(header, rows, columnIndex);
                const collator = new Intl.Collator('id-ID', { numeric: true, sensitivity: 'base' });

                sortableHeaders.forEach(other => {
                    other.dataset.sortDirection = '';
                    other.setAttribute('aria-sort', 'none');
                    other.classList.remove('nukari-sorted-asc', 'nukari-sorted-desc');
                });
                header.dataset.sortDirection = direction;
                header.setAttribute('aria-sort', direction === 'asc' ? 'ascending' : 'descending');
                header.classList.add(direction === 'asc' ? 'nukari-sorted-asc' : 'nukari-sorted-desc');

                rows.map((row, index) => ({ row, index })).sort((a, b) => {
                    const aText = sortableText(a.row.cells[columnIndex]);
                    const bText = sortableText(b.row.cells[columnIndex]);
                    let result;
                    if (type === 'number') result = (sortableNumber(aText) ?? Number.NEGATIVE_INFINITY) - (sortableNumber(bText) ?? Number.NEGATIVE_INFINITY);
                    else if (type === 'date') result = (sortableDate(aText) ?? Number.NEGATIVE_INFINITY) - (sortableDate(bText) ?? Number.NEGATIVE_INFINITY);
                    else result = collator.compare(aText, bText);
                    if (result === 0) result = a.index - b.index;
                    return direction === 'asc' ? result : -result;
                }).forEach(item => body.appendChild(item.row));
                stationaryRows.forEach(row => body.appendChild(row));
            }

            header.addEventListener('click', event => {
                if (event.target.closest('a, button, input, select')) return;
                sortColumn();
            });
            header.addEventListener('keydown', event => {
                if (event.key !== 'Enter' && event.key !== ' ') return;
                event.preventDefault();
                sortColumn();
            });
        });
    }

    function initSortableTables(scope) {
        const root = scope || document;
        if (root.matches && root.matches('table')) initSortableTable(root);
        root.querySelectorAll('table').forEach(initSortableTable);
    }

    document.addEventListener('DOMContentLoaded', function () {
        initCurrencyInputs(document);
        initSortableTables(document);

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
                    if (node.querySelectorAll) {
                        initCurrencyInputs(node);
                        initSortableTables(node);
                    }
                });
            });
        });

        observer.observe(document.body, { childList: true, subtree: true });
    });

    window.NukariUI = window.NukariUI || {};
    window.NukariUI.rawNumber = rawNumber;
    window.NukariUI.formatRupiahNumber = formatRupiahNumber;
    window.NukariUI.initCurrencyInputs = initCurrencyInputs;
    window.NukariUI.initSortableTables = initSortableTables;
})();
