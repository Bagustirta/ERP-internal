/**
 * Format angka & mata uang Rupiah standar Indonesia (pemisah ribuan titik, desimal koma)
 */
export function formatRp(value, showDecimal = false) {
    const num = Number(value || 0);
    return new Intl.NumberFormat('id-ID', {
        style: 'currency',
        currency: 'IDR',
        minimumFractionDigits: showDecimal ? 2 : 0,
        maximumFractionDigits: showDecimal ? 2 : 0,
    }).format(num);
}

export function formatNum(value, decimals = 0) {
    const num = Number(value || 0);
    return new Intl.NumberFormat('id-ID', {
        minimumFractionDigits: decimals,
        maximumFractionDigits: decimals,
    }).format(num);
}

export function formatDate(dateStr) {
    if (!dateStr) return '-';
    try {
        const d = new Date(dateStr);
        return new Intl.DateTimeFormat('id-ID', {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
        }).format(d);
    } catch {
        return dateStr;
    }
}

/**
 * Konversi angka menjadi kalimat Terbilang Bahasa Indonesia resmi untuk Faktur & Slip Gaji
 */
export function terbilang(nilai) {
    const angka = Math.floor(Math.abs(Number(nilai || 0)));
    if (angka === 0) return 'Nol rupiah';

    const satuan = [
        '',
        'satu',
        'dua',
        'tiga',
        'empat',
        'lima',
        'enam',
        'tujuh',
        'delapan',
        'sembilan',
        'sepuluh',
        'sebelas',
    ];

    function eja(n) {
        if (n < 12) return satuan[n];
        if (n < 20) return eja(n - 10) + ' belas';
        if (n < 100) return (eja(Math.floor(n / 10)) + ' puluh ' + eja(n % 10)).trim();
        if (n < 200) return ('seratus ' + eja(n - 100)).trim();
        if (n < 1000) return (eja(Math.floor(n / 100)) + ' ratus ' + eja(n % 100)).trim();
        if (n < 2000) return ('seribu ' + eja(n - 1000)).trim();
        if (n < 1000000) return (eja(Math.floor(n / 1000)) + ' ribu ' + eja(n % 1000)).trim();
        if (n < 1000000000) return (eja(Math.floor(n / 1000000)) + ' juta ' + eja(n % 1000000)).trim();
        if (n < 1000000000000) return (eja(Math.floor(n / 1000000000)) + ' miliar ' + eja(n % 1000000000)).trim();
        return (eja(Math.floor(n / 1000000000000)) + ' triliun ' + eja(n % 1000000000000)).trim();
    }

    const hasil = eja(angka).replace(/\s+/g, ' ').trim();
    return hasil.charAt(0).toUpperCase() + hasil.slice(1) + ' rupiah';
}

/**
 * Parser Paste dari Excel / Google Sheets (Tab-separated \t atau Semicolon ;)
 */
export function parseExcelPaste(rawText) {
    if (!rawText || !rawText.trim()) return [];
    return rawText
        .split(/\r?\n/)
        .map((line) => line.trim())
        .filter((line) => line.length > 0)
        .map((line) => {
            const delimiter = line.includes('\t') ? '\t' : ';';
            return line.split(delimiter).map((cell) => cell.trim());
        });
}

/**
 * Normalisasi nilai sel untuk ekspor CSV agar rapi saat dibuka di Microsoft Excel:
 * 1. Mengubah tanggal ISO ("2026-11-16T00:00:00.000000Z" atau "2026-11-16") menjadi format teks Indonesia ("16 Nov 2026").
 * 2. Untuk kolom mata uang (Total, Saldo, Harga, HPP, Piutang, Hutang, Bruto, BPJS, PPh, THP, Debit, Kredit, Nilai),
 *    memformat angka menjadi format mata uang Rupiah ("Rp 42.180.000") sebagai teks agar di Excel tampil sebagai
 *    currency Rupiah dan tidak pernah berubah menjadi `########` atau `3,17E+08`.
 */
function normalizeCsvValue(val, headerLabel = '') {
    if (val === null || val === undefined) return '';
    const str = String(val).trim();
    if (!str) return '';

    // 1. Deteksi string tanggal ISO (YYYY-MM-DD atau YYYY-MM-DDTHH:mm:ss...)
    if (/^\d{4}-\d{2}-\d{2}(?:[T\s]\d{2}:\d{2}:\d{2}(?:\.\d+)?Z?)?$/.test(str)) {
        const formatted = formatDate(str);
        return ` ${formatted}`;
    }

    // 2. Deteksi angka murni atau string decimal dari database (mis. "42180000", "317460000.00")
    if (typeof val === 'number' || /^-?\d+(?:\.\d+)?$/.test(str)) {
        const num = Number(str);
        if (!Number.isNaN(num)) {
            const isQtyColumn = /\b(qty|jml|jumlah\s+karyawan|stok|minimum)\b/i.test(headerLabel);
            const isCurrencyColumn =
                !isQtyColumn &&
                (/\b(total|saldo|mutasi|debit|kredit|harga|hpp|piutang|hutang|bruto|bpjs|pph|thp|nilai|nominal|tagihan)\b/i.test(
                    headerLabel
                ) ||
                    Math.abs(num) >= 10000);

            if (isCurrencyColumn) {
                // Format sebagai Currency Rupiah ("Rp 42.180.000") dengan spasi normal
                const rpText = formatRp(num).replace(/\u00A0/g, ' ');
                return rpText;
            }

            return new Intl.NumberFormat('id-ID', {
                minimumFractionDigits: 0,
                maximumFractionDigits: 2,
            }).format(num);
        }
    }

    return str;
}

export function exportToCsv(filename, headers, rows) {
    const escapeCell = (v, colIdx) => {
        const headerLabel = headers?.[colIdx] || '';
        const str = normalizeCsvValue(v, headerLabel);
        return `"${str.replace(/"/g, '""')}"`;
    };
    const csvContent = [
        headers.map((h) => `"${String(h).replace(/"/g, '""')}"`).join(';'),
        ...rows.map((r) => r.map((cell, idx) => escapeCell(cell, idx)).join(';')),
    ].join('\r\n');

    const blob = new Blob(['\uFEFF' + csvContent], { type: 'text/csv;charset=utf-8;' });
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.setAttribute('download', `${filename}_${new Date().toISOString().slice(0, 10)}.csv`);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
