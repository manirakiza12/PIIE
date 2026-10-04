@php
    $printTarget = $printTarget ?? 'page-print-area';
    $institutionName = trim((string) (get_settings('navbar_title') ?: get_settings('system_title') ?: 'PIIE'));
@endphp

<div class="page-toolbar print-hidden d-flex flex-wrap justify-content-end gap-2 mb-3">
    <button type="button" class="btn btn-outline-secondary btn-sm" title="{{ get_phrase('Print this page') }}" onclick="printCurrentPage('{{ $printTarget }}')">
        <i class="bi bi-printer" aria-hidden="true"></i> {{ get_phrase('Print') }}
    </button>
    <button type="button" class="btn btn-outline-danger btn-sm" title="{{ get_phrase('Download as PDF') }}" onclick="exportCurrentPagePdf('{{ $printTarget }}')">
        <i class="bi bi-file-earmark-pdf" aria-hidden="true"></i> {{ get_phrase('PDF') }}
    </button>
    <button type="button" class="btn btn-outline-success btn-sm page-toolbar-excel" title="{{ get_phrase('Download as Excel') }}" onclick="exportCurrentPageExcel('{{ $printTarget }}')">
        <i class="bi bi-file-earmark-excel" aria-hidden="true"></i> {{ get_phrase('Excel') }}
    </button>
</div>

