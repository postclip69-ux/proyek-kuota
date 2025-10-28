<?php
// --- PENGATURAN & Fungsi panggilApi (tidak berubah) ---
$apiKey = "6A225EC0-2922-4252-8204-C7C00A3DA0E5";
$baseUrl = "https://panel.khfy-store.com/api_v2";
$baseUrl_v3 = "https://panel.khfy-store.com/api_v3";

function panggilApi($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);
    if ($error) return ['error' => "cURL Error: " . $error];
    $decoded = json_decode($response, true);
    // Tambahkan pengecekan jika json_decode gagal
    if (json_last_error() !== JSON_ERROR_NONE && !empty($response)) {
        return ['error' => 'Gagal decode JSON: ' . json_last_error_msg(), 'raw_response' => $response];
    }
    return $decoded;
}

// Inisialisasi variabel
$pesanStok = "";
$pesanTransaksi = "";
$pesanHistory = ""; // Pesan history tidak akan ditampilkan untuk multi-transaksi

// --- Cek Stok Akrab (tidak berubah) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'cek_stok') {
    $stokUrl = "$baseUrl_v3/cek_stock_akrab?api_key=$apiKey";
    $hasilStok = panggilApi($stokUrl);
    if (isset($hasilStok['error'])) { $pesanStok = "Gagal: " . $hasilStok['error']; }
    elseif (isset($hasilStok['message'])) { $pesanStok = $hasilStok['message']; }
    elseif (isset($hasilStok['data']['stock'])) { $pesanStok = "Stok Akrab Saat Ini: " . $hasilStok['data']['stock']; }
    else { $pesanStok = "Info Stok: <pre>" . htmlspecialchars(json_encode($hasilStok, JSON_PRETTY_PRINT)) . "</pre>"; }
}

// --- Ambil List Produk & Kelompokkan (tidak berubah) ---
$listProdukUrl = "$baseUrl/list_product?api_key=$apiKey";
$dataProduk = panggilApi($listProdukUrl);
$produkGrouped = [];
$deskripsiMap = [];
$akrabBulananSubs = ['Supermini', 'Mini', 'Big', 'Jumbo v2', 'Jumbo', 'Megabig'];
$bonusAkrabKeywords = ['Bonus Akrab L', 'Bonus Akrab XL', 'Bonus Akrab XXL'];

if (isset($dataProduk['error'])) {
     echo "Gagal menghubungi API List Produk: " . $dataProduk['error'];
} elseif (isset($dataProduk['data']) && is_array($dataProduk['data'])) {
    foreach ($dataProduk['data'] as $produk) {
        $nama = $produk['nama_produk'] ?? 'Produk'; $kode = $produk['kode_produk'] ?? '';
        $desc = $produk['deskripsi'] ?? ''; $harga = $produk['harga_final'] ?? 0;
        $isGangguan = $produk['gangguan'] ?? 0; $isKosong = $produk['kosong'] ?? 0;
        $skipProduk = false;
        foreach ($bonusAkrabKeywords as $keyword) { if (stripos($nama, $keyword) === 0) { $skipProduk = true; break; } }
        if ($skipProduk) continue;
        $kategoriUtama = null; $foundAkrabSub = false;
        foreach ($akrabBulananSubs as $sub) { if (stripos($nama, $sub) !== false) { $kategoriUtama = "Akrab Bulanan"; $foundAkrabSub = true; break; } }
        if (!$foundAkrabSub && stripos($nama, 'FlexMax') === 0) { $kategoriUtama = "FlexMax"; }
        if ($kategoriUtama === null) continue;
        if (!isset($produkGrouped[$kategoriUtama])) { $produkGrouped[$kategoriUtama] = []; }
        $produkGrouped[$kategoriUtama][] = $produk;
        $deskripsiMap[$kode] = ['deskripsi' => $desc, 'harga' => $harga, 'harga_formatted' => "Rp " . number_format($harga)];
    }
    ksort($produkGrouped);
    foreach ($produkGrouped as &$produksDalamGrup) { usort($produksDalamGrup, function($a, $b) { return strcmp($a['nama_produk'] ?? '', $b['nama_produk'] ?? ''); }); } unset($produksDalamGrup);
} else { echo "Gagal mengambil daftar produk. Response: <pre>" . htmlspecialchars(print_r($dataProduk, true)) . "</pre>"; }

