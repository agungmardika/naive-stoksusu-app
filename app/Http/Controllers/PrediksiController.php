<?php

namespace App\Http\Controllers;

use App\Models\DataStok;
use App\Models\DataPrediksi;
use App\Models\DataLikelihood;
use App\Models\DataProbabilitas;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PrediksiController extends Controller
{
    public function __construct()
    {
        $this->middleware('auth');
    }

    public function index()
    {
        $prediksi = DataPrediksi::with('dataStok')->orderBy('id_prediksi', 'desc')->get();
        return view('admin.prediksi.index', compact('prediksi'));
    }

    public function create()
    {
        return view('admin.prediksi.create');
    }

    public function predict(Request $request)
    {
        $request->validate([
            'merk' => 'nullable|string|max:100',
            'stok' => 'required|numeric',
            'penjualan' => 'required|numeric',
        ]);

        try {

            // Cek apakah sudah training
            if (DataLikelihood::count() == 0 || DataProbabilitas::count() == 0) {
                return response()->json([
                    'success' => false,
                    'message' => 'Belum ada data training! Silakan lakukan training terlebih dahulu.'
                ], 400);
            }

            $stok = $this->convertToDecimal($request->stok);
            $penjualan = $this->convertToDecimal($request->penjualan);

            $kategori = ['Banyak', 'Sedikit', 'Sedang'];
            $probabilities = [];

            foreach ($kategori as $kat) {

                $likelihood = DataLikelihood::where('kategori', $kat)->first();
                $prior = DataProbabilitas::where('kategori', $kat)->first();

                if (!$likelihood || !$prior) {
                    continue;
                }

                $probStok = $this->gaussianProbability(
                    $stok,
                    $likelihood->stok_li,
                    $likelihood->stok_std
                );



                $probPenjualan = $this->gaussianProbability(
                    $penjualan,
                    $likelihood->penjualan_li,
                    $likelihood->penjualan_std
                );

                $posterior =
                    $prior->probability *
                    $probStok *
                    $probPenjualan;

                $probabilities[$kat] = $posterior;
            }

            if (empty($probabilities)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Data probabilitas tidak ditemukan.'
                ], 400);
            }

            arsort($probabilities);

            $hasilPrediksi = array_key_first($probabilities);

            DB::beginTransaction();

            $dataStok = DataStok::create([
                'merk' => $request->merk ?? ('Prediksi-' . date('YmdHis')),
                'stok' => $stok,
                'penjualan' => $penjualan,
                'kategori_stok' => $hasilPrediksi,
            ]);

            DataPrediksi::create([
                'id_stok' => $dataStok->id_stok,
                'prediksi' => $hasilPrediksi,
            ]);

            DB::commit();

            return response()->json([
                'success' => true,
                'message' => 'Prediksi berhasil!',
                'data' => [
                    'prediksi' => $hasilPrediksi,
                    'probabilities' => $probabilities,
                    'rekomendasi' => $this->getRecommendation($hasilPrediksi),
                ]
            ]);
        } catch (\Exception $e) {

            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            return response()->json([
                'success' => false,
                'message' => 'Gagal melakukan prediksi: ' . $e->getMessage()
            ], 500);
        }
    }


    private function calculateProbability($value, $mean, $stdDev = 10)
    {
        // Simplified probability calculation
        $diff = abs($value - $mean);
        return 1 / (1 + $diff / 100); // Simple probability based on difference
    }

    private function getRecommendation($kategori)
    {
        switch ($kategori) {

            case 'Sedikit':
                return 'Karena hasil prediksi stok adalah Sedikit, toko disarankan menambah jumlah stok agar ketersediaan produk tetap terjaga.';

            case 'Sedang':
                return 'Karena hasil prediksi stok adalah Sedang, toko disarankan mempertahankan jumlah stok pada kondisi saat ini.';

            case 'Banyak':
                return 'Karena hasil prediksi stok adalah Banyak, toko disarankan mengurangi jumlah pemesanan stok agar tidak terjadi penumpukan barang dan risiko kedaluwarsa.';
        }

        return '-';
    }

    public function destroy($id)
    {
        try {
            $prediksi = DataPrediksi::findOrFail($id);
            $prediksi->delete();

            return response()->json([
                'success' => true,
                'message' => 'Data prediksi berhasil dihapus!'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal menghapus data prediksi!'
            ], 500);
        }
    }

    private function gaussianProbability($x, $mean, $stdDev)
    {
        if ($stdDev <= 0) {
            $stdDev = 1;
        }

        $exponent = exp(
            -pow(($x - $mean), 2)
                /
                (2 * pow($stdDev, 2))
        );

        return (1 / (sqrt(2 * pi()) * $stdDev))
            * $exponent;
    }

    /**
     * Konversi format angka dengan koma menjadi titik untuk desimal
     * Mendukung angka negatif
     */
    private function convertToDecimal($value)
    {
        // Jika sudah berupa angka, return langsung
        if (is_numeric($value)) {
            return $value;
        }

        // Hapus spasi dan ubah koma menjadi titik
        $value = str_replace(' ', '', $value);
        $value = str_replace(',', '.', $value);

        return floatval($value);
    }
}
