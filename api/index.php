<?php
// --- PENGATURAN ---
$apiKey = "6A225EC0-2922-4252-8204-C7C00A3DA0E5";
$baseUrl = "https://panel.khfy-store.com/api_v2";
$baseUrl_v3 = "https://panel.khfy-store.com/api_v3";

// Fungsi helper API (tidak berubah)
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
    return json_decode($response, true);
}

// Inisialisasi variabel
$pesanStok = "";
$pesanTransaksi = "";
$pesanHistory = "";

// --- Cek Stok Akrab (tidak berubah) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'cek_stok') {
    $stokUrl = "$baseUrl_v3/cek_stock_akrab?api_key=$apiKey";
    $hasilStok = panggilApi($stokUrl);
    // (Logika pesan stok tidak berubah)
    if (isset($hasilStok['error'])) { $pesanStok = "Gagal: " . $hasilStok['error']; }
    elseif (isset($hasilStok['message'])) { $pesanStok = $hasilStok['message']; }
    elseif (isset($hasilStok['data']['stock'])) { $pesanStok = "Stok Akrab Saat Ini: " . $hasilStok['data']['stock']; }
    else { $pesanStok = "Info Stok: <pre>" . htmlspecialchars(json_encode($hasilStok, JSON_PRETTY_PRINT)) . "</pre>"; }
}

// --- Ambil List Produk & Kelompokkan (MODIFIKASI BESAR DI SINI) ---
$listProdukUrl = "$baseUrl/list_product?api_key=$apiKey";
$dataProduk = panggilApi($listProdukUrl);

$produkList = [];
$produkGrouped = []; // Struktur: ['Kategori Utama']['Sub Kategori'][] = $produk
$deskripsiMap = []; // Struktur: ['kode_produk'] = ['deskripsi', 'harga', 'harga_formatted']

// Nama sub-kategori Akrab Bulanan yang diinginkan
$akrabBulananSubs = ['Supermini', 'Mini', 'Big', 'Jumbo v2', 'Jumbo', 'Megabig'];
$flexmaxSubs = []; // Akan diisi otomatis

if (isset($dataProduk['error'])) {
     echo "Gagal menghubungi API List Produk: " . $dataProduk['error'];
} elseif (isset($dataProduk['data']) && is_array($dataProduk['data'])) {
    $produkList = $dataProduk['data'];
    
    // Kata kunci untuk filter Bonus Akrab
    $bonusAkrabKeywords = ['Bonus Akrab L', 'Bonus Akrab XL', 'Bonus Akrab XXL'];

    foreach ($produkList as $produk) {
        $nama = $produk['nama_produk'] ?? 'Produk Tidak Dikenal';
        $kode = $produk['kode_produk'] ?? '';
        $desc = $produk['deskripsi'] ?? 'Tidak ada deskripsi.';
        $harga = $produk['harga_final'] ?? 0;
        $isGangguan = $produk['gangguan'] ?? 0;
        $isKosong = $produk['kosong'] ?? 0;

        // --- FILTER PRODUK BONUS AKRAB ---
        $skipProduk = false;
        foreach ($bonusAkrabKeywords as $keyword) {
            if (stripos($nama, $keyword) === 0) { // Cek jika nama dimulai dengan keyword
                $skipProduk = true;
                break; // Keluar dari loop keyword jika sudah ketemu
            }
        }
        if ($skipProduk) {
            continue; // Lanjut ke produk berikutnya jika ini adalah Bonus Akrab
        }
        // --- AKHIR FILTER ---

        // Tentukan Kategori Utama & Sub Kategori
        $kategoriUtama = "Lainnya";
        $subKategori = $nama; // Default sub kategori adalah nama produk itu sendiri

        // Logika Pengelompokan Akrab Bulanan
        $foundAkrabSub = false;
        foreach ($akrabBulananSubs as $sub) {
            if (stripos($nama, $sub) !== false) { // Cek apakah nama mengandung sub kategori
                $kategoriUtama = "Akrab Bulanan";
                $subKategori = $sub; // Gunakan nama sub kategori yang ditemukan
                $foundAkrabSub = true;
                break;
            }
        }

        // Logika Pengelompokan Flexmax (jika bukan Akrab Bulanan)
        if (!$foundAkrabSub && stripos($kode, 'FLEXMAX') === 0) { // Cek awalan KODE
            $kategoriUtama = "Flexmax";
            // Untuk Flexmax, subkategori tetap nama produknya
        }
        
        // Anda bisa tambahkan logika 'elseif' lain di sini untuk kategori utama lainnya

        // Masukkan produk ke grupnya
        // Pastikan array diinisialisasi
        if (!isset($produkGrouped[$kategoriUtama])) {
            $produkGrouped[$kategoriUtama] = [];
        }
         // Kita tidak lagi pakai subkategori di array PHP, langsung list produk per kategori utama
        $produkGrouped[$kategoriUtama][] = $produk; 
        
        // Simpan deskripsi DAN harga ke map
        $deskripsiMap[$kode] = [
            'deskripsi' => $desc,
            'harga' => $harga,
            'harga_formatted' => "Rp " . number_format($harga)
        ];
    }
    
    // Urutkan kategori utama
    ksort($produkGrouped);
    // Urutkan produk dalam setiap kategori berdasarkan nama (opsional)
    foreach ($produkGrouped as $kategori => &$produksDalamGrup) {
        usort($produksDalamGrup, function($a, $b) {
            return strcmp($a['nama_produk'] ?? '', $b['nama_produk'] ?? '');
        });
    }
    unset($produksDalamGrup); // Hapus referensi

} else {
    echo "Gagal mengambil daftar produk atau format data tidak sesuai. Response API: <pre>" . htmlspecialchars(print_r($dataProduk, true)) . "</pre>";
}

