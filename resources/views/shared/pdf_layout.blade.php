{{-- Dompdf uses all-media CSS; browser print rules alone do not protect downloads. --}}
<style>
    @page { margin: 12mm; }
    body { overflow-wrap: anywhere; word-wrap: break-word; }
    table { width: 100%; table-layout: fixed; border-collapse: collapse; }
    th, td { white-space: normal; word-wrap: break-word; }
    thead { display: table-header-group; }
    tr { page-break-inside: avoid; }
    h1, h2, h3, h4 { page-break-after: avoid; }
    img { max-width: 100%; }
</style>
