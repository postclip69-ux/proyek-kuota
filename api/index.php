<?php
// --- PENGATURAN & Fungsi panggilApi (tidak berubah) ---
$apiKey = "947481D5-5AF8-49AC-8F04-09749DF07B4F";
$baseUrl = "https://panel.khfy-store.com/api_v2";
$baseUrl_v3 = "https://panel.khfy-store.com/api_v3";

function panggilApi($url) {
    $ch = curl_init(); curl_setopt_array($ch, [ CURLOPT_URL => $url, CURLOPT_RETURNTRANSFER => 1, CURLOPT_FOLLOWLOCATION => 1, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 30 ]);
    $response = curl_exec($ch); $error = curl_error($ch); curl_close($ch);
    if ($error) return ['error' => "cURL Error: " . $error];
    $decoded = json_decode($response, true);
    if (json_last_error() !== JSON_ERROR_NONE && !empty($response)) { return ['error' => 'Gagal decode JSON: ' . json_last_error_msg(), 'raw_response' => $response]; }
    return $decoded;
}

// Inisialisasi variabel
$pesanStok = ""; $pesanTransaksi = ""; $pesanHistory = "";

// --- Cek Stok Akrab (tidak berubah) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'cek_stok') {
    $stokUrl = "$baseUrl_v3/cek_stock_akrab?api_key=$apiKey"; $hasilStok = panggilApi($stokUrl);
    if (isset($hasilStok['error'])) { $pesanStok = "Gagal: " . $hasilStok['error']; }
    elseif (isset($hasilStok['message'])) { $pesanStok = $hasilStok['message']; }
    elseif (isset($hasilStok['data']['stock'])) { $pesanStok = "Stok Akrab Saat Ini: " . $hasilStok['data']['stock']; }
    else { $pesanStok = "Info Stok: <pre>" . htmlspecialchars(json_encode($hasilStok, JSON_PRETTY_PRINT)) . "</pre>"; }
}

// --- Ambil List Produk & Kelompokkan (PERBAIKAN LOGIKA KATEGORI) ---
$listProdukUrl = "$baseUrl/list_product?api_key=$apiKey"; $dataProduk = panggilApi($listProdukUrl);
$produkGrouped = []; $deskripsiMap = []; $produkRawMap = [];
$akrabBulananSubs = ['Supermini', 'Mini', 'Big', 'Jumbo v2', 'Jumbo', 'Megabig'];
$bonusAkrabKeywords = ['Bonus Akrab L', 'Bonus Akrab XL', 'Bonus Akrab XXL'];
$akrabV2Keyword = "Reguler + Lokal"; // Keyword untuk Akrab V2

if (isset($dataProduk['error'])) { echo "Gagal menghubungi API List Produk: " . $dataProduk['error']; }
elseif (isset($dataProduk['data']) && is_array($dataProduk['data'])) {
    foreach ($dataProduk['data'] as $produk) {
        $nama = $produk['nama_produk'] ?? 'Produk'; $kode = $produk['kode_produk'] ?? '';
        $desc = $produk['deskripsi'] ?? ''; $harga = $produk['harga_final'] ?? 0;
        $isGangguan = $produk['gangguan'] ?? 0; $isKosong = $produk['kosong'] ?? 0;
        $skipProduk = false;
        foreach ($bonusAkrabKeywords as $keyword) { if (stripos($nama, $keyword) === 0) { $skipProduk = true; break; } }
        if ($skipProduk || empty($kode)) continue;
        
        // --- PERBAIKAN LOGIKA KATEGORI ---
        $kategoriUtama = null; $foundAkrabSub = false;
        // 1. Cek Akrab Bulanan V1
        foreach ($akrabBulananSubs as $sub) { if (stripos($nama, $sub) !== false) { $kategoriUtama = "Akrab Bulanan"; $foundAkrabSub = true; break; } }
        // 2. Cek Akrab Bulanan V2 (MENGANDUNG keyword)
        if (!$foundAkrabSub && stripos($nama, $akrabV2Keyword) !== false) { $kategoriUtama = "Akrab Bulanan V2"; }
        // 3. Cek FlexMax (DIMULAI DENGAN keyword, jika belum masuk kategori lain)
        elseif ($kategoriUtama === null && stripos($nama, 'FlexMax') === 0) { $kategoriUtama = "FlexMax"; }
        // --- AKHIR PERBAIKAN LOGIKA ---

        if ($kategoriUtama === null) continue; // Abaikan jika tidak masuk kategori
        if (!isset($produkGrouped[$kategoriUtama])) { $produkGrouped[$kategoriUtama] = []; }
        $produkGrouped[$kategoriUtama][] = $produk;
        $deskripsiMap[$kode] = ['deskripsi' => $desc, 'harga' => $harga, 'harga_formatted' => "Rp " . number_format($harga), 'gangguan' => $isGangguan, 'kosong' => $isKosong ];
        $produkRawMap[$kode] = $produk;
    }
    ksort($produkGrouped);
    foreach ($produkGrouped as &$produksDalamGrup) { usort($produksDalamGrup, function($a, $b) { return strcmp($a['nama_produk'] ?? '', $b['nama_produk'] ?? ''); }); } unset($produksDalamGrup);
} else { echo "Gagal mengambil daftar produk. Response: <pre>" . htmlspecialchars(print_r($dataProduk, true)) . "</pre>"; }

