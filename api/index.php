<?php
// --- PENGATURAN ---
// PERINGATAN: API Key ini TERLIHAT PUBLIK. Segera ganti setelah testing.
$apiKey = "6A225EC0-2922-4252-8204-C7C00A3DA0E5";
$baseUrl = "https://panel.khfy-store.com/api_v2";

// Fungsi helper untuk memanggil API menggunakan cURL
function panggilApi($url) {
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1); // Ikuti redirect jika ada
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true); // Mengembalikan sebagai array asosiatif
}

// --- 1. AMBIL LIST PRODUK ---
$listProdukUrl = "$baseUrl/list_product?api_key=$apiKey";
$dataProduk = panggilApi($listProdukUrl);

// Kita asumsikan data produk ada di dalam $dataProduk['data']
// Sesuaikan 'data' jika struktur JSON-nya berbeda
$produkList = [];
if (isset($dataProduk['data']) && is_array($dataProduk['data'])) {
    $produkList = $dataProduk['data'];
} else {
    // Tampilkan error jika gagal mengambil produk
    echo "Gagal mengambil daftar produk. Cek API Key Anda.";
    // print_r($dataProduk); // Gunakan ini untuk debugging jika perlu
}

// --- 2. PROSES TRANSAKSI (JIKA ADA FORM SUBMIT) ---
$pesanTransaksi = "";
$pesanHistory = "";

if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['kode_produk'], $_POST['tujuan'])) {
    
    $kodeProduk = $_POST['kode_produk'];
    $tujuan = $_POST['tujuan'];
    
    // Buat Reff ID unik untuk transaksi ini
    // API Anda menggunakan format UUID, tapi kita bisa pakai uniqid() untuk contoh sederhana
    $reffId = "trx-" . uniqid(); 
    
    // --- PANGGIL API TRANSAKSI ---
    $trxUrl = "$baseUrl/trx?produk=$kodeProduk&tujuan=$tujuan&reff_id=$reffId&api_key=$apiKey";
    $hasilTrx = panggilApi($trxUrl);
    
    // Asumsikan respon sukses memiliki status 'success'
    // Sesuaikan 'status' dan 'success' jika struktur JSON-nya berbeda
    if (isset($hasilTrx['status']) && $hasilTrx['status'] == 'success') {
        $pesanTransaksi = "✅ Transaksi Berhasil Dikirim! (Ref ID: $reffId)";
        
        // --- 3. PANGGIL API HISTORY ---
        // Kita beri jeda 1 detik untuk memberi waktu server memproses TRX
        sleep(1); 
        
        $historyUrl = "$baseUrl/history?api_key=$apiKey&refid=$reffId";
        $hasilHistory = panggilApi($historyUrl);
        
        // Asumsikan data history ada di $hasilHistory['data'] dan kita ambil yang pertama [0]
        // Sesuaikan 'data', 'status', dan 'catatan' jika struktur JSON-nya berbeda
        if (isset($hasilHistory['data'][0])) {
            $history = $hasilHistory['data'][0];
            $status = $history['status'] ?? 'Tidak diketahui';
            $catatan = $history['catatan'] ?? 'Tidak ada catatan';
            
            $pesanHistory = "<strong>Status Pesanan:</strong> $status <br> <strong>Catatan:</strong> $catatan";
        } else {
            $pesanHistory = "Gagal mengambil status history untuk Ref ID: $reffId.";
            // print_r($hasilHistory); // Debugging
        }
        
    } else {
        // Jika transaksi gagal
        $pesanError = $hasilTrx['message'] ?? 'Terjadi error tidak diketahui';
        $pesanTransaksi = "❌ Transaksi Gagal: $pesanError";
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
        .btn { background-color: #007bff; color: white; padding: 10px 15px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; }
        .btn:hover { background-color: #0056b3; }
        .message { padding: 15px; border-radius: 4px; margin-top: 20px; }
        .message.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .message.info { background-color: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        .warning-box { background-color: #fff3cd; color: #856404; border: 1px solid #ffeeba; padding: 15px; border-radius: 4px; margin-bottom: 20px; text-align: center; font-weight: bold; }
    </style>
</head>
<body>

    <div class="container">
        <div class="warning-box">
            MODE TESTING! API KEY INI TERLIHAT PUBLIK.
        </div>

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
                <select id="kode_produk" name="kode_produk" required>
                    <option value="">-- Pilih Produk --</option>
                    <?php if (empty($produkList)): ?>
                        <option value="" disabled>Gagal memuat produk</option>
                    <?php else: ?>
                        <?php foreach ($produkList as $produk): ?>
                            <?php
                            // Asumsikan struktur data produk
                            // Sesuaikan 'nama_produk', 'kode_produk', dan 'harga'
                            $nama = htmlspecialchars($produk['nama_produk'] ?? 'Produk Tidak Dikenal');
                            $kode = htmlspecialchars($produk['kode_produk'] ?? '');
                            $harga = number_format($produk['harga'] ?? 0);
                            ?>
                            <option value="<?php echo $kode; ?>">
                                <?php echo "$nama - (Rp $harga)"; ?>
                            </option>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </select>
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