@once
    <style>
        .print-header {
            display: none;
        }

        @media print {

            .print-hidden,
            .page-toolbar,
            .sidebar,
            .home-header,
            .copyright-text,
            .navbar,
            .modal,
            .modal-backdrop {
                display: none !important;
            }

            .home-section,
            #app,
            .main_content {
                left: 0 !important;
                width: 100% !important;
                margin-left: 0 !important;
                padding: 0 !important;
                overflow: visible !important;
            }

            body {
                background: #fff !important;
            }

            .print-header {
                display: block !important;
                margin-bottom: 12px;
                border-bottom: 2px solid #333;
                padding-bottom: 8px;
            }

            .print-header .print-header-institution {
                font-size: 16px;
                font-weight: 700;
                margin: 0;
            }

            .print-header .print-header-meta {
                display: flex;
                justify-content: space-between;
                font-size: 12px;
                color: #333;
                margin-top: 4px;
            }

            table {
                width: 100% !important;
            }

            thead {
                display: table-header-group;
            }

            tfoot {
                display: table-footer-group;
            }

            tr,
            .card {
                break-inside: avoid;
                page-break-inside: avoid;
            }

            @page {
                size: A4;
                margin: 12mm;
            }
        }
    </style>

    <script>
        (function () {
            var institutionName = @json($institutionName);

            function exportFileBaseName() {
                var title = (document.title || 'export').toLowerCase();
                return title.replace(/[^a-z0-9]+/gi, '-').replace(/^-+|-+$/g, '') || 'export';
            }

            function getPrintableRoot(targetId) {
                return document.getElementById(targetId || 'page-print-area');
            }

            function removeToolbarNodes(root) {
                if (!root) {
                    return;
                }

                root.querySelectorAll('.page-toolbar, .print-hidden, .no-print').forEach(function (node) {
                    node.remove();
                });
            }

            function guessPageTitle(root) {
                var heading = root && root.querySelector('h1, h2, h3, h4, .card-title, .page-title');
                if (heading && heading.textContent.trim()) {
                    return heading.textContent.trim();
                }
                return (document.title || '').split('|')[0].trim() || 'Report';
            }

            function buildPrintHeader(root) {
                var header = document.createElement('div');
                header.className = 'print-header';

                var now = new Date();
                var generatedAt = now.toLocaleDateString() + ' ' + now.toLocaleTimeString();

                var institution = document.createElement('p');
                institution.className = 'print-header-institution';
                institution.textContent = institutionName;
                header.appendChild(institution);

                var meta = document.createElement('div');
                meta.className = 'print-header-meta';

                var titleEl = document.createElement('span');
                titleEl.textContent = guessPageTitle(root);
                meta.appendChild(titleEl);

                var dateEl = document.createElement('span');
                dateEl.textContent = "{{ get_phrase('Generated') }}: " + generatedAt;
                meta.appendChild(dateEl);

                header.appendChild(meta);

                return header;
            }

            window.printCurrentPage = function (targetId) {
                var source = getPrintableRoot(targetId);

                if (!source) {
                    window.print();
                    return;
                }

                var header = buildPrintHeader(source);
                source.insertBefore(header, source.firstChild);

                var cleanup = function () {
                    header.remove();
                    window.removeEventListener('afterprint', cleanup);
                };
                window.addEventListener('afterprint', cleanup);

                window.print();
            };

            window.exportCurrentPagePdf = function (targetId) {
                var source = getPrintableRoot(targetId);

                if (!source) {
                    window.print();
                    return;
                }

                if (typeof window.html2pdf === 'undefined') {
                    window.print();
                    return;
                }

                var cloned = source.cloneNode(true);
                removeToolbarNodes(cloned);

                var wrapper = document.createElement('div');
                wrapper.appendChild(buildPrintHeader(source));
                wrapper.appendChild(cloned);

                html2pdf().set({
                    margin: 0.5,
                    filename: exportFileBaseName() + '.pdf',
                    image: { type: 'jpeg', quality: 0.98 },
                    html2canvas: { scale: 2 },
                    jsPDF: { unit: 'in', format: 'a4', orientation: 'portrait' },
                    pagebreak: { mode: ['css', 'legacy'] }
                }).from(wrapper).save();
            };

            function cellText(cell) {
                return (cell.innerText || cell.textContent || '').replace(/"/g, '""').trim();
            }

            function tableToSpreadsheetXml(table, title) {
                var rows = table.querySelectorAll('tr');
                var xmlRows = [];

                rows.forEach(function (row) {
                    var cells = row.querySelectorAll('th, td');
                    var xmlCells = [];

                    cells.forEach(function (cell) {
                        var text = cellText(cell)
                            .replace(/&/g, '&amp;')
                            .replace(/</g, '&lt;')
                            .replace(/>/g, '&gt;');
                        var isNumeric = text !== '' && !isNaN(text.replace(/,/g, ''));
                        var dataType = isNumeric ? 'Number' : 'String';
                        var value = isNumeric ? text.replace(/,/g, '') : text;

                        xmlCells.push('<Cell><Data ss:Type="' + dataType + '">' + value + '</Data></Cell>');
                    });

                    xmlRows.push('<Row>' + xmlCells.join('') + '</Row>');
                });

                // Each Excel XML processing instruction below is emitted as two adjacent
                // string literals, the first holding only the opening angle
                // bracket. A short open tag immediately followed by "xml" or
                // "mso-application" is a ParseError when short_open_tag=On,
                // which would 500 this page. The emitted XML is byte-identical.
                return '<' + '?xml version="1.0"?>' +
                    '<' + '?mso-application progid="Excel.Sheet"?>' +
                    '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" ' +
                    'xmlns:o="urn:schemas-microsoft-com:office:office" ' +
                    'xmlns:x="urn:schemas-microsoft-com:office:excel" ' +
                    'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">' +
                    '<Worksheet ss:Name="' + title.substring(0, 31) + '">' +
                    '<Table>' + xmlRows.join('') + '</Table>' +
                    '</Worksheet>' +
                    '</Workbook>';
            }

            window.exportCurrentPageExcel = function (targetId) {
                var source = getPrintableRoot(targetId);

                if (!source) {
                    return;
                }

                var table = source.querySelector('table');
                if (!table) {
                    alert("{{ get_phrase('No table available to export.') }}");
                    return;
                }

                var xml = tableToSpreadsheetXml(table, guessPageTitle(source));
                var blob = new Blob([xml], { type: 'application/vnd.ms-excel' });
                var url = window.URL.createObjectURL(blob);
                var link = document.createElement('a');

                link.href = url;
                link.download = exportFileBaseName() + '.xls';
                document.body.appendChild(link);
                link.click();
                link.remove();
                window.URL.revokeObjectURL(url);
            };

            document.addEventListener('DOMContentLoaded', function () {
                var excelButton = document.querySelector('.page-toolbar-excel');
                var pageArea = document.getElementById('page-print-area');

                if (excelButton && (!pageArea || !pageArea.querySelector('table'))) {
                    excelButton.style.display = 'none';
                }
            });
        })();
    </script>
@endonce