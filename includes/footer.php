<?php
/*
|--------------------------------------------------------------------------
| FOOTER - Nukari Management System
|--------------------------------------------------------------------------
| Isi file:
| 1. Penutup app-wrapper
| 2. Floating button WhatsApp dan Chat AI placeholder
| 3. Script Bootstrap
| 4. Script currency formatter
| 5. Script sidebar hide/show dengan localStorage
|
| Catatan:
| - Nomor WhatsApp di bawah masih placeholder.
| - Chat AI belum dihubungkan ke API, tetapi tombol dan modalnya sudah disiapkan.
*/
$companyWhatsapp = preg_replace('/\D+/', '', getenv('NUKARI_WHATSAPP') ?: '');
$aiEnabled = getenv('NUKARI_AI_ENABLED') === '1';
?>

</div><!-- /.app-wrapper -->

<!-- ===============================
     FLOATING ACTIONS: WHATSAPP & CHAT AI
================================= -->
<?php if ($companyWhatsapp || $aiEnabled): ?><div class="nukari-floating-actions">
    <!-- Ganti nomor WhatsApp di bawah dengan nomor perusahaan -->
    <?php if ($companyWhatsapp): ?><a
        href="https://wa.me/<?= htmlspecialchars($companyWhatsapp); ?>?text=Halo%20Admin%20Nukari"
        target="_blank"
        class="floating-action-btn floating-whatsapp"
        title="WhatsApp Perusahaan">
        <i class="bi bi-whatsapp"></i>
    </a><?php endif; ?>

    <?php if ($aiEnabled): ?><button
        type="button"
        class="floating-action-btn floating-ai"
        title="Chat AI Nukari"
        data-bs-toggle="modal"
        data-bs-target="#nukariAiModal">
        <i class="bi bi-robot"></i>
    </button><?php endif; ?>
</div><?php endif; ?>

<!-- ===============================
     MODAL CHAT AI PLACEHOLDER
================================= -->
<?php if ($aiEnabled): ?><div class="modal fade" id="nukariAiModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-end modal-dialog-scrollable">
        <div class="modal-content rounded-4 border-0">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title fw-bold mb-0">Chat AI Nukari</h5>
                    <small class="text-muted">Placeholder untuk integrasi AI internal.</small>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>

            <div class="modal-body">
                <div class="alert alert-success rounded-4 mb-3">
                    Fitur ini disiapkan untuk integrasi Chat AI perusahaan, misalnya panduan SOP, data project, aset, HR, atau bantuan admin.
                </div>

                <div class="border rounded-4 p-3 bg-light mb-3" style="min-height: 180px;">
                    <div class="small text-muted mb-2">Contoh penggunaan nanti:</div>
                    <ul class="small text-muted mb-0">
                        <li>“Tampilkan project yang termin-nya jatuh tempo minggu ini.”</li>
                        <li>“Aset apa yang perlu service bulan ini?”</li>
                        <li>“Siapa karyawan yang ulang tahun minggu ini?”</li>
                        <li>“Berikan ringkasan cashflow project.”</li>
                    </ul>
                </div>

                <textarea class="form-control" rows="3" placeholder="Chat AI belum diaktifkan..."></textarea>
            </div>

            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Tutup</button>
                <button type="button" class="btn btn-nukari" disabled>Kirim</button>
            </div>
        </div>
    </div>
</div><?php endif; ?>

<!-- Bootstrap JS -->
<script src="<?= base_url('assets/vendor/bootstrap/js/bootstrap.bundle.min.js'); ?>"></script>
<!-- Nukari UI Global Components -->
<script src="<?= base_url('assets/js/nukari-ui.js'); ?>?v=1.0"></script>


