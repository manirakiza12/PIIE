(function () {
    'use strict';

    var excluded = '.page-toolbar, .print-hidden, .no-print, .d-print-none, [data-print-exclude], ' +
        '.sidebar, .navbar, .home-header, .copyright-text, .breadcrumb, .eBreadcrumb-2, ' +
        '.pagination, .dataTables_filter, .dataTables_length, .dataTables_info, .dataTables_paginate, ' +
        '.library-filters, .filter-area, .filter-wrapper, .filter-section, .nav-tabs, ' +
        '.dropdown-menu, .dropdown-toggle, [role="button"], .workspace-back, .workspace-steps, ' +
        'button, .btn, .eBtn, a[class*="eBtn"], .print_invoice_btn, #printPageButton, ' +
        'input, select, textarea, script';

    function reportSource(source) {
        if (typeof source === 'string') source = document.getElementById(source);
        if (!source) return null;
        // A document-specific region takes precedence over the surrounding page.
        if (source.id === 'page-print-area' || source.classList.contains('main_content')) {
            return source.querySelector('[data-print-root], #transcript-doc, #printableDiv') || source;
        }
        return source;
    }

    function reportClone(source) {
        var clone = source.cloneNode(true);
        clone.querySelectorAll('form').forEach(function (form) {
            if (form.querySelector('input, select, textarea') &&
                !form.querySelector('table, [data-print-root], .invoice_content, .id-card')) form.remove();
        });
        var tables = Array.from(clone.querySelectorAll('table'));
        if (clone.matches('table')) tables.unshift(clone);
        tables.forEach(function (table) {
            var header = table.tHead && table.tHead.rows[0];
            if (!header) return;
            var columns = [];
            Array.from(header.cells).forEach(function (cell, index) {
                if (/^(actions?|options?|manage)$/i.test(cell.textContent.trim()) ||
                    cell.hasAttribute('data-print-exclude') ||
                    (!cell.textContent.trim() && cell.querySelector('input[type="checkbox"]'))) columns.push(index);
            });
            Array.from(table.rows).forEach(function (row) {
                var column = 0;
                Array.from(row.cells).forEach(function (cell) {
                    var span = cell.colSpan || 1;
                    var removed = columns.filter(function (index) { return index >= column && index < column + span; }).length;
                    column += span;
                    if (removed === span) cell.remove();
                    else if (removed) cell.colSpan = span - removed;
                });
            });
        });
        clone.querySelectorAll('label[for]').forEach(function (label) {
            var control = Array.from(clone.querySelectorAll('input, select, textarea')).some(function (field) { return field.id === label.htmlFor; });
            if (control) label.remove();
        });
        clone.querySelectorAll(excluded).forEach(function (node) { node.remove(); });
        clone.style.setProperty('width', '100%', 'important');
        clone.style.setProperty('min-width', '0', 'important');
        clone.style.setProperty('max-width', '100%', 'important');
        clone.style.setProperty('display', clone.matches('table') ? 'table' : 'block', 'important');
        clone.style.setProperty('position', 'static', 'important');
        return clone;
    }

    function clearPrintReport() {
        var output = document.getElementById('report-print-output');
        if (output) output.remove();
        document.body.classList.remove('report-printing');
    }

    function preparePrintReport(source, header) {
        source = reportSource(source);
        if (!source) return false;
        clearPrintReport();
        var output = document.createElement('div');
        output.id = 'report-print-output';
        output.className = 'pdf-export';
        if (header) output.appendChild(header);
        output.appendChild(reportClone(source));
        document.body.appendChild(output);
        document.body.classList.add('report-printing');
        return true;
    }

    window.printReport = function (source, header) {
        if (!preparePrintReport(source, header)) return;
        try { window.print(); } catch (error) { clearPrintReport(); throw error; }
    };

    window.addEventListener('beforeprint', function () {
        if (document.getElementById('report-print-output')) return;
        var source = document.getElementById('page-print-area') || document.querySelector('.main_content');
        if (source) preparePrintReport(source);
    });
    window.addEventListener('afterprint', clearPrintReport);

    // Rasterize at the printable page width, not the current desktop viewport.
    window.exportReportPdf = function (source, filename, header) {
        source = reportSource(source);
        if (!source) return Promise.resolve();
        if (typeof window.html2pdf !== 'function') {
            window.printReport(source, header);
            return Promise.resolve();
        }
        var clone = reportClone(source);
        var wide = Array.from(clone.querySelectorAll('tr')).some(function (row) {
            return row.cells.length > 6;
        });
        var wrapper = document.createElement('div');
        wrapper.className = 'pdf-export';
        wrapper.style.width = (wide ? 273 : 186) + 'mm';
        if (header) wrapper.appendChild(header);
        wrapper.appendChild(clone);
        return window.html2pdf().set({
            margin: 12,
            filename: filename || 'report.pdf',
            image: { type: 'jpeg', quality: 0.98 },
            html2canvas: { scale: 1.5, scrollX: 0, scrollY: 0, windowWidth: wide ? 1123 : 794 },
            jsPDF: { unit: 'mm', format: 'a4', orientation: wide ? 'landscape' : 'portrait' },
            pagebreak: { mode: ['css', 'legacy'], avoid: ['tr', 'h1', 'h2', 'h3', 'h4'] }
        }).from(wrapper).save();
    };
})();
