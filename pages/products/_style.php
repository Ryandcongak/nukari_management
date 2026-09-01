<style>
/* Products module professional UI - aligned with Nukari Asset module */
.product-page{max-width:100%;}
.product-hero{background:linear-gradient(135deg,#07553f 0%,#118044 58%,#e0bd42 100%);border-radius:28px;padding:30px;color:#fff;position:relative;overflow:hidden;margin-bottom:22px;box-shadow:0 16px 40px rgba(15,23,42,.10)}
.product-hero:after{content:"";position:absolute;right:-72px;top:-105px;width:285px;height:285px;border-radius:50%;background:rgba(255,255,255,.18)}
.product-hero>*{position:relative;z-index:1}.product-hero h1,.product-hero h2{font-weight:900;letter-spacing:-.04em}.product-hero p{font-weight:500;opacity:.95}
.product-card{background:#fff;border:1px solid rgba(226,232,240,.95);border-radius:22px;box-shadow:0 14px 38px rgba(15,23,42,.07);overflow:hidden}.product-card .card-header{background:#fff;border-bottom:1px solid #eef2f7;padding:18px 20px}.product-card .card-body{padding:20px}.product-card.soft{background:linear-gradient(180deg,#fff,#fbfdff)}
.kpi-card{background:#fff;border-radius:22px;padding:20px;border:1px solid rgba(226,232,240,.95);box-shadow:0 14px 38px rgba(15,23,42,.07);height:100%;position:relative;overflow:hidden}.kpi-card:after{content:"";position:absolute;right:-20px;top:-20px;width:78px;height:78px;border-radius:24px;background:#ecfdf3;opacity:.9}.kpi-card h3,.kpi-card h4{font-weight:900;letter-spacing:-.03em;color:#0f172a;margin:4px 0}.kpi-card small{color:#64748b}.kpi-icon{width:46px;height:46px;border-radius:16px;display:flex;align-items:center;justify-content:center;background:#ecfdf3;color:#198754;font-size:20px;position:relative;z-index:1}.small-muted{font-size:.82rem;color:#64748b}.money{font-weight:800;color:#07553f}.section-title{font-weight:900;color:#0f172a;letter-spacing:-.02em}.btn-rounded{border-radius:14px}.page-actions{gap:8px;flex-wrap:wrap}.alert-soft{background:#f8fafc;border:1px solid #e2e8f0;border-radius:18px}.low-stock{background:#fff7ed!important}
.product-toolbar{background:#fff;border:1px solid #e2e8f0;border-radius:22px;padding:14px;box-shadow:0 12px 30px rgba(15,23,42,.05)}
.product-quick-card{display:flex;align-items:center;gap:14px;padding:16px;border:1px solid #e2e8f0;border-radius:20px;background:#fff;text-decoration:none;color:#0f172a;height:100%;transition:.18s ease}.product-quick-card:hover{transform:translateY(-2px);box-shadow:0 14px 32px rgba(15,23,42,.08);border-color:#bbf7d0}.product-quick-card .icon{width:42px;height:42px;border-radius:15px;background:#ecfdf3;color:#198754;display:flex;align-items:center;justify-content:center}.product-quick-card strong{display:block}.product-quick-card span{font-size:.82rem;color:#64748b}
.nav-pills .nav-link{border-radius:14px;color:#334155;font-weight:700;padding:10px 13px}.nav-pills .nav-link.active{background:#16824f;color:#fff}.nav-pills{overflow-x:auto;flex-wrap:nowrap;padding-bottom:2px}.nav-pills .nav-link{white-space:nowrap}
.table{vertical-align:middle}.table thead th{font-size:.76rem;text-transform:uppercase;letter-spacing:.055em;color:#64748b;background:#f8fafc;border-bottom:1px solid #e5e7eb}.table tbody td{border-color:#edf2f7}.table-hover tbody tr:hover{background:#f8fafc}.form-label{font-weight:700;color:#334155;font-size:.9rem}.form-control,.form-select{border-radius:14px;border-color:#dbe3ef;padding:.68rem .85rem}.form-control:focus,.form-select:focus{border-color:#198754;box-shadow:0 0 0 .2rem rgba(25,135,84,.12)}
.badge{border-radius:999px;padding:.42em .7em}.empty-state{padding:32px 20px;text-align:center;color:#64748b}.empty-state i{font-size:36px;display:block;margin-bottom:10px;color:#94a3b8}.metric-note{font-size:.8rem;color:#64748b}.product-grid-gap{row-gap:18px}.sticky-side{position:sticky;top:100px}
@media(max-width:991px){.product-hero{border-radius:22px;padding:24px}.kpi-card,.product-card{border-radius:18px}.page-actions .btn{width:100%}.sticky-side{position:static}}
@media print{.sidebar,.nukari-topbar,.topbar,.page-actions,.btn,.no-print,.product-nav-wrap,.nukari-floating-actions{display:none!important}.main-content{margin-left:0!important;width:100%!important;padding:0!important}.product-card,.kpi-card{box-shadow:none!important;break-inside:avoid}.product-hero{background:#fff!important;color:#000!important;border:1px solid #ddd;box-shadow:none!important}.table{font-size:11px}@page{size:A4 landscape;margin:10mm}}
</style>

<script>
(function(){
    function normalizeDigits(value){
        return (value || '').toString().replace(/[^0-9,.-]/g,'').trim();
    }
    function parseIdNumber(value){
        value = normalizeDigits(value);
        if (!value) return 0;
        // Indonesia typing: 1.500.000 or 1.500.000,50
        if (value.indexOf(',') >= 0) {
            value = value.replace(/\./g,'').replace(',', '.');
            return parseFloat(value) || 0;
        }
        // PHP/DB decimal display: 1500.00 should stay 1500, not 150000
        if (/^\d+\.\d{1,2}$/.test(value) && !/^\d{1,3}(\.\d{3})+$/.test(value)) {
            return parseFloat(value) || 0;
        }
        // Indonesian thousands: 1.500 or 1.500.000
        if (/^\d{1,3}(\.\d{3})+$/.test(value)) {
            return parseFloat(value.replace(/\./g,'')) || 0;
        }
        // fallback: remove thousands dots
        return parseFloat(value.replace(/\./g,'')) || 0;
    }
    function formatIdMoney(value){
        var n = parseIdNumber(value);
        return n ? Math.round(n).toLocaleString('id-ID') : '0';
    }
    function parseQty(value){
        value = normalizeDigits(value);
        if (!value) return 0;
        if (value.indexOf(',') >= 0) return parseFloat(value.replace(/\./g,'').replace(',', '.')) || 0;
        if (/^\d+\.\d{1,3}$/.test(value) && !/^\d{1,3}(\.\d{3})+$/.test(value)) return parseFloat(value) || 0;
        if (/^\d{1,3}(\.\d{3})+$/.test(value)) return parseFloat(value.replace(/\./g,'')) || 0;
        return parseFloat(value.replace(/\./g,'')) || 0;
    }
    function formatQtyNumber(value){
        var n = (typeof value === 'number') ? value : parseQty(value);
        if (!n) return '0';
        return n.toLocaleString('id-ID', {minimumFractionDigits:0, maximumFractionDigits:3});
    }
    window.productParseId = parseIdNumber;
    window.productParseQty = parseQty;
    window.productFormatId = formatIdMoney;
    window.productFormatQty = formatQtyNumber;
    function bindFormattedInputs(scope){
        (scope || document).querySelectorAll('.money-input').forEach(function(el){
            if (el.dataset.boundMoney === '1') return;
            el.dataset.boundMoney = '1';
            el.setAttribute('inputmode','numeric');
            el.setAttribute('autocomplete','off');
            el.addEventListener('input', function(){
                var start = this.selectionStart;
                var oldLen = this.value.length;
                this.value = formatIdMoney(this.value);
                var diff = this.value.length - oldLen;
                try { this.setSelectionRange(Math.max(0, start + diff), Math.max(0, start + diff)); } catch(e) {}
                this.dispatchEvent(new CustomEvent('money-formatted', {bubbles:true}));
            });
            el.addEventListener('blur', function(){ this.value = formatIdMoney(this.value); });
            if (el.value !== '') el.value = formatIdMoney(el.value);
        });
        (scope || document).querySelectorAll('.qty-input').forEach(function(el){
            if (el.dataset.boundQty === '1') return;
            el.dataset.boundQty = '1';
            el.setAttribute('inputmode','decimal');
            el.setAttribute('autocomplete','off');
            el.addEventListener('blur', function(){ this.value = formatQtyNumber(this.value); });
            if (el.value !== '') el.value = formatQtyNumber(el.value);
        });
    }
    window.productBindMoneyInputs = bindFormattedInputs;
    document.addEventListener('DOMContentLoaded', function(){ bindFormattedInputs(document); });
})();
</script>