<script>
    // Semua form mutasi lama ikut memperoleh CSRF token secara terpusat.
    (function() {
        const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
        if (!token) return;
        document.querySelectorAll('form').forEach(function(form) {
            const method = (form.getAttribute('method') || 'GET').toUpperCase();
            if (method !== 'POST' || form.querySelector('input[name="csrf_token"]')) return;
            const input = document.createElement('input');
            input.type = 'hidden'; input.name = 'csrf_token'; input.value = token;
            form.prepend(input);
        });
        const originalFetch = window.fetch;
        window.fetch = function(resource, options) {
            options = options || {};
            if ((options.method || 'GET').toUpperCase() === 'POST') {
                options.headers = new Headers(options.headers || {});
                options.headers.set('X-CSRF-Token', token);
            }
            return originalFetch(resource, options);
        };

        // Tautan hapus lama dikirim sebagai POST agar crawler/preview tidak dapat menghapus data.
        document.addEventListener('click', function(event) {
            const link = event.target.closest('a');
            if (!link) return;
            const href = link.getAttribute('href') || '';
            const destructive = /(?:delete[^/]*\.php|transaction_delete\.php|[?&](?:delete|mode=delete)=?)/i.test(href);
            if (!destructive) return;
            event.preventDefault();
            event.stopImmediatePropagation();
            if (!window.confirm('Tindakan ini mengubah atau menghapus data. Lanjutkan?')) return;
            const form = document.createElement('form');
            form.method = 'POST'; form.action = link.href; form.style.display = 'none';
            const csrf = document.createElement('input');
            csrf.type = 'hidden'; csrf.name = 'csrf_token'; csrf.value = token;
            form.appendChild(csrf); document.body.appendChild(form); form.submit();
        }, true);
    })();

    /*
    |--------------------------------------------------------------------------
    | SIDEBAR TOGGLE
    |--------------------------------------------------------------------------
    | Desktop:
    | - Menambah class sidebar-collapsed ke body.
    |
    | Mobile:
    | - Menambah class sidebar-mobile-open ke body.
    |
    | State desktop disimpan di localStorage.
    */
    document.addEventListener('DOMContentLoaded', function() {
        const sidebarToggle = document.getElementById('sidebarToggle');
        const isMobile = () => window.innerWidth <= 992;

        if (localStorage.getItem('nukari_sidebar_collapsed') === 'yes' && !isMobile()) {
            document.body.classList.add('sidebar-collapsed');
        }

        if (sidebarToggle) {
            sidebarToggle.addEventListener('click', function() {
                if (isMobile()) {
                    document.body.classList.toggle('sidebar-mobile-open');
                    return;
                }

                document.body.classList.toggle('sidebar-collapsed');

                if (document.body.classList.contains('sidebar-collapsed')) {
                    localStorage.setItem('nukari_sidebar_collapsed', 'yes');
                } else {
                    localStorage.setItem('nukari_sidebar_collapsed', 'no');
                }
            });
        }

        document.addEventListener('click', function(e) {
            if (!isMobile()) return;

            const sidebar = document.getElementById('appSidebar');
            const toggle = document.getElementById('sidebarToggle');

            if (!sidebar || !toggle) return;

            const clickedInsideSidebar = sidebar.contains(e.target);
            const clickedToggle = toggle.contains(e.target);

            if (!clickedInsideSidebar && !clickedToggle) {
                document.body.classList.remove('sidebar-mobile-open');
            }
        });
    });

    /*
    |--------------------------------------------------------------------------
    | CURRENCY FORMATTER GLOBAL
    |--------------------------------------------------------------------------
    | Untuk input dengan class .currency-input.
    | Jika input punya data-target, nilai angka mentah dimasukkan ke hidden input.
    */
    function getRawNumber(value) {
        return String(value).replace(/[^0-9]/g, '');
    }

    function formatCurrencyInput(input) {
        let rawValue = getRawNumber(input.value);

        input.value = rawValue === '' ?
            '' :
            'Rp ' + new Intl.NumberFormat('id-ID').format(rawValue);

        const targetName = input.dataset.target;

        if (targetName) {
            const hiddenInput = document.querySelector(`input[name="${targetName}"]`);
            if (hiddenInput) {
                hiddenInput.value = rawValue;
            }
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        document.querySelectorAll('.currency-input').forEach(function(input) {
            input.addEventListener('input', function() {
                formatCurrencyInput(this);

                if (typeof window.calculatePercentageFromAmount === 'function') {
                    window.calculatePercentageFromAmount();
                }
            });

            if (input.value !== '') {
                formatCurrencyInput(input);
            }

            const form = input.closest('form');
            if (form) {
                form.addEventListener('submit', function() {
                    const targetName = input.dataset.target;

                    if (!targetName && input.name) {
                        input.value = getRawNumber(input.value);
                    }
                });
            }
        });
    });
</script>

</body>

</html>