// --- Proses Transaksi (tidak berubah) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'beli_produk' && isset($_POST['kode_produk'], $_POST['tujuan'])) {
    $kodeProduk = $_POST['kode_produk'];
    $tujuan = $_POST['tujuan'];
    if (empty($kodeProduk)) {
         $pesanTransaksi = "❌ Transaksi Gagal: Anda belum memilih produk spesifik.";
    } else {
        $reffId = "trx-" . uniqid(); 
        $trxUrl = "$baseUrl/trx?produk=$kodeProduk&tujuan=$tujuan&reff_id=$reffId&api_key=$apiKey";
        $hasilTrx = panggilApi($trxUrl);
        // (Logika pesan transaksi & history tidak berubah)
        if (isset($hasilTrx['error'])) { $pesanTransaksi = "❌ Gagal: " . $hasilTrx['error']; }
        elseif (isset($hasilTrx['status']) && $hasilTrx['status'] == 'success') {
            $pesanTransaksi = "✅ Berhasil! (Ref ID: $reffId)";
            sleep(2); $historyUrl = "$baseUrl/history?api_key=$apiKey&refid=$reffId"; $hasilHistory = panggilApi($historyUrl);
            if (isset($hasilHistory['error'])) { $pesanHistory = "Gagal Cek History: " . $hasilHistory['error']; }
            elseif (isset($hasilHistory['data'][0])) { $h = $hasilHistory['data'][0]; $s = $h['status']??''; $c = $h['catatan']??''; $pesanHistory = "<strong>Status:</strong> $s <br> <strong>Catatan:</strong> $c"; }
            else { $pesanHistory = "Belum ada history untuk Ref ID: $reffId."; }
        } else { $err = $hasilTrx['message'] ?? $hasilTrx['msg'] ?? 'Error API.'; $pesanTransaksi = "❌ Gagal: $err"; }
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
        /* (CSS tidak berubah signifikan, hanya penyesuaian kecil jika perlu) */
        body { font-family: Arial, sans-serif; margin: 20px; background-color: #f4f4f4; }
        .container { max-width: 500px; margin: auto; padding: 20px; background-color: #fff; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group select, .form-group input { width: 100%; padding: 10px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; font-size: 1rem; }
        .btn { background-color: #007bff; color: white; padding: 12px 15px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; width: 100%; box-sizing: border-box; transition: background-color 0.2s; }
        .btn:hover { background-color: #0056b3; }
        .btn-info { background-color: #17a2b8; }
        .btn-info:hover { background-color: #117a8b; }
        .message { padding: 15px; border-radius: 4px; margin-top: 20px; word-break: break-word; }
        .message.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .message.info { background-color: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        .warning-box { background-color: #fff3cd; color: #856404; border: 1px solid #ffeeba; padding: 15px; border-radius: 4px; margin-bottom: 20px; text-align: center; font-weight: bold; }
        .description-box {
            display: none;
            margin-top: 15px;
            padding: 15px;
            background-color: #e9ecef;
            border-radius: 4px;
            border: 1px solid #ced4da;
            font-size: 0.9em;
            line-height: 1.5;
            white-space: pre-wrap;
        }
        .description-box .price { /* Styling harga di bawah */
            display: block;
            margin-top: 10px; /* Jarak dari deskripsi */
            padding-top: 10px; /* Garis pemisah */
            border-top: 1px solid #ced4da; 
            font-size: 1.1em;
            font-weight: bold;
            color: #0056b3;
        }
        .form-cek-stok { margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #eee; }
        /* Style untuk dropdown produk yang awalnya disembunyikan */
        #produk-group { display: none; } 
    </style>

    <script>
        // Data produk lengkap yang dikelompokkan
        const groupedProducts = <?php echo json_encode($produkGrouped); ?>;
        // Data deskripsi dan harga
        const productDetails = <?php echo json_encode($deskripsiMap); ?>;

        function populateProducts() {
            const kategoriSelect = document.getElementById("kategori_produk");
            const produkSelect = document.getElementById("kode_produk");
            const produkGroupDiv = document.getElementById("produk-group");
            const descriptionBox = document.getElementById("product-description");
            const selectedKategori = kategoriSelect.value;

            // Kosongkan dropdown produk dan deskripsi
            produkSelect.innerHTML = '<option value="">-- Pilih Produk Spesifik --</option>';
            descriptionBox.style.display = 'none';
            descriptionBox.innerHTML = '';

            if (selectedKategori && groupedProducts[selectedKategori]) {
                const productsInCategory = groupedProducts[selectedKategori];
                
                productsInCategory.forEach(produk => {
                    const kode = produk.kode_produk;
                    const nama = produk.nama_produk;
                    const isGangguan = produk.gangguan == 1;
                    const isKosong = produk.kosong == 1;
                    let labelStatus = "";
                    let isDisabled = false;

                    if (isGangguan) {
                        labelStatus = " (Gangguan)";
                        isDisabled = true;
                    } else if (isKosong) {
                        labelStatus = " (Stok Kosong)";
                        isDisabled = true;
                    }

                    const option = document.createElement('option');
                    option.value = kode;
                    option.textContent = nama + labelStatus;
                    if (isDisabled) {
                        option.disabled = true;
                    }
                    produkSelect.appendChild(option);
                });

                produkGroupDiv.style.display = 'block'; // Tampilkan dropdown produk
            } else {
                produkGroupDiv.style.display = 'none'; // Sembunyikan jika tidak ada kategori dipilih
            }
        }

        function showDescription() {
            var selectBox = document.getElementById("kode_produk");
            var selectedValue = selectBox.value;
            var descriptionBox = document.getElementById("product-description");
            
            if (productDetails[selectedValue]) {
                const details = productDetails[selectedValue];
                // PERUBAHAN POSISI HARGA: Deskripsi dulu, baru harga
                descriptionBox.innerHTML = `${details.deskripsi}<span class="price">Harga: ${details.harga_formatted}</span>`; 
                descriptionBox.style.display = 'block';
            } else {
                descriptionBox.innerHTML = '';
                descriptionBox.style.display = 'none';
            }
        }
    </script>

</head>
<body>

    <div class="container">
        <div class="warning-box">
            MODE TESTING! API KEY TERLIHAT PUBLIK.
        </div>

        <?php if ($pesanStok): ?> <div class="message info"><?php echo $pesanStok; ?></div> <?php endif; ?>

        <form action="index.php" method="POST" class="form-cek-stok">
            <input type="hidden" name="action" value="cek_stok">
            <button type="submit" class="btn btn-info">Cek Stock Akrab</button>
        </form>
        

        <h2>Pesan Kuota Internet</h2>

        <?php if ($pesanTransaksi): ?> <div class="message <?php echo (strpos($pesanTransaksi, 'Gagal') !== false) ? 'error' : 'success'; ?>"><?php echo $pesanTransaksi; ?></div> <?php endif; ?>
        <?php if ($pesanHistory): ?> <div class="message info"><?php echo $pesanHistory; ?></div> <?php endif; ?>

        <form action="index.php" method="POST">
            <input type="hidden" name="action" value="beli_produk"> 
            
            <div class="form-group">
                <label for="kategori_produk">Pilih Jenis Produk:</label>
                <select id="kategori_produk" name="kategori_produk" required onchange="populateProducts()">
                    <option value="">-- Pilih Kategori --</option>
                    <?php foreach (array_keys($produkGrouped) as $kategori): ?>
                        <option value="<?php echo htmlspecialchars($kategori); ?>">
                            <?php echo htmlspecialchars($kategori); ?>
                        </option>
                    <?php endforeach; ?>
                     <?php if (empty($produkGrouped)): ?>
                         <option value="" disabled>Tidak ada kategori tersedia</option>
                    <?php endif; ?>
                </select>
            </div>

            <div class="form-group" id="produk-group"> 
                <label for="kode_produk">Pilih Produk Spesifik:</label>
                <select id="kode_produk" name="kode_produk" required onchange="showDescription()">
                    <option value="">-- Pilih Produk Spesifik --</option>
                    </select>
            </div>
            
            <div id="product-description" class="description-box">
                </div>
            
            <div class="form-group">
                <label for="tujuan">Nomor Tujuan:</label>
                <input type="tel" id="tujuan" name="tujuan" placeholder="Contoh: 08123456789" required>
            </div>
            
            <button type="submit" class="btn">Beli Sekarang</button>
        </form>
    </div>

</body>
</html>
