<?php
// --- PENGATURAN ---
$apiKey = "6A225EC0-2922-4252-8204-C7C00A3DA0E5";
$baseUrl = "https://panel.khfy-store.com/api_v2";
$baseUrl_v3 = "https://panel.khfy-store.com/api_v3"; // URL BARU untuk Cek Stok

// Fungsi helper untuk memanggil API menggunakan cURL
function panggilApi($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

// Inisialisasi variabel pesan
$pesanStok = "";
$pesanTransaksi = "";
$pesanHistory = "";

// --- 0. PROSES CEK STOK AKRAB (FITUR BARU) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'cek_stok') {
    $stokUrl = "$baseUrl_v3/cek_stock_akrab?api_key=$apiKey";
    $hasilStok = panggilApi($stokUrl);
    
    // Coba format pesan yang bagus, jika tidak, tampilkan data mentah
    if (isset($hasilStok['message'])) {
        $pesanStok = $hasilStok['message'];
    } elseif (isset($hasilStok['data']['stock'])) {
        $pesanStok = "Stok Akrab Saat Ini: " . $hasilStok['data']['stock'];
    } elseif (!empty($hasilStok)) {
        // Tampilkan sebagai JSON jika formatnya tidak dikenal
        $pesanStok = "Info Stok: <pre>" . htmlspecialchars(json_encode($hasilStok, JSON_PRETTY_PRINT)) . "</pre>";
    } else {
        $pesanStok = "Gagal mengambil info stok atau stok tidak dikenal.";
    }
}


// --- 1. AMBIL LIST PRODUK ---
$listProdukUrl = "$baseUrl/list_product?api_key=$apiKey";
$dataProduk = panggilApi($listProdukUrl);

$produkList = [];
$deskripsiMap = []; 

if (isset($dataProduk['data']) && is_array($dataProduk['data'])) {
    $produkList = $dataProduk['data'];
    
    foreach ($produkList as $produk) {
        $kode = $produk['kode_produk'] ?? '';
        
        // Key 'deskripsi' sudah benar sesuai hasil debug Anda
        $desc = $produk['deskripsi'] ?? 'Tidak ada deskripsi.';
        
        $deskripsiMap[$kode] = $desc;
    }
    
} else {
    echo "Gagal mengambil daftar produk. Cek API Key Anda atau saldo API Anda.";
}

// --- 2. PROSES TRANSAKSI (JIKA ADA FORM SUBMIT) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['kode_produk'], $_POST['tujuan'])) {
    
    $kodeProduk = $_POST['kode_produk'];
    $tujuan = $_POST['tujuan'];
    $reffId = "trx-" . uniqid(); 
    
    $trxUrl = "$baseUrl/trx?produk=$kodeProduk&tujuan=$tujuan&reff_id=$reffId&api_key=$apiKey";
    $hasilTrx = panggilApi($trxUrl);
    
    if (isset($hasilTrx['status']) && $hasilTrx['status'] == 'success') {
        $pesanTransaksi = "✅ Transaksi Berhasil Dikirim! (Ref ID: $reffId)";
        
        // --- 3. PANGGIL API HISTORY (Cara Lama, nanti kita ganti Webhook) ---
        sleep(1); 
        $historyUrl = "$baseUrl/history?api_key=$apiKey&refid=$reffId";
        $hasilHistory = panggilApi($historyUrl);
        
        if (isset($hasilHistory['data'][0])) {
            $history = $hasilHistory['data'][0];
            $status = $history['status'] ?? 'Tidak diketahui';
            $catatan = $history['catatan'] ?? 'Tidak ada catatan';
            
            $pesanHistory = "<strong>Status Pesanan:</strong> $status <br> <strong>Catatan:</strong> $catatan";
        } else {
            $pesanHistory = "Gagal mengambil status history untuk Ref ID: $reffId.";
        }
        
    } else {
        $pesanError = $hasilTrx['message'] ?? $hasilTrx['msg'] ?? null;
        
        if ($pesanError) {
            $pesanTransaksi = "❌ Transaksi Gagal: $pesanError";
        } elseif ($kodeProduk == "") {
             $pesanTransaksi = "❌ Transaksi Gagal: Anda belum memilih produk.";
        } else {
            $pesanTransaksi = "❌ Transaksi Gagal: Terjadi error tidak diketahui dari API.";
        }
    }
}
?>