// --- 2. PROSES TRANSAKSI (MODIFIKASI UNTUK MULTI) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'beli_produk' && isset($_POST['kode_produk'], $_POST['tujuan'])) {
    
    $kodeProduk = trim($_POST['kode_produk']);
    $tujuanInput = trim($_POST['tujuan']); // Ambil dari input/textarea yang aktif (name="tujuan")
    $isMulti = isset($_POST['multi_transaksi']) && $_POST['multi_transaksi'] == 'yes';

    if (empty($kodeProduk)) {
         $pesanTransaksi = "❌ Transaksi Gagal: Anda belum memilih produk spesifik.";
    } elseif (empty($tujuanInput)) {
         $pesanTransaksi = "❌ Transaksi Gagal: Nomor tujuan tidak boleh kosong.";
    } else {
        
        $nomorTujuanList = [];
        if ($isMulti) {
            // Pecah berdasarkan baris baru, hapus spasi ekstra, filter nomor kosong
            $nomorTujuanList = array_filter(array_map('trim', explode("\n", $tujuanInput)));
        } else {
            // Hanya satu nomor
            $nomorTujuanList = [trim($tujuanInput)];
        }

        if (empty($nomorTujuanList)) {
            $pesanTransaksi = "❌ Transaksi Gagal: Tidak ada nomor tujuan yang valid ditemukan.";
        } else {
            $hasilMultiTransaksi = [];
            $totalNomor = count($nomorTujuanList);
            $nomorKe = 1;

            foreach ($nomorTujuanList as $tujuan) {
                $reffId = "trx-" . uniqid() . "-" . $nomorKe; // Tambahkan nomor urut ke reffId
                $trxUrl = "$baseUrl/trx?produk=$kodeProduk&tujuan=$tujuan&reff_id=$reffId&api_key=$apiKey";
                $hasilTrx = panggilApi($trxUrl);

                if (isset($hasilTrx['error'])) {
                     $hasilMultiTransaksi[] = "Nomor $tujuan: ❌ Gagal menghubungi API ($reffId)";
                } elseif (isset($hasilTrx['status']) && $hasilTrx['status'] == 'success') {
                     $hasilMultiTransaksi[] = "Nomor $tujuan: ✅ Berhasil dikirim ($reffId)";
                } else {
                     $pesanError = $hasilTrx['message'] ?? $hasilTrx['msg'] ?? 'Error API tidak diketahui.';
                     $hasilMultiTransaksi[] = "Nomor $tujuan: ❌ Gagal - $pesanError ($reffId)";
                }
                
                // Beri jeda 1 detik antar request jika multi transaksi
                if ($isMulti && $nomorKe < $totalNomor) {
                    sleep(1); 
                }
                $nomorKe++;
            }

            // Gabungkan hasil untuk ditampilkan
            $pesanTransaksi = "Hasil Transaksi:<br>" . implode("<br>", $hasilMultiTransaksi);
            // History tidak dipanggil untuk multi agar tidak terlalu lama
            $pesanHistory = ($isMulti) ? "Pengecekan status history tidak dilakukan untuk multi transaksi." : ""; 
            
            // Jika BUKAN multi, coba cek history seperti biasa
             if (!$isMulti && strpos($pesanTransaksi, '✅') !== false) {
                 // Ambil reffId dari pesan sukses tunggal
                 preg_match('/\(Ref ID: (trx-[a-f0-9-]+)\)/', $pesanTransaksi, $matches);
                 if (isset($matches[1])) {
                     $reffIdSingle = $matches[1];
                     sleep(2);
                     $historyUrl = "$baseUrl/history?api_key=$apiKey&refid=$reffIdSingle";
                     $hasilHistory = panggilApi($historyUrl);
                     if (isset($hasilHistory['error'])) { $pesanHistory = "Gagal Cek History: " . $hasilHistory['error']; }
                     elseif (isset($hasilHistory['data'][0])) { $h = $hasilHistory['data'][0]; $s = $h['status']??''; $c = $h['catatan']??''; $pesanHistory = "<strong>Status:</strong> $s <br> <strong>Catatan:</strong> $c"; }
                     else { $pesanHistory = "Belum ada history untuk Ref ID: $reffIdSingle."; }
                 }
             }

        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jual Kuota Internet</title>
    <style>
        /* (CSS tidak berubah) */
        body { font-family: Arial, sans-serif; margin: 20px; background-color: #f4f4f4; }
        .container { max-width: 500px; margin: auto; padding: 20px; background-color: #fff; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group select, .form-group input[type="tel"], .form-group textarea { /* Ubah input jadi type tel & textarea */
            width: 100%; padding: 10px; box-sizing: border-box; border: 1px solid #ccc; 
            border-radius: 4px; font-size: 1rem; 
        }
        .form-group textarea { min-height: 80px; resize: vertical; } /* Style textarea */
        .checkbox-group label { font-weight: normal; margin-left: 5px; } /* Style label checkbox */
        .btn { background-color: #007bff; color: white; padding: 12px 15px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; width: 100%; box-sizing: border-box; transition: background-color 0.2s; }
        .btn:hover { background-color: #0056b3; }
        .btn-info { background-color: #17a2b8; }
        .btn-info:hover { background-color: #117a8b; }
        .message { padding: 15px; border-radius: 4px; margin-top: 20px; word-break: break-word; line-height: 1.5; /* Perbaiki line height pesan multi */ }
        .message.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .message.info { background-color: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        .warning-box { background-color: #fff3cd; color: #856404; border: 1px solid #ffeeba; padding: 15px; border-radius: 4px; margin-bottom: 20px; text-align: center; font-weight: bold; }
        .description-box { display: none; margin-top: 15px; padding: 15px; background-color: #e9ecef; border-radius: 4px; border: 1px solid #ced4da; font-size: 0.9em; line-height: 1.5; white-space: pre-wrap; }
        .description-box .price { display: block; margin-top: 10px; padding-top: 10px; border-top: 1px solid #ced4da; font-size: 1.1em; font-weight: bold; color: #0056b3; }
        .form-cek-stok { margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #eee; }
        #produk-group { display: none; } 
        #tujuan_multi { display: none; } /* Sembunyikan textarea awal */
        option.unavailable { color: #999; }
    </style>

    <script>
        const groupedProducts = <?php echo json_encode($produkGrouped); ?>;
        const productDetails = <?php echo json_encode($deskripsiMap); ?>;

        function populateProducts() {
            // ... (Fungsi populateProducts tidak berubah) ...
             const kategoriSelect = document.getElementById("kategori_produk");
            const produkSelect = document.getElementById("kode_produk");
            const produkGroupDiv = document.getElementById("produk-group");
            const descriptionBox = document.getElementById("product-description");
            const selectedKategori = kategoriSelect.value;
            produkSelect.innerHTML = '<option value="">-- Pilih Produk Spesifik --</option>';
            descriptionBox.style.display = 'none'; descriptionBox.innerHTML = '';
            if (selectedKategori && groupedProducts[selectedKategori]) {
                const productsInCategory = groupedProducts[selectedKategori];
                productsInCategory.forEach(produk => {
                    const kode = produk.kode_produk; const nama = produk.nama_produk;
                    const isGangguan = produk.gangguan == 1; const isKosong = produk.kosong == 1;
                    let labelStatus = ""; let optionClass = "";
                    if (isGangguan) { labelStatus = " (Gangguan)"; optionClass = "unavailable"; }
                    else if (isKosong) { labelStatus = " (Stok Kosong)"; optionClass = "unavailable"; }
                    const option = document.createElement('option');
                    option.value = kode; option.textContent = nama + labelStatus;
                    if (optionClass) { option.classList.add(optionClass); }
                    produkSelect.appendChild(option);
                });
                produkGroupDiv.style.display = 'block'; 
            } else { produkGroupDiv.style.display = 'none'; }
        }

        function showDescription() {
            // ... (Fungsi showDescription tidak berubah) ...
             var selectBox = document.getElementById("kode_produk");
            var selectedValue = selectBox.value;
            var descriptionBox = document.getElementById("product-description");
            if (productDetails[selectedValue]) {
                const details = productDetails[selectedValue];
                descriptionBox.innerHTML = `${details.deskripsi}<span class="price">Harga: ${details.harga_formatted}</span>`; 
                descriptionBox.style.display = 'block';
            } else { descriptionBox.innerHTML = ''; descriptionBox.style.display = 'none'; }
        }
        
        // --- BARU: Fungsi Toggle Input Tujuan ---
        function toggleMultiTujuan() {
            const checkbox = document.getElementById("multi_transaksi_check");
            const inputSingle = document.getElementById("tujuan_single");
            const textareaMulti = document.getElementById("tujuan_multi");

            if (checkbox.checked) {
                inputSingle.style.display = 'none';
                textareaMulti.style.display = 'block';
                textareaMulti.name = 'tujuan'; // Textarea yang akan dikirim
                inputSingle.name = ''; // Kosongkan nama input single
                inputSingle.required = false; // Input single tidak wajib
                textareaMulti.required = true; // Textarea wajib
            } else {
                inputSingle.style.display = 'block';
                textareaMulti.style.display = 'none';
                inputSingle.name = 'tujuan'; // Input single yang akan dikirim
                textareaMulti.name = ''; // Kosongkan nama textarea
                inputSingle.required = true; // Input single wajib
                textareaMulti.required = false; // Textarea tidak wajib
            }
        }
    </script>

</head>
<body>

    <div class="container">
        <div class="warning-box">MODE TESTING! API KEY TERLIHAT PUBLIK.</div>

        <?php if ($pesanStok): ?> <div class="message info"><?php echo $pesanStok; ?></div> <?php endif; ?>

        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST" class="form-cek-stok">
            <input type="hidden" name="action" value="cek_stok">
            <button type="submit" class="btn btn-info">Cek Stock Akrab</button>
        </form>
        
        <h2>Pesan Kuota Internet</h2>

        <?php if ($pesanTransaksi): ?> <div class="message <?php echo (strpos($pesanTransaksi, 'Gagal') !== false || strpos($pesanTransaksi, '❌') !== false) ? 'error' : 'success'; ?>"><?php echo $pesanTransaksi; ?></div> <?php endif; ?>
        <?php if ($pesanHistory): ?> <div class="message info"><?php echo $pesanHistory; ?></div> <?php endif; ?>

        <form action="<?php echo htmlspecialchars($_SERVER["PHP_SELF"]); ?>" method="POST">
            <input type="hidden" name="action" value="beli_produk"> 
            
            <div class="form-group">
                <label for="kategori_produk">Pilih Jenis Produk:</label>
                <select id="kategori_produk" name="kategori_produk" required onchange="populateProducts()">
                    <option value="">-- Pilih Kategori --</option>
                    <?php foreach (array_keys($produkGrouped) as $kategori): ?>
                        <option value="<?php echo htmlspecialchars($kategori); ?>"><?php echo htmlspecialchars($kategori); ?></option>
                    <?php endforeach; ?>
                     <?php if (empty($produkGrouped)): ?><option value="" disabled>Tidak ada kategori</option><?php endif; ?>
                </select>
            </div>

            <div class="form-group" id="produk-group"> 
                <label for="kode_produk">Pilih Produk Spesifik:</label>
                <select id="kode_produk" name="kode_produk" required onchange="showDescription()">
                    <option value="">-- Pilih Produk Spesifik --</option>
                </select>
            </div>
            
            <div id="product-description" class="description-box"></div>
            
            <div class="form-group">
                <label for="tujuan_single">Nomor Tujuan:</label>
                <input type="tel" id="tujuan_single" name="tujuan" placeholder="Contoh: 08123456789" required>
                <textarea id="tujuan_multi" placeholder="Masukkan nomor tujuan, pisahkan dengan Enter (baris baru). Contoh:
08123456789
08771234567
08569876543"></textarea>
            </div>

            <div class="form-group checkbox-group">
                <input type="checkbox" id="multi_transaksi_check" name="multi_transaksi" value="yes" onchange="toggleMultiTujuan()">
                <label for="multi_transaksi_check">Multi Transaksi (Banyak Nomor)</label>
            </div>
            
            <button type="submit" class="btn">Beli Sekarang</button>
        </form>
    </div>

</body>
</html>
