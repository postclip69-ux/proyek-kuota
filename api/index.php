
<?php
// --- PENGATURAN ---
$apiKey = "6A225EC0-2922-4252-8204-C7C00A3DA0E5";
$baseUrl = "https://panel.khfy-store.com/api_v2";
$baseUrl_v3 = "https://panel.khfy-store.com/api_v3";

// Fungsi helper untuk memanggil API menggunakan cURL
function panggilApi($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    // Tambahkan timeout untuk mencegah hang jika API lambat
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10); // Timeout koneksi 10 detik
    curl_setopt($ch, CURLOPT_TIMEOUT, 30); // Timeout total 30 detik
    $response = curl_exec($ch);
    $error = curl_error($ch); // Ambil pesan error cURL jika ada
    curl_close($ch);
    
    if ($error) {
        // Jika ada error cURL, kembalikan array error
        return ['error' => "cURL Error: " . $error];
    }
    
    return json_decode($response, true);
}

// Inisialisasi variabel pesan
$pesanStok = "";
$pesanTransaksi = "";
$pesanHistory = "";

// --- 0. PROSES CEK STOK AKRAB ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'cek_stok') {
    $stokUrl = "$baseUrl_v3/cek_stock_akrab?api_key=$apiKey";
    $hasilStok = panggilApi($stokUrl);
    
    if (isset($hasilStok['error'])) {
        $pesanStok = "Gagal menghubungi API Cek Stok: " . $hasilStok['error'];
    } elseif (isset($hasilStok['message'])) {
        $pesanStok = $hasilStok['message'];
    } elseif (isset($hasilStok['data']['stock'])) {
        $pesanStok = "Stok Akrab Saat Ini: " . $hasilStok['data']['stock'];
    } elseif (!empty($hasilStok)) {
        $pesanStok = "Info Stok: <pre>" . htmlspecialchars(json_encode($hasilStok, JSON_PRETTY_PRINT)) . "</pre>";
    } else {
        $pesanStok = "Gagal mengambil info stok atau format tidak dikenal.";
    }
}

// --- 1. AMBIL LIST PRODUK & KELOMPOKKAN ---
$listProdukUrl = "$baseUrl/list_product?api_key=$apiKey";
$dataProduk = panggilApi($listProdukUrl);

$produkList = [];
$produkGrouped = []; // Array baru untuk menyimpan produk per grup
$deskripsiMap = []; // Sekarang menyimpan deskripsi DAN harga

if (isset($dataProduk['error'])) {
     echo "Gagal menghubungi API List Produk: " . $dataProduk['error'];
} elseif (isset($dataProduk['data']) && is_array($dataProduk['data'])) {
    $produkList = $dataProduk['data'];
    
    // Logika Pengelompokan (Sesuaikan prefix ini sesuai produk Anda)
    foreach ($produkList as $produk) {
        $kode = $produk['kode_produk'] ?? '';
        $nama = $produk['nama_produk'] ?? 'Produk Tidak Dikenal';
        $desc = $produk['deskripsi'] ?? 'Tidak ada deskripsi.';
        $harga = $produk['harga_final'] ?? 0;
        $isGangguan = $produk['gangguan'] ?? 0;
        $isKosong = $produk['kosong'] ?? 0;

        // Tentukan Kategori Berdasarkan Awal Kode Produk
        $kategori = "Lainnya"; // Default
        if (strpos($kode, 'BPA') === 0) { // Awalan BPA untuk Akrab
            $kategori = "Kartu Akrab";
        } elseif (strpos($kode, 'XLA') === 0 || strpos($kode, 'AX') === 0) { // Awalan XL atau AX
            $kategori = "XL / Axis";
        } elseif (strpos($kode, 'ID') === 0) { // Awalan ID untuk Indosat
             $kategori = "Indosat";
        } elseif (strpos($kode, 'TS') === 0 || strpos($kode, 'TD') === 0) { // Awalan TS atau TD untuk Telkomsel
             $kategori = "Telkomsel";
        }
        // Tambahkan kondisi 'elseif' lain di sini jika ada kategori lain

        // Masukkan produk ke grupnya
        $produkGrouped[$kategori][] = $produk;
        
        // Simpan deskripsi DAN harga ke map
        $deskripsiMap[$kode] = [
            'deskripsi' => $desc,
            'harga' => $harga, // Simpan harga di sini
            'harga_formatted' => "Rp " . number_format($harga) // Simpan format harga juga
        ];
    }
    
    // Urutkan grup berdasarkan nama kategori (opsional)
    ksort($produkGrouped);

} else {
    echo "Gagal mengambil daftar produk atau format data tidak sesuai. Response API: <pre>" . htmlspecialchars(print_r($dataProduk, true)) . "</pre>";
}