<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Jual Kuota Internet (Testing)</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background-color: #f4f4f4; }
        .container { max-width: 500px; margin: auto; padding: 20px; background-color: #fff; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); }
        .form-group { margin-bottom: 15px; }
        .form-group label { display: block; margin-bottom: 5px; font-weight: bold; }
        .form-group select, .form-group input { width: 100%; padding: 8px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; }
        .btn { background-color: #007bff; color: white; padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; width: 100%; box-sizing: border-box; }
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
            padding: 10px;
            background-color: #f0f0f0;
            border-radius: 4px;
            border: 1px solid #ddd;
            font-size: 0.9em;
            line-height: 1.4;
            white-space: pre-wrap; /* Agar format \n (baris baru) dari deskripsi terbaca */
        }
        .form-cek-stok {
            margin-bottom: 20px; 
            padding-bottom: 20px; 
            border-bottom: 1px solid #eee;
        }
    </style>

    <script>
        const productDescriptions = <?php echo json_encode($deskripsiMap); ?>;

        function showDescription() {
            var selectBox = document.getElementById("kode_produk");
            var selectedValue = selectBox.value;
            var descriptionBox = document.getElementById("product-description");
            
            if (productDescriptions[selectedValue]) {
                // Gunakan innerText agar aman dari HTML injection
                descriptionBox.innerText = productDescriptions[selectedValue];
                descriptionBox.style.display = 'block';
            } else {
                descriptionBox.innerText = '';
                descriptionBox.style.display = 'none';
            }
        }
    </script>

</head>
<body>

    <div class="container">
        <div class="warning-box">
            MODE TESTING! API KEY INI TERLIHAT PUBLIK.
        </div>

        <?php if ($pesanStok): ?>
            <div class="message info">
                <?php echo $pesanStok; // Pesan ini sudah diformat di PHP ?>
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
            <div class="form-group">
                <label for="kode_produk">Pilih Produk:</label>
                <select id="kode_produk" name="kode_produk" required onchange="showDescription()">
                    <option value="">-- Pilih Produk --</option>
                    
                    <?php if (empty($produkList)): ?>
                        <option value="" disabled>Tidak ada produk yang tersedia</option>
                    <?php else: ?>
                        <?php foreach ($produkList as $produk): ?>
                            <?php
                            $nama = htmlspecialchars($produk['nama_produk'] ?? 'Produk Tidak Dikenal');
                            $kode = htmlspecialchars($produk['kode_produk'] ?? '');
                            
                            // --- INI DIA PERBAIKANNYA ---
                            $harga = number_format($produk['harga_final'] ?? 0);
                            // ---
                            
                            // Logika untuk menampilkan produk
                            // (gangguan=1 atau kosong=1 artinya JANGAN TAMPILKAN)
                            $isGangguan = $produk['gangguan'] ?? 0;
                            $isKosong = $produk['kosong'] ?? 0;
                            $labelStatus = "";
                            
                            if ($isGangguan == 1) {
                                $labelStatus = " (Gangguan)";
                            } elseif ($isKosong == 1) {
                                $labelStatus = " (Stok Kosong)";
                            }
                            ?>
                            
                            <?php if ($isGangguan == 0 && $isKosong == 0): ?>
                                <option value="<?php echo $kode; ?>">
                                    <?php echo "$nama - (Rp $harga)"; ?>
                                </option>
                            <?php else: ?>
                                <option value="<?php echo $kode; ?>" disabled>
                                    <?php echo "$nama - (Rp $harga) - $labelStatus"; ?>
                                </option>
                            <?php endif; ?>
                            
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
