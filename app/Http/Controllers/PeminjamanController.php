<?php

namespace App\Http\Controllers;

use App\Models\Barang;
use App\Models\Peminjaman;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class PeminjamanController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index(): View
    {
        $peminjamans = Peminjaman::query()
            ->with('barang')
            ->whereHas('barang', fn ($query) => $query->where('user_id', Auth::id()))
            ->latest()
            ->paginate(15);

        return view('peminjaman.index', compact('peminjamans'));

    }

    /**
     * Show the form for creating a new resource.
     */
    public function create(): View
    {
        $barangs = Barang::where('user_id', Auth::id())->orderBy('nama_barang')->get();

        return view('peminjaman.create', compact('barangs'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'barang_id' => [
                'required',
                'integer',
                Rule::exists('barangs', 'id')->where('user_id', Auth::id()),
            ],
            'nama_peminjam' => ['required', 'string', 'max:255'],
            'jumlah_pinjam' => ['required', 'integer', 'min:1'],
            'tanggal_pinjam' => ['required', 'date'],
            'tanggal_kembali' => ['nullable', 'date', 'after_or_equal:tanggal_pinjam'],
            'status' => ['required', Rule::in(['Dipinjam', 'Dikembalikan', 'Terlambat'])],
        ]);

        DB::transaction(function () use ($data): void {
            $barang = Barang::query()
                ->where('user_id', Auth::id())
                ->lockForUpdate()
                ->findOrFail($data['barang_id']);

            if ($this->statusMenahanStok($data['status'])) {
                $this->pastikanStokCukup($barang, $data['jumlah_pinjam']);
                $barang->decrement('stok', $data['jumlah_pinjam']);
            }

            Peminjaman::create($data);
        });

        return redirect()->route('peminjaman.index')->with('success', 'Data peminjaman berhasil ditambahkan.');
    }

    /**
     * Display the specified resource.
     */
    public function show(Peminjaman $peminjaman): View
    {
        $this->ensurePeminjamanMilikPengguna($peminjaman);
        $peminjaman->load('barang');

        return view('peminjaman.show', compact('peminjaman'));
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Peminjaman $peminjaman): View
    {
        $this->ensurePeminjamanMilikPengguna($peminjaman);
        $barangs = Barang::where('user_id', Auth::id())->orderBy('nama_barang')->get();

        return view('peminjaman.edit', compact('peminjaman', 'barangs'));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Peminjaman $peminjaman): RedirectResponse
    {
        $data = $request->validate(['barang_id' => ['required','integer',Rule::exists('barangs', 'id')->where('user_id', Auth::id()),],
            'nama_peminjam' => ['required', 'string', 'max:255'],
            'jumlah_pinjam' => ['required', 'integer', 'min:1'],
            'tanggal_pinjam' => ['required', 'date'],
            'tanggal_kembali' => ['nullable', 'date', 'after_or_equal:tanggal_pinjam'],
            'status' => ['required', Rule::in(['Dipinjam', 'Dikembalikan', 'Terlambat'])],
        ]);

        DB::transaction(function () use ($data, $peminjaman): void {
            $peminjaman = Peminjaman::query()
                ->lockForUpdate()
                ->findOrFail($peminjaman->getKey());
            $this->ensurePeminjamanMilikPengguna($peminjaman);

            $barangIds = collect([$peminjaman->barang_id, $data['barang_id']])
                ->unique()
                ->sort()
                ->values();
            $barangs = Barang::query()
                ->where('user_id', Auth::id())
                ->whereIn('id', $barangIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get()
                ->keyBy('id');

            $barangLama = $barangs->get($peminjaman->barang_id);
            $barangBaru = $barangs->get($data['barang_id']);

            abort_unless($barangLama && $barangBaru, 404);

            $stokLamaDipakai = $this->statusMenahanStok($peminjaman->status) ? $peminjaman->jumlah_pinjam : 0;
            $stokBaruDipakai = $this->statusMenahanStok($data['status']) ? $data['jumlah_pinjam'] : 0;

            if ($barangLama->is($barangBaru)) {
                $perubahanStok = $stokBaruDipakai - $stokLamaDipakai;

                if ($perubahanStok > 0) {
                    $this->pastikanStokCukup($barangBaru, $perubahanStok);
                }

                $barangBaru->stok -= $perubahanStok;
                $barangBaru->save();
            } else {
                $barangLama->stok += $stokLamaDipakai;

                if ($stokBaruDipakai > 0) {
                    $this->pastikanStokCukup($barangBaru, $stokBaruDipakai);
                    $barangBaru->stok -= $stokBaruDipakai;
                }

                $barangLama->save();
                $barangBaru->save();
            }

            $peminjaman->update($data);
        });

        return redirect()->route('peminjaman.index')->with('success', 'Data peminjaman berhasil diperbarui.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Peminjaman $peminjaman): RedirectResponse
    {
        DB::transaction(function () use ($peminjaman): void {
            $peminjaman = Peminjaman::query()
                ->lockForUpdate()
                ->findOrFail($peminjaman->getKey());
            $this->ensurePeminjamanMilikPengguna($peminjaman);

            $barang = Barang::query()
                ->where('user_id', Auth::id())
                ->lockForUpdate()
                ->findOrFail($peminjaman->barang_id);

            if ($this->statusMenahanStok($peminjaman->status)) {
                $barang->increment('stok', $peminjaman->jumlah_pinjam);
            }

            $peminjaman->delete();
        });

        return redirect()->route('peminjaman.index')->with('success', 'Data peminjaman berhasil dihapus.');
    }

    private function ensurePeminjamanMilikPengguna(Peminjaman $peminjaman): void
    {
        abort_unless(
            $peminjaman->barang()->where('user_id', Auth::id())->exists(),
            404
        );
    }

    private function statusMenahanStok(string $status): bool
    {
        return $status !== 'Dikembalikan';
    }

    private function pastikanStokCukup(Barang $barang, int $jumlahPinjam): void
    {
        if ($barang->stok < $jumlahPinjam) {
            throw ValidationException::withMessages([
                'jumlah_pinjam' => 'Jumlah pinjam melebihi stok tersedia. Stok tersisa: '.$barang->stok.'.',
            ]);
        }
    }
}