// --- Proses Transaksi (tidak berubah) ---
if ($_SERVER["REQUEST_METHOD"] == "POST" && isset($_POST['action']) && $_POST['action'] == 'beli_produk' && isset($_POST['kode_produk'], $_POST['tujuan'])) {
    $kodeProduk = trim($_POST['kode_produk']); $tujuanInput = trim($_POST['tujuan']);
    $isMulti = isset($_POST['multi_transaksi']) && $_POST['multi_transaksi'] == 'yes';
    $isPreorder = isset($_POST['preorder']) && $_POST['preorder'] == 'yes';
    if (empty($kodeProduk)) { $pesanTransaksi = "❌ Gagal: Pilih produk spesifik."; }
    elseif (empty($tujuanInput)) { $pesanTransaksi = "❌ Gagal: Nomor tujuan kosong."; }
    elseif (!isset($produkRawMap[$kodeProduk])) { $pesanTransaksi = "❌ Gagal: Kode produk tidak valid."; }
    else {
        $selectedProduk = $produkRawMap[$kodeProduk]; $isProdukAvailable = ($selectedProduk['gangguan'] == 0 && $selectedProduk['kosong'] == 0);
        if ($isPreorder && !$isProdukAvailable) { $namaProduk = htmlspecialchars($selectedProduk['nama_produk']); $pesanTransaksi = "⏱️ PreOrder '$namaProduk' diterima (Simulasi)."; }
        elseif (!$isPreorder && !$isProdukAvailable) { $namaProduk = htmlspecialchars($selectedProduk['nama_produk']); $statusProblem = ($selectedProduk['gangguan'] == 1)?"gangguan":"kosong"; $pesanTransaksi = "❌ Gagal: '$namaProduk' saat ini $statusProblem."; }
        else {
            $nomorTujuanList = []; if ($isMulti) { $nomorTujuanList = array_filter(array_map('trim', explode("\n", $tujuanInput))); } else { $nomorTujuanList = [trim($tujuanInput)]; }
            if (empty($nomorTujuanList)) { $pesanTransaksi = "❌ Gagal: Tidak ada nomor tujuan valid."; }
            else {
                $hasilMultiTransaksi = []; $totalNomor = count($nomorTujuanList); $nomorKe = 1;
                foreach ($nomorTujuanList as $tujuan) {
                    $reffId = "trx-" . uniqid() . "-" . $nomorKe; $trxUrl = "$baseUrl/trx?produk=$kodeProduk&tujuan=$tujuan&reff_id=$reffId&api_key=$apiKey"; $hasilTrx = panggilApi($trxUrl);
                    if (isset($hasilTrx['error'])) { $hasilMultiTransaksi[] = "No $tujuan: ❌ API Error ($reffId) - ".$hasilTrx['error']; }
                    elseif (isset($hasilTrx['status']) && $hasilTrx['status'] == 'success') { $hasilMultiTransaksi[] = "No $tujuan: ✅ OK ($reffId)"; }
                    else { $err = $hasilTrx['message']??$hasilTrx['msg']??'Error API'; $hasilMultiTransaksi[] = "No $tujuan: ❌ Gagal - $err ($reffId)"; }
                    if ($isMulti && $nomorKe < $totalNomor) { sleep(1); } $nomorKe++;
                }
                $pesanTransaksi = "Hasil:<br>" . implode("<br>", $hasilMultiTransaksi); $pesanHistory = ($isMulti)?"History tdk dicek.":"";
                 if (!$isMulti && strpos($pesanTransaksi, '✅') !== false) {
                     preg_match('/\(Ref ID: (trx-[a-f0-9-]+)\)/', $pesanTransaksi, $matches);
                     if (isset($matches[1])) {
                         $reffIdSingle = $matches[1]; sleep(2); $historyUrl = "$baseUrl/history?api_key=$apiKey&refid=$reffIdSingle"; $hasilHistory = panggilApi($historyUrl);
                         if (isset($hasilHistory['error'])) { $pesanHistory = "Hist Gagal: ".$hasilHistory['error']; }
                         elseif (isset($hasilHistory['data'][0])) { $h=$hasilHistory['data'][0]; $s=$h['status']??''; $c=$h['catatan']??''; $pesanHistory = "<strong>Status:</strong> $s <br> <strong>Catatan:</strong> $c"; }
                         else { $pesanHistory = "Hist blm ada: $reffIdSingle."; }
                     }
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
        .form-group select, .form-group input[type="tel"], .form-group textarea { width: 100%; padding: 10px; box-sizing: border-box; border: 1px solid #ccc; border-radius: 4px; font-size: 1rem; }
        .form-group textarea { min-height: 80px; resize: vertical; }
        .checkbox-group label { font-weight: normal; margin-left: 5px; cursor: pointer; }
        .btn { background-color: #007bff; color: white; padding: 12px 15px; border: none; border-radius: 4px; cursor: pointer; font-size: 16px; width: 100%; box-sizing: border-box; transition: background-color 0.2s; }
        .btn:hover { background-color: #0056b3; }
        .btn-info { background-color: #17a2b8; }
        .btn-info:hover { background-color: #117a8b; }
        .message { padding: 15px; border-radius: 4px; margin-top: 20px; word-break: break-word; line-height: 1.5; }
        .message.success { background-color: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
        .message.error { background-color: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
        .message.info { background-color: #d1ecf1; color: #0c5460; border: 1px solid #bee5eb; }
        .message.preorder { background-color: #fff3cd; color: #856404; border: 1px solid #ffeeba; }
        .warning-box { background-color: #fff3cd; color: #856404; border: 1px solid #ffeeba; padding: 15px; border-radius: 4px; margin-bottom: 20px; text-align: center; font-weight: bold; }
        .description-box { display: none; margin-top: 15px; padding: 15px; background-color: #e9ecef; border-radius: 4px; border: 1px solid #ced4da; font-size: 0.9em; line-height: 1.5; white-space: pre-wrap; }
        .description-box .price { display: block; margin-top: 10px; padding-top: 10px; border-top: 1px solid #ced4da; font-size: 1.1em; font-weight: bold; color: #0056b3; }
        .form-cek-stok { margin-bottom: 20px; padding-bottom: 20px; border-bottom: 1px solid #eee; }
        #produk-group { display: none; } 
        #tujuan_multi { display: none; }
        option.unavailable { color: #999; }
        #preorder-group { display: none; margin-top: 10px; }
        #preorder_check:disabled + label { color: #aaa; cursor: not-allowed; }
        #total-price-info { display: none; text-align: right; margin-top: 10px; font-weight: bold; color: var(--primary-color); }
    </style>

    <script>
        // (JavaScript tidak berubah)
        const groupedProducts = <?php echo json_encode($produkGrouped); ?>;
        const productDetails = <?php echo json_encode($deskripsiMap); ?>;
        function formatRupiahJS(angka) { if (typeof angka !== 'number' || isNaN(angka)) { angka = 0; } return new Intl.NumberFormat('id-ID', { style: 'currency', currency: 'IDR', minimumFractionDigits: 0 }).format(angka); }
        function populateProducts() { const k=document.getElementById("kategori_produk"),p=document.getElementById("kode_produk"),g=document.getElementById("produk-group"),d=document.getElementById("product-description"),r=document.getElementById("preorder-group"),s=k.value;p.innerHTML='<option value="">-- Pilih Produk Spesifik --</option>',d.style.display='none',d.innerHTML='',r.style.display='none';if(s&&groupedProducts[s]){const t=groupedProducts[s];t.forEach(o=>{const e=o.kode_produk,n=o.nama_produk,l=1==o.gangguan,i=1==o.kosong;let c="",u="";l?(c=" (Gangguan)",u="unavailable"):i&&(c=" (Stok Kosong)",u="unavailable");const a=document.createElement("option");a.value=e,a.textContent=n+c,u&&a.classList.add(u),p.appendChild(a)}),g.style.display='block'}else g.style.display='none';calculateTotalPrice() }
        function showDescription() { const p=document.getElementById("kode_produk"),s=p.value,d=document.getElementById("product-description"),r=document.getElementById("preorder-group"),c=document.getElementById("preorder_check");d.innerHTML='',d.style.display='none',r.style.display='none',c.disabled=!0,c.checked=!1;if(productDetails[s]){const o=productDetails[s],e=0==o.gangguan&&0==o.kosong;d.innerHTML=`${o.deskripsi}<span class="price">Harga: ${o.harga_formatted}</span>`,d.style.display='block',r.style.display='block',e?c.disabled=!0:c.disabled=!1}calculateTotalPrice() }
        function calculateTotalPrice() { const p=document.getElementById("kode_produk"),s=p.value,t=document.getElementById("total-price-info"),m=document.getElementById("multi_transaksi_check"),u=m.checked?document.getElementById("tujuan_multi"):document.getElementById("tujuan_single");t.style.display='none';if(s&&productDetails[s]){const o=productDetails[s].harga;let e=0;m.checked?(e=u.value.split('\n').filter(l=>""!==l.trim()).length):e=""!==u.value.trim()?1:0;if(e>0){const l=o*e;t.textContent=`Estimasi Total: ${formatRupiahJS(l)} (${e} nomor)`,t.style.display='block'}} }
        function toggleMultiTujuan() { const c=document.getElementById("multi_transaksi_check"),i=document.getElementById("tujuan_single"),t=document.getElementById("tujuan_multi");c.checked?(i.style.display='none',t.style.display='block',t.name='tujuan',i.name='',i.required=!1,t.required=!0):(i.style.display='block',t.style.display='none',i.name='tujuan',t.name='',i.required=!0,t.required=!1);calculateTotalPrice() }
        document.addEventListener('DOMContentLoaded', ()=>{ const i=document.getElementById('tujuan_single'),t=document.getElementById('tujuan_multi');i&&i.addEventListener('input', calculateTotalPrice),t&&t.addEventListener('input', calculateTotalPrice) });
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

        <?php 
        $msgClass = 'info'; 
        if ($pesanTransaksi) {
            if (strpos($pesanTransaksi, '❌') !== false || strpos($pesanTransaksi, 'Gagal') !== false) $msgClass = 'error';
            elseif (strpos($pesanTransaksi, '✅') !== false) $msgClass = 'success';
            elseif (strpos($pesanTransaksi, '⏱️') !== false) $msgClass = 'preorder'; 
        }
        ?>
        <?php if ($pesanTransaksi): ?> <div class="message <?php echo $msgClass; ?>"><?php echo $pesanTransaksi; ?></div> <?php endif; ?>
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

             <div class="form-group checkbox-group" id="preorder-group">
                <input type="checkbox" id="preorder_check" name="preorder" value="yes" disabled>
                <label for="preorder_check">PreOrder jika Stok Kosong/Gangguan</label>
            </div>
            
            <div class="form-group">
                <label for="tujuan_single">Nomor Tujuan:</label>
                <input type="tel" id="tujuan_single" name="tujuan" placeholder="Contoh: 08123456789" required>
                <textarea id="tujuan_multi" placeholder="Masukkan nomor tujuan, pisahkan dengan Enter (baris baru)..."></textarea>
            </div>

            <div class="form-group checkbox-group">
                <input type="checkbox" id="multi_transaksi_check" name="multi_transaksi" value="yes" onchange="toggleMultiTujuan()">
                <label for="multi_transaksi_check">Multi Transaksi (Banyak Nomor)</label>
            </div>

            <div id="total-price-info">Estimasi Total: Rp 0</div>
            
            <button type="submit" class="btn">Beli Sekarang</button>
        </form>
    </div>

</body>
</html>