// --- 2. PROSES TRANSAKSI ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'beli_produk' && isset($_POST['kode_produk'], $_POST['tujuan'])) {
    
    $kodeProduk = $_POST['kode_produk'];
    $tujuan = $_POST['tujuan'];
    $reffId = "trx-" . uniqid(); 
    
    $trxUrl = "$baseUrl/trx?produk=$kodeProduk&tujuan=$tujuan&reff_id=$reffId&api_key=$apiKey";
    $hasilTrx = panggilApi($trxUrl);
    
    if (isset($hasilTrx['error'])) {
        $pesanTransaksi = "❌ Gagal menghubungi API Transaksi: " . $hasilTrx['error'];
    } elseif (isset($hasilTrx['status']) && $hasilTrx['status'] == 'success') {
        $pesanTransaksi = "✅ Transaksi Berhasil Dikirim! (Ref ID: $reffId)";
        
        // --- 3. PANGGIL API HISTORY (Tetap cara lama untuk sekarang) ---
        sleep(2); // Beri jeda sedikit lebih lama
        $historyUrl = "$baseUrl/history?api_key=$apiKey&refid=$reffId";
        $hasilHistory = panggilApi($historyUrl);
        
        if (isset($hasilHistory['error'])) {
             $pesanHistory = "Gagal menghubungi API History: " . $hasilHistory['error'];
        } elseif (isset($hasilHistory['data'][0])) {
            $history = $hasilHistory['data'][0];
            $status = $history['status'] ?? 'Tidak diketahui';
            $catatan = $history['catatan'] ?? 'Tidak ada catatan';
            
            $pesanHistory = "<strong>Status Pesanan:</strong> $status <br> <strong>Catatan:</strong> $catatan";
        } else {
            $pesanHistory = "Belum ada history ditemukan untuk Ref ID: $reffId. Coba cek beberapa saat lagi.";
        }
        
    } else {
        $pesanError = $hasilTrx['message'] ?? $hasilTrx['msg'] ?? 'Error tidak diketahui dari API.';
        $pesanTransaksi = "❌ Transaksi Gagal: $pesanError";
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
            background-color: #e9ecef; /* Warna latar sedikit beda */
            border-radius: 4px;
            border: 1px solid #ced4da;
            font-size: 0.9em;
            line-height: 1.5;
            white-space: pre-wrap; /* Jaga format baris baru */
        }
        .description-box strong { /* Styling untuk harga */
            display: block;
            margin-bottom: 8px;
            font-size: 1.1em;
            color: #0056b3;
        }
        .form-cek-stok {
            margin-bottom: 20px; 
            padding-bottom: 20px; 
            border-bottom: 1px solid #eee;
        }
        /* Style untuk optgroup */
        optgroup {
            font-weight: bold;
            font-style: italic;
            color: #0056b3;
            background-color: #f0f0f0;
            padding: 5px 0;
        }
    </style>

    <script>
        // Data deskripsi dan harga sekarang ada di sini
        const productDetails = <?php echo json_encode($deskripsiMap); ?>;

        function showDescription() {
            var selectBox = document.getElementById("kode_produk");
            var selectedValue = selectBox.value;
            var descriptionBox = document.getElementById("product-description");
            
            if (productDetails[selectedValue]) {
                const details = productDetails[selectedValue];
                // Tampilkan harga DULU, baru deskripsi
                descriptionBox.innerHTML = `<strong>Harga: ${details.harga_formatted}</strong>${details.deskripsi}`; 
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

        <?php if ($pesanStok): ?>
            <div class="message info">
                <?php echo $pesanStok; ?>
            </div>
        <?php endif; ?>

        <form action="index.php" method="POST" class="form-cek-stok">
            <input type="hidden" name="action" value="cek_stok">
            <button type="submit" class="btn btn-info">Cek Stock Akrab</button>
        </form>
        

        <h2>Pesan Kuota Internet</h2>

        <?php if ($pesanTransaksi): ?>
            <div class="message <?php echo (strpos($pesanTransaksi, 'Gagal') !== false) ? 'error' : 'success'; ?>">
                <?php echo $pesanTransaksi; ?>
            </div>
        <?php endif; ?>

        <?php if ($pesanHistory): ?>
            <div class="message info">
                <?php echo $pesanHistory; ?>
            </div>
        <?php endif; ?>

        <form action="index.php" method="POST">
            <input type="hidden" name="action" value="beli_produk"> 
            
            <div class="form-group">
                <label for="kode_produk">Pilih Produk:</label>
                <select id="kode_produk" name="kode_produk" required onchange="showDescription()">
                    <option value="">-- Pilih Produk --</option>
                    
                    <?php if (empty($produkGrouped)): ?>
                        <option value="" disabled>Tidak ada produk yang tersedia</option>
                    <?php else: ?>
                        <?php foreach ($produkGrouped as $kategori => $produksDalamGrup): ?>
                            <optgroup label="<?php echo htmlspecialchars($kategori); ?>">
                                <?php foreach ($produksDalamGrup as $produk): ?>
                                    <?php
                                    $nama = htmlspecialchars($produk['nama_produk'] ?? 'Produk Tidak Dikenal');
                                    $kode = htmlspecialchars($produk['kode_produk'] ?? '');
                                    
                                    // Status produk
                                    $isGangguan = $produk['gangguan'] ?? 0;
                                    $isKosong = $produk['kosong'] ?? 0;
                                    $labelStatus = "";
                                    $isDisabled = false;
                                    
                                    if ($isGangguan == 1) {
                                        $labelStatus = " (Gangguan)";
                                        $isDisabled = true;
                                    } elseif ($isKosong == 1) {
                                        $labelStatus = " (Stok Kosong)";
                                        $isDisabled = true;
                                    }
                                    ?>
                                    
                                    <option value="<?php echo $kode; ?>" <?php echo $isDisabled ? 'disabled' : ''; ?>>
                                        <?php echo $nama . $labelStatus; // Harga sudah dihapus dari sini ?>
                                    </option>
                                    
                                <?php endforeach; ?>
                            </optgroup>
                        <?php endforeach; ?>
                    <?php endif; ?>
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
