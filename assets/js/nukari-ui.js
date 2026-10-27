/*
|--------------------------------------------------------------------------
| Nukari UI Global Components
|--------------------------------------------------------------------------
| v1.3 - Currency, Table Sorting & Contextual Tooltips
| - Menampilkan nominal dengan titik ribuan: 2300000 -> 2.300.000
| - Saat form disubmit, nilai dikembalikan ke angka mentah: 2300000
| - Otomatis aktif pada input uang yang memiliki class .currency-input
|   atau input dengan nama field nominal/amount/price/cost/salary/dll.
| - Otomatis mengaktifkan sorting pada tabel melalui judul kolom.
| - Memberikan tooltip kontekstual pada menu, aksi, form, status, dan card.
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

    const IS_REPORT_PAGE = /\/pages\/reports\//.test(window.location.pathname);
    const STAT_HELP = {
        'total karyawan': 'Jumlah seluruh karyawan yang tercakup pada halaman dan filter yang sedang digunakan.',
        'karyawan aktif': 'Jumlah karyawan dengan status kerja aktif.',
        'hadir hari ini': 'Jumlah karyawan yang tercatat hadir pada tanggal hari ini.',
        'terlambat': 'Jumlah kehadiran yang melewati batas waktu masuk sesuai aturan absensi.',
        'izin / sakit': 'Jumlah ketidakhadiran yang tercatat sebagai izin atau sakit.',
        'alpha': 'Jumlah ketidakhadiran tanpa keterangan yang tercatat.',
        'total proyek': 'Jumlah proyek yang tercakup pada halaman dan filter saat ini.',
        'proyek aktif': 'Jumlah proyek yang masih berjalan atau belum selesai.',
        'nilai kontrak': 'Total nilai kontrak proyek yang tercakup dalam data saat ini.',
        'termin dibayar': 'Total nilai termin proyek yang sudah berstatus dibayar.',
        'total pemasukan': 'Jumlah uang masuk yang tercatat pada periode dan filter saat ini.',
        'total pengeluaran': 'Jumlah uang keluar yang tercatat pada periode dan filter saat ini.',
        'saldo': 'Jumlah uang yang tersedia berdasarkan transaksi yang sudah tercatat.',
        'saldo akhir': 'Posisi uang setelah seluruh pemasukan dan pengeluaran pada periode dihitung.',
        'hutang': 'Kewajiban perusahaan yang masih harus dibayarkan.',
        'piutang': 'Hak tagih perusahaan yang pembayarannya belum diterima.',
        'gaji bersih': 'Gaji akhir setelah komponen pendapatan ditambah dan seluruh potongan dikurangi.',
        'gaji pokok': 'Nilai gaji dasar sebelum tunjangan, tambahan, dan potongan.',
        'total potongan': 'Jumlah seluruh komponen yang mengurangi nilai payroll.',
        'total aset': 'Jumlah aset perusahaan yang tercatat pada halaman ini.',
        'nilai aset': 'Total nilai aset berdasarkan data yang tersimpan.',
        'stok': 'Jumlah persediaan produk yang tercatat saat ini.',
        'penjualan': 'Ringkasan transaksi penjualan sesuai periode dan filter saat ini.',
        'omzet': 'Total nilai penjualan sebelum dikurangi biaya atau harga pokok.',
        'laba': 'Selisih antara nilai penjualan dan biaya atau harga pokok yang tercatat.',
        'pending': 'Jumlah data yang masih menunggu tindakan atau penyelesaian.',
        'perlu review': 'Jumlah data yang memerlukan pemeriksaan pengguna.'
    };
    const STATUS_HELP = {
        active: 'Status aktif: data ini masih digunakan dalam operasional.',
        aktif: 'Status aktif: data ini masih digunakan dalam operasional.',
        inactive: 'Status tidak aktif: data disimpan tetapi tidak digunakan untuk operasional baru.',
        'tidak aktif': 'Status tidak aktif: data disimpan tetapi tidak digunakan untuk operasional baru.',
        draft: 'Draft: data masih dapat diperiksa dan belum menjadi catatan final.',
        pending: 'Pending: data masih menunggu proses atau tindak lanjut.',
        posted: 'Posted: transaksi sudah dibukukan dan memengaruhi saldo.',
        historical: 'Historis: data lama untuk dokumentasi dan tidak mengubah saldo berjalan.',
        paid: 'Paid: pembayaran sudah dicatat selesai.',
        dibayar: 'Dibayar: pembayaran sudah dicatat selesai.',
        unpaid: 'Belum dibayar: masih terdapat pembayaran yang harus diselesaikan.',
        approved: 'Disetujui: data sudah melewati proses persetujuan yang berlaku.',
        disetujui: 'Disetujui: data sudah melewati proses persetujuan yang berlaku.',
        rejected: 'Ditolak: data tidak diterima pada proses pemeriksaan.',
        ditolak: 'Ditolak: data tidak diterima pada proses pemeriksaan.',
        completed: 'Selesai: proses atau pekerjaan sudah ditutup.',
        selesai: 'Selesai: proses atau pekerjaan sudah ditutup.',
        ongoing: 'Berjalan: proses atau pekerjaan masih berlangsung.',
        berjalan: 'Berjalan: proses atau pekerjaan masih berlangsung.',
        cancelled: 'Dibatalkan: data tidak dilanjutkan ke proses berikutnya.',
        dibatalkan: 'Dibatalkan: data tidak dilanjutkan ke proses berikutnya.'
    };

    function normalizedText(value) {
        return String(value || '').replace(/\s+/g, ' ').trim();
    }

    function elementsIn(scope, selector) {
        const root = scope || document;
        const items = [];
        if (root.matches && root.matches(selector)) items.push(root);
        if (root.querySelectorAll) items.push(...root.querySelectorAll(selector));
        return items;
    }

    function registerTooltip(element, message, placement) {
        if (!element || !message || element.dataset.nukariTooltipReady === '1') return;
        element.dataset.nukariTooltipReady = '1';
        element.dataset.nukariTooltip = '1';
        element.setAttribute('data-bs-title', message);
        if (!element.matches('a, button, input, select, textarea, [tabindex], [contenteditable="true"]')) {
            element.setAttribute('tabindex', '0');
        }
        if (!element.getAttribute('aria-label') && !normalizedText(element.textContent)) {
            element.setAttribute('aria-label', message);
        }
        const existingToggle = (element.getAttribute('data-bs-toggle') || '').toLowerCase();
        if (element.matches(':disabled') || ['button', 'dropdown', 'tab', 'pill', 'popover'].includes(existingToggle)) {
            element.setAttribute('title', message);
            return;
        }
        if (window.bootstrap && window.bootstrap.Tooltip) {
            new window.bootstrap.Tooltip(element, {
                title: message,
                placement: placement || 'top',
                trigger: 'hover focus',
                container: 'body',
                customClass: 'nukari-global-tooltip',
                delay: { show: 180, hide: 60 }
            });
        }
    }

    function actionHelp(element) {
        const existing = element.dataset.tooltip || element.dataset.bsTitle || element.getAttribute('title') || element.getAttribute('aria-label');
        if (existing) return normalizedText(existing);
        let label = normalizedText(element.textContent);
        if (label.length > 80) {
            const concise = element.querySelector('.kpi-label, .metric-label, .stat-title, .card-title, h3, h4, h5, h6');
            label = normalizedText(concise ? concise.textContent : label.slice(0, 80));
        }
        const text = label.toLowerCase();
        if (!label) {
            const icon = element.querySelector('i');
            const iconClass = icon ? icon.className : '';
            if (/bi-(pencil|pen)/.test(iconClass)) return 'Ubah informasi pada data yang dipilih.';
            if (/bi-(trash|x-circle)/.test(iconClass)) return 'Hapus atau batalkan data yang dipilih.';
            if (/bi-(eye|search)/.test(iconClass)) return 'Lihat informasi lebih lengkap untuk data ini.';
            if (/bi-(plus|file-earmark-plus)/.test(iconClass)) return 'Tambahkan data baru.';
            if (/bi-(download|file-earmark-arrow-down)/.test(iconClass)) return 'Unduh data atau berkas ini.';
            if (/bi-(printer|file-pdf)/.test(iconClass)) return 'Cetak data atau simpan sebagai PDF.';
            if (/bi-(check|check2|check-circle)/.test(iconClass)) return 'Konfirmasi tindakan untuk data ini.';
            if (/bi-(chevron-left|arrow-left)/.test(iconClass)) return 'Kembali ke data atau halaman sebelumnya.';
            if (/bi-(chevron-right|arrow-right)/.test(iconClass)) return 'Lanjut ke data atau halaman berikutnya.';
            if (/bi-(three-dots|list)/.test(iconClass)) return 'Tampilkan pilihan tindakan lainnya.';
            if (/bi-(gear|sliders)/.test(iconClass)) return 'Buka pengaturan untuk bagian ini.';
            return '';
        }
        if (element.dataset.date) return `Buka aktivitas pada tanggal ${element.dataset.date}.`;
        if (/(logout|keluar)/.test(text)) return 'Keluar dari akun dan kembali ke halaman login.';
        if (/(hapus|delete|remove)/.test(text)) return 'Menghapus data yang dipilih. Periksa kembali karena tindakan ini dapat memengaruhi data terkait.';
        if (/(simpan|save)/.test(text)) return 'Simpan data yang sudah diisi atau diperbarui.';
        if (/(tambah|buat|input|data baru)/.test(text)) return 'Buka formulir untuk menambahkan data baru.';
        if (/(edit|ubah|perbarui|update)/.test(text)) return 'Ubah informasi pada data yang dipilih.';
        if (/(bayar|paid|lunas)/.test(text)) return 'Catat pembayaran dan perbarui status data ini.';
        if (/(setujui|approve)/.test(text)) return 'Setujui data yang sedang diperiksa.';
        if (/(tolak|reject)/.test(text)) return 'Tolak data yang sedang diperiksa.';
        if (/(cetak|print|pdf)/.test(text)) return 'Buka tampilan cetak atau simpan halaman sebagai PDF.';
        if (/(download|unduh|export)/.test(text)) return 'Unduh data sesuai halaman dan filter yang sedang digunakan.';
        if (/(upload|unggah|import|impor)/.test(text)) return 'Masukkan data dari berkas yang dipilih ke dalam sistem.';
        if (/(cari|search)/.test(text)) return 'Cari data menggunakan kata kunci yang dimasukkan.';
        if (/(filter|tampilkan|terapkan)/.test(text)) return 'Terapkan pilihan filter untuk memperbarui data yang ditampilkan.';
        if (/(reset|bersihkan)/.test(text)) return 'Kembalikan isian atau filter ke kondisi awal.';
        if (/(kembali|back)/.test(text)) return 'Kembali ke halaman sebelumnya.';
        if (/(detail|lihat|buka)/.test(text)) return 'Buka informasi lebih lengkap untuk data ini.';
        if (/(tutup|close|batal)/.test(text)) return 'Tutup bagian ini tanpa melanjutkan tindakan.';
        if (/(refresh|muat ulang|sinkron)/.test(text)) return 'Muat ulang data terbaru dari sistem.';
        if (/(generate|hitung ulang|proses)/.test(text)) return 'Jalankan proses perhitungan atau pembuatan data sesuai pilihan saat ini.';
        if (element.classList.contains('nav-link')) return `Buka menu ${label}.`;
        if (element.classList.contains('page-link')) return `Buka halaman ${label}.`;
        if (element.classList.contains('dropdown-item')) return `Pilih tindakan ${label}.`;
        return `Jalankan tindakan ${label}.`;
    }

    function fieldLabel(field) {
        if (field.id) {
            const escapedId = window.CSS && window.CSS.escape ? window.CSS.escape(field.id) : field.id.replace(/(["\\])/g, '\\$1');
            const explicit = document.querySelector(`label[for="${escapedId}"]`);
            if (explicit) return normalizedText(explicit.textContent);
        }
        const wrapper = field.closest('.mb-3, .form-group, [class*="col-"]');
        const nearby = wrapper ? wrapper.querySelector('label.form-label, label') : null;
        return normalizedText(nearby ? nearby.textContent : (field.getAttribute('aria-label') || field.placeholder || field.name));
    }

    function fieldHelp(field) {
        const existing = field.dataset.tooltip || field.dataset.bsTitle || field.getAttribute('title');
        if (existing) return normalizedText(existing);
        const label = fieldLabel(field);
        if (!label) return '';
        const type = (field.getAttribute('type') || '').toLowerCase();
        if (field.tagName === 'SELECT') return `Pilih ${label.toLowerCase()} yang sesuai.`;
        if (type === 'date' || type === 'month' || type === 'datetime-local') return `Pilih ${label.toLowerCase()} untuk menentukan periode atau tanggal data.`;
        if (type === 'file') return `Pilih berkas untuk ${label.toLowerCase()}.`;
        if (type === 'search' || /cari|search|keyword/i.test(`${field.name} ${field.placeholder}`)) return `Ketik kata kunci untuk mencari ${label.toLowerCase()}.`;
        if (type === 'checkbox' || type === 'radio') return `Aktifkan pilihan ${label.toLowerCase()} jika diperlukan.`;
        return `Isi ${label.toLowerCase()} sesuai data yang benar.`;
    }

    function statHelp(label) {
        const normalized = normalizedText(label).toLowerCase();
        if (STAT_HELP[normalized]) return STAT_HELP[normalized];
        const partial = Object.keys(STAT_HELP).find(key => normalized.includes(key));
        if (partial) return STAT_HELP[partial];
        return `Ringkasan ${normalized} berdasarkan data dan filter yang sedang ditampilkan pada halaman ini.`;
    }

    function prepareStatCard(card) {
        if (!card || card.dataset.nukariHelpReady === '1') return;
        card.dataset.nukariHelpReady = '1';
        const title = card.querySelector('.stat-title, .kpi-label, .metric-label, .metric-title, .stat-label, .card-title, h3, h4, h5, h6');
        if (!title || title.querySelector('.nukari-card-help')) return;
        const label = normalizedText(title.textContent);
        if (!label) return;
        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'nukari-card-help';
        button.setAttribute('aria-label', `Penjelasan ${label}`);
        button.innerHTML = '<i class="bi bi-info" aria-hidden="true"></i>';
        button.addEventListener('click', event => event.stopPropagation());
        title.appendChild(button);
        registerTooltip(button, statHelp(label), 'top');
    }

    function initHelpfulTooltips(scope) {
        if (IS_REPORT_PAGE) return;
        const root = scope || document;
        elementsIn(root, '.stat-card, .kpi-card, .metric-card, .asset-kpi, .summary-card').forEach(prepareStatCard);

        elementsIn(root, '.btn, button, [role="button"], .nav-link, .page-link, .dropdown-item, [data-bs-toggle="modal"]').forEach(element => {
            if (element.classList.contains('nukari-card-help') || element.classList.contains('nukari-no-tooltip')) return;
            if (element.closest('.tooltip')) return;
            const message = actionHelp(element);
            if (message) registerTooltip(element, message, element.closest('.sidebar') ? 'right' : 'top');
        });

        elementsIn(root, 'input:not([type="hidden"]), select, textarea').forEach(field => {
            if (field.classList.contains('nukari-no-tooltip') || field.closest('.nukari-no-tooltip')) return;
            const message = fieldHelp(field);
            if (message) registerTooltip(field, message, 'top');
        });

        elementsIn(root, '.badge, .status-badge').forEach(badge => {
            const status = normalizedText(badge.textContent).toLowerCase();
            const key = Object.keys(STATUS_HELP).find(item => status === item || status.startsWith(`${item} `));
            if (key) registerTooltip(badge, STATUS_HELP[key], 'top');
        });

        elementsIn(root, '[title]').forEach(element => {
            if (element.dataset.nukariTooltipReady === '1' || element.classList.contains('nukari-no-tooltip')) return;
            registerTooltip(element, normalizedText(element.getAttribute('title')), element.closest('.sidebar') ? 'right' : 'top');
        });
    }

    document.addEventListener('DOMContentLoaded', function () {
        initCurrencyInputs(document);
        initSortableTables(document);
        initHelpfulTooltips(document);

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
                        initHelpfulTooltips(node);
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
    window.NukariUI.initHelpfulTooltips = initHelpfulTooltips;
})();
